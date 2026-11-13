<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Scheduling\Enums\SlotStatus;
use App\Support\Http\ApiDate;
use App\Support\Money;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Finds approved recruiters for the search screen and the public profile.
 *
 * This is the one kind of code allowed to read other modules' tables (docs/PLAN.md §2):
 * a read-only listing query. A search has to filter, sort and page over profile data
 * (Profiles), names (Identity) and prices and slots (Scheduling) in ONE SQL query, or paging
 * and sorting by price would be wrong. It uses the query builder, never the other modules'
 * models, and it never writes.
 */
final class RecruiterSearch
{
    /**
     * @param  array<string, mixed>  $filters  validated input of SearchRecruitersRequest
     * @return array{items: list<array<string, mixed>>, page: int, pageSize: int, totalCount: int}
     */
    public function search(array $filters): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $pageSize = (int) ($filters['pageSize'] ?? 20);

        $query = $this->approvedRecruiters($filters)
            // A recruiter with nothing to sell cannot be booked, so they are not listed.
            ->whereExists($this->matchingOfferings($filters));

        $this->applyFilters($query, $filters);

        // Count before sorting and paging: the total is of all matches, not of one page.
        $totalCount = $query->getCountForPagination();

        $this->applySort($query, $filters['sort'] ?? 'rating_desc');
        $rows = $query->forPage($page, $pageSize)->get();

        return [
            'items' => $this->toSummaries($rows),
            'page' => $page,
            'pageSize' => $pageSize,
            'totalCount' => $totalCount,
        ];
    }

    /**
     * One approved recruiter in the `RecruiterSummary` shape plus bio, languages and LinkedIn,
     * or null if there is no approved recruiter with this id.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $recruiterId): ?array
    {
        $row = $this->approvedRecruiters([])->where('rp.user_id', $recruiterId)->first();
        if ($row === null) {
            return null;
        }

        return [
            ...$this->toSummaries(collect([$row]))[0],
            'bio' => $row->bio,
            'languages' => json_decode($row->languages, true),
            'linkedinUrl' => $row->linkedin_url,
        ];
    }

    /**
     * The base query: one row per approved recruiter, with two computed columns.
     *
     * @param  array<string, mixed>  $filters
     */
    private function approvedRecruiters(array $filters): Builder
    {
        return DB::table('recruiter_profiles as rp')
            ->join('users as u', 'u.id', '=', 'rp.user_id')
            ->where('rp.verification_status', 'approved')
            ->select([
                'rp.user_id', 'u.full_name', 'rp.current_title', 'rp.years_of_experience',
                'rp.rating_avg', 'rp.rating_count', 'rp.bio', 'rp.languages', 'rp.linkedin_url',
            ])
            // "From X": the cheapest active offering that fits the type/mode/price filters,
            // so the card never shows a price for a service the candidate filtered out.
            ->selectSub($this->matchingOfferings($filters)->selectRaw('min(price_minor)'), 'starting_price_minor')
            ->selectSub(
                DB::table('availability_slots')
                    ->selectRaw('min(starts_at)')
                    ->whereColumn('recruiter_id', 'rp.user_id')
                    ->where('status', SlotStatus::Open->value)
                    ->where('starts_at', '>', now()),
                'next_available_at',
            );
    }

    /**
     * Active offerings of the current row's recruiter that fit the filters.
     *
     * @param  array<string, mixed>  $filters
     */
    private function matchingOfferings(array $filters): Builder
    {
        return DB::table('offerings')
            ->whereColumn('offerings.recruiter_id', 'rp.user_id')
            ->where('offerings.is_active', true)
            ->when($filters['sessionType'] ?? null, fn (Builder $q, string $type) => $q->where('offerings.session_type', $type))
            ->when($filters['mode'] ?? null, fn (Builder $q, string $mode) => $q->where('offerings.mode', $mode))
            ->when(isset($filters['maxPriceMinor']), fn (Builder $q) => $q->where('offerings.price_minor', '<=', (int) $filters['maxPriceMinor']));
    }

    /** @param  array<string, mixed>  $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if ($specializationId = $filters['specializationId'] ?? null) {
            // Choosing a parent ("Software Engineering") also finds recruiters who picked one
            // of its children ("Backend").
            $ids = DB::table('specializations')->where('id', $specializationId)->orWhere('parent_id', $specializationId)->pluck('id');

            $query->whereExists(fn (Builder $q) => $q->from('recruiter_specializations as rs')
                ->whereColumn('rs.recruiter_id', 'rp.user_id')
                ->whereIn('rs.specialization_id', $ids));
        }

        if (isset($filters['minRating'])) {
            $query->where('rp.rating_avg', '>=', (float) $filters['minRating']);
        }

        $from = isset($filters['availableFrom']) ? Carbon::parse($filters['availableFrom']) : null;
        $to = isset($filters['availableTo']) ? Carbon::parse($filters['availableTo']) : null;
        if ($from !== null || $to !== null) {
            // Has a free, future slot that touches the window.
            $query->whereExists(fn (Builder $q) => $q->from('availability_slots as s')
                ->whereColumn('s.recruiter_id', 'rp.user_id')
                ->where('s.status', SlotStatus::Open->value)
                ->where('s.starts_at', '>', now())
                ->when($from, fn (Builder $q) => $q->where('s.ends_at', '>', $from))
                ->when($to, fn (Builder $q) => $q->where('s.starts_at', '<', $to)));
        }

        if (($text = trim((string) ($filters['q'] ?? ''))) !== '') {
            // % and _ are wildcards in LIKE. Escaped, so a search for "100%" means those characters.
            $pattern = '%'.addcslashes($text, '%_\\').'%';

            $query->where(fn (Builder $q) => $q
                ->where('u.full_name', 'ilike', $pattern)
                ->orWhere('rp.bio', 'ilike', $pattern)
                ->orWhere('rp.current_title', 'ilike', $pattern));
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'price_asc' => $query->orderBy('starting_price_minor'),
            'price_desc' => $query->orderByDesc('starting_price_minor'),
            'experience_desc' => $query->orderByDesc('rp.years_of_experience'),
            default => $query->orderByDesc('rp.rating_avg')->orderByDesc('rp.rating_count'),
        };

        // A last, unique sort key. Without it, rows that tie could swap places between two
        // requests and a recruiter could show up on two pages, or on none.
        $query->orderBy('rp.user_id');
    }

    /**
     * @param  Collection<int, \stdClass>  $rows
     * @return list<array<string, mixed>>
     */
    private function toSummaries(Collection $rows): array
    {
        $specializations = $this->specializationsOf($rows->pluck('user_id')->all());

        return $rows->map(fn (\stdClass $row) => [
            'userId' => $row->user_id,
            'fullName' => $row->full_name,
            'avatarUrl' => null,
            'currentTitle' => $row->current_title,
            'yearsOfExperience' => (int) $row->years_of_experience,
            'specializations' => $specializations[$row->user_id] ?? [],
            'ratingAvg' => (float) $row->rating_avg,
            'ratingCount' => (int) $row->rating_count,
            'startingPrice' => $row->starting_price_minor === null
                ? null
                : (new Money((int) $row->starting_price_minor))->toArray(),
            'nextAvailableAt' => $row->next_available_at === null
                ? null
                : ApiDate::format(Carbon::parse($row->next_available_at)),
        ])->all();
    }

    /**
     * The specializations of many recruiters in one query (not one query per recruiter).
     *
     * @param  list<string>  $recruiterIds
     * @return array<string, list<array<string, mixed>>> keyed by recruiter id
     */
    private function specializationsOf(array $recruiterIds): array
    {
        $nameColumn = app()->getLocale() === 'ar' ? 's.name_ar' : 's.name_en';

        return DB::table('recruiter_specializations as rs')
            ->join('specializations as s', 's.id', '=', 'rs.specialization_id')
            ->whereIn('rs.recruiter_id', $recruiterIds)
            ->orderBy('s.slug')
            ->get(['rs.recruiter_id', 's.id', 's.slug', "$nameColumn as name", 's.parent_id'])
            ->groupBy('recruiter_id')
            ->map(fn (Collection $group) => $group->map(fn (\stdClass $s) => [
                'id' => $s->id,
                'slug' => $s->slug,
                'name' => $s->name,
                'parentId' => $s->parent_id,
            ])->values()->all())
            ->all();
    }
}
