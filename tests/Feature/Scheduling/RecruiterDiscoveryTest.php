<?php

namespace Tests\Feature\Scheduling;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use App\Modules\Scheduling\Enums\SlotStatus;
use App\Modules\Scheduling\Models\AvailabilitySlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesRecruiters;
use Tests\TestCase;

class RecruiterDiscoveryTest extends TestCase
{
    use CreatesRecruiters, RefreshDatabase;

    private string $candidate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-11-15 10:00:00');
        $this->candidate = $this->register()['accessToken'];
    }

    /**
     * An approved recruiter with one active offering, so they appear in search.
     *
     * @param  array<string, mixed>  $profile
     * @param  array<string, mixed>  $offering
     * @return array<string, mixed>
     */
    private function listed(string $name, array $profile = [], array $offering = []): array
    {
        $auth = $this->recruiter($name, $profile);
        $this->offer($auth['accessToken'], $offering);

        return $auth;
    }

    private function rate(array $auth, float $average, int $count): void
    {
        // What the Reviews module will maintain in milestone M6.
        RecruiterProfile::whereKey($auth['user']['id'])->update(['rating_avg' => $average, 'rating_count' => $count]);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function search(array $query = []): array
    {
        return $this->api('GET', '/recruiters?'.http_build_query($query), token: $this->candidate)->assertOk()->json();
    }

    /**
     * @param  array<string, mixed>  $query
     * @return list<string>
     */
    private function names(array $query = []): array
    {
        return array_column($this->search($query)['items'], 'fullName');
    }

    public function test_a_search_result_has_the_contract_shape(): void
    {
        $sara = $this->listed('Sara Mostafa', ['currentTitle' => 'Senior Technical Recruiter']);
        $this->rate($sara, 4.8, 37);
        $this->slot($sara['accessToken'], '+1 day 16:00', '+1 day 17:00');
        $backend = $this->specialization('backend');

        $result = $this->search();

        $this->assertSame(['items', 'page', 'pageSize', 'totalCount'], array_keys($result));
        $this->assertSame([1, 20, 1], [$result['page'], $result['pageSize'], $result['totalCount']]);
        $this->assertEquals([
            'userId' => $sara['user']['id'],
            'fullName' => 'Sara Mostafa',
            'avatarUrl' => null,
            'currentTitle' => 'Senior Technical Recruiter',
            'yearsOfExperience' => 8,
            'specializations' => [['id' => $backend->id, 'slug' => 'backend', 'name' => 'Backend', 'parentId' => null]],
            'ratingAvg' => 4.8,
            'ratingCount' => 37,
            'startingPrice' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            'nextAvailableAt' => '2026-11-16T16:00:00Z',
        ], $result['items'][0]);
        $this->assertSame(array_keys($result['items'][0]), [
            'userId', 'fullName', 'avatarUrl', 'currentTitle', 'yearsOfExperience',
            'specializations', 'ratingAvg', 'ratingCount', 'startingPrice', 'nextAvailableAt',
        ]);
    }

    public function test_only_approved_recruiters_with_an_active_offering_are_listed(): void
    {
        $this->listed('Approved With Offering');
        $this->recruiter('Approved Without Offering');
        $this->recruiter('Pending Recruiter', status: VerificationStatus::Pending);

        // Approved once, published an offering, then got rejected.
        $rejected = $this->listed('Rejected Later');
        RecruiterProfile::whereKey($rejected['user']['id'])->update(['verification_status' => VerificationStatus::Rejected]);

        // Approved, but switched the only offering off.
        $paused = $this->recruiter('Paused Recruiter');
        $offering = $this->offer($paused['accessToken']);
        $this->api('PATCH', "/me/offerings/{$offering['id']}", ['isActive' => false], $paused['accessToken'])->assertOk();

        $this->assertSame(['Approved With Offering'], $this->names());
    }

    public function test_filter_by_specialization_and_a_parent_also_finds_its_children(): void
    {
        $backend = $this->specialization('backend', 'software');
        $design = $this->specialization('design');
        $this->listed('Backend Recruiter', ['specializationIds' => [$backend->id]]);
        $this->listed('Design Recruiter', ['specializationIds' => [$design->id]]);

        $this->assertSame(['Backend Recruiter'], $this->names(['specializationId' => $backend->id]));
        $this->assertSame(['Backend Recruiter'], $this->names(['specializationId' => $this->specialization('software')->id]));
        $this->assertSame(['Design Recruiter'], $this->names(['specializationId' => $design->id]));
        $this->assertSame([], $this->names(['specializationId' => '01a1c737-b065-7130-8d08-f913763e916c']));
    }

    public function test_filter_by_session_type_and_mode(): void
    {
        $this->listed('Voice Interviewer', offering: ['sessionType' => 'mock_interview', 'mode' => 'voice']);
        $this->listed('Chat Reviewer', offering: ['sessionType' => 'cv_review', 'mode' => 'chat']);

        $this->assertSame(['Voice Interviewer'], $this->names(['sessionType' => 'mock_interview']));
        $this->assertSame(['Chat Reviewer'], $this->names(['mode' => 'chat']));
        // Both conditions must be met by the SAME offering.
        $this->assertSame([], $this->names(['sessionType' => 'mock_interview', 'mode' => 'chat']));
    }

    public function test_the_starting_price_is_the_cheapest_offering_that_fits_the_filters(): void
    {
        $sara = $this->recruiter('Sara Mostafa');
        $this->offer($sara['accessToken'], ['mode' => 'chat', 'price' => ['amountMinor' => 20000, 'currency' => 'EGP']]);
        $this->offer($sara['accessToken'], ['mode' => 'voice', 'price' => ['amountMinor' => 45000, 'currency' => 'EGP']]);
        $off = $this->offer($sara['accessToken'], ['sessionType' => 'cv_review', 'price' => ['amountMinor' => 10000, 'currency' => 'EGP']]);
        $this->api('PATCH', "/me/offerings/{$off['id']}", ['isActive' => false], $sara['accessToken'])->assertOk();

        $price = fn (array $query) => $this->search($query)['items'][0]['startingPrice']['amountMinor'];

        // The inactive 100 EGP one never counts.
        $this->assertSame(20000, $price([]));
        // Filtered to voice, the card must not advertise the chat price.
        $this->assertSame(45000, $price(['mode' => 'voice']));
    }

    public function test_filter_by_maximum_price(): void
    {
        $this->listed('Cheap', offering: ['price' => ['amountMinor' => 20000, 'currency' => 'EGP']]);
        $this->listed('Expensive', offering: ['price' => ['amountMinor' => 90000, 'currency' => 'EGP']]);

        $this->assertSame(['Cheap'], $this->names(['maxPriceMinor' => 20000]));
        $this->assertSame([], $this->names(['maxPriceMinor' => 19999]));
        $this->assertEqualsCanonicalizing(['Cheap', 'Expensive'], $this->names(['maxPriceMinor' => 90000]));
    }

    public function test_filter_by_minimum_rating(): void
    {
        $this->rate($this->listed('Top Rated'), 4.8, 10);
        $this->rate($this->listed('Average'), 3.9, 10);
        $this->listed('No Reviews Yet');

        $this->assertSame(['Top Rated'], $this->names(['minRating' => 4]));
        $this->assertSame(['Top Rated', 'Average'], $this->names(['minRating' => 3.9]));
    }

    public function test_filter_by_availability_counts_only_free_future_slots(): void
    {
        $free = $this->listed('Free Tomorrow');
        $this->slot($free['accessToken'], '+1 day 16:00', '+1 day 17:00');

        $booked = $this->listed('Booked Tomorrow');
        $taken = $this->slot($booked['accessToken'], '+1 day 16:00', '+1 day 17:00');
        AvailabilitySlot::whereKey($taken['id'])->update(['status' => SlotStatus::Booked]);

        $nextWeek = $this->listed('Free Next Week');
        $this->slot($nextWeek['accessToken'], '+7 days 16:00', '+7 days 17:00');

        $this->listed('No Slots');

        $tomorrow = ['availableFrom' => '2026-11-16T00:00:00Z', 'availableTo' => '2026-11-17T00:00:00Z'];
        $this->assertSame(['Free Tomorrow'], $this->names($tomorrow));
        $this->assertEqualsCanonicalizing(['Free Tomorrow', 'Free Next Week'], $this->names(['availableFrom' => '2026-11-16T00:00:00Z']));
        $this->assertSame(['Free Next Week'], $this->names(['availableFrom' => '2026-11-20T00:00:00Z']));

        // Once the slot's start has passed, it no longer counts.
        $this->travelTo('2026-11-16 16:30:00');
        $this->candidate = $this->register()['accessToken']; // the old token expired with the time jump
        $this->assertSame([], $this->names($tomorrow));
    }

    public function test_next_available_is_the_earliest_free_future_slot_or_null(): void
    {
        $sara = $this->listed('Sara Mostafa');
        $this->listed('No Slots');
        $this->slot($sara['accessToken'], '+5 days 10:00', '+5 days 11:00');
        $soon = $this->slot($sara['accessToken'], '+1 day 16:00', '+1 day 17:00');

        $next = fn () => collect($this->search()['items'])->pluck('nextAvailableAt', 'fullName')->all();

        $this->assertSame(['Sara Mostafa' => '2026-11-16T16:00:00Z', 'No Slots' => null], $next());

        // When the earliest one gets booked, the next free one takes its place.
        AvailabilitySlot::whereKey($soon['id'])->update(['status' => SlotStatus::Held]);
        $this->assertSame('2026-11-20T10:00:00Z', $next()['Sara Mostafa']);
    }

    public function test_free_text_search_looks_in_name_title_and_bio_ignoring_case(): void
    {
        $this->listed('Sara Mostafa', ['currentTitle' => 'Talent Partner']);
        $this->listed('Mona Adel', ['currentTitle' => 'Fintech Hiring Lead']);
        $this->listed('Omar Nabil', ['bio' => 'I hire data engineers and analysts for e-commerce companies across the Gulf.']);

        $this->assertSame(['Sara Mostafa'], $this->names(['q' => 'sara']));
        $this->assertSame(['Mona Adel'], $this->names(['q' => 'FINTECH hiring']));
        $this->assertSame(['Omar Nabil'], $this->names(['q' => 'e-commerce']));
        $this->assertSame([], $this->names(['q' => 'nobody matches this']));
    }

    public function test_percent_and_underscore_in_the_search_text_are_plain_characters(): void
    {
        $this->listed('Sara Mostafa');
        $this->listed('Mona Adel', ['bio' => 'I placed 100% of my shortlisted candidates last year, mostly backend_engineers.']);

        // As LIKE wildcards, % and _ would match everyone. As plain characters they match
        // only Mona, whose bio really contains them.
        $this->assertSame(['Mona Adel'], $this->names(['q' => '100%']));
        $this->assertSame(['Mona Adel'], $this->names(['q' => 'backend_e']));
        $this->assertSame(['Mona Adel'], $this->names(['q' => '%']));
        $this->assertSame(['Mona Adel'], $this->names(['q' => '_']));
        // "backendXe" would match "backend_e" if _ were still a wildcard.
        $this->assertSame([], $this->names(['q' => 'backendXe']));
        $this->assertSame([], $this->names(['q' => '100X']));
    }

    public function test_sorting(): void
    {
        $a = $this->listed('Rec A', ['yearsOfExperience' => 3], ['price' => ['amountMinor' => 60000, 'currency' => 'EGP']]);
        $b = $this->listed('Rec B', ['yearsOfExperience' => 12], ['price' => ['amountMinor' => 20000, 'currency' => 'EGP']]);
        $c = $this->listed('Rec C', ['yearsOfExperience' => 7], ['price' => ['amountMinor' => 40000, 'currency' => 'EGP']]);
        $this->rate($a, 4.9, 5);
        $this->rate($b, 4.2, 80);
        $this->rate($c, 4.9, 30);

        // Default: best rating first, and more reviews wins a tie.
        $this->assertSame(['Rec C', 'Rec A', 'Rec B'], $this->names());
        $this->assertSame(['Rec C', 'Rec A', 'Rec B'], $this->names(['sort' => 'rating_desc']));
        $this->assertSame(['Rec B', 'Rec C', 'Rec A'], $this->names(['sort' => 'price_asc']));
        $this->assertSame(['Rec A', 'Rec C', 'Rec B'], $this->names(['sort' => 'price_desc']));
        $this->assertSame(['Rec B', 'Rec C', 'Rec A'], $this->names(['sort' => 'experience_desc']));
    }

    public function test_paging_returns_every_recruiter_exactly_once(): void
    {
        // Five recruiters that tie on every sort key: the hard case for stable paging.
        foreach (range(1, 5) as $i) {
            $this->listed("Recruiter $i");
        }

        $pages = array_map(fn (int $page) => $this->search(['page' => $page, 'pageSize' => 2]), [1, 2, 3, 4]);

        $this->assertSame([2, 2, 1, 0], array_map(fn (array $p) => count($p['items']), $pages));
        $this->assertSame([5, 5, 5, 5], array_column($pages, 'totalCount'));
        $this->assertSame([2, 2], [$pages[0]['pageSize'], $pages[1]['page']]);

        $seen = array_merge(...array_map(fn (array $p) => array_column($p['items'], 'fullName'), $pages));
        $this->assertCount(5, array_unique($seen));
    }

    public function test_the_total_counts_all_matches_not_just_the_page(): void
    {
        $this->listed('Chat One', offering: ['mode' => 'chat']);
        $this->listed('Chat Two', offering: ['mode' => 'chat']);
        $this->listed('Voice One', offering: ['mode' => 'voice']);

        $result = $this->search(['mode' => 'chat', 'pageSize' => 1]);

        $this->assertSame(2, $result['totalCount']);
        $this->assertCount(1, $result['items']);
    }

    public function test_specialization_names_follow_accept_language(): void
    {
        $this->listed('Sara Mostafa');

        $items = $this->api('GET', '/recruiters', token: $this->candidate, headers: ['Accept-Language' => 'ar'])->json('items');

        $this->assertSame('تخصص backend', $items[0]['specializations'][0]['name']);
    }

    /** @param  array<string, mixed>  $query */
    #[DataProvider('invalidQueries')]
    public function test_invalid_search_parameters_are_rejected(array $query, string $field): void
    {
        $response = $this->api('GET', '/recruiters?'.http_build_query($query), token: $this->candidate);

        $this->assertProblem($response, 400, 'VALIDATION_FAILED');
        $this->assertSame([$field], array_keys($response->json('errors')));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidQueries(): array
    {
        return [
            'specialization id that is not a uuid' => [['specializationId' => 'backend'], 'specializationId'],
            'unknown session type' => [['sessionType' => 'coaching'], 'sessionType'],
            'unknown mode' => [['mode' => 'video'], 'mode'],
            'negative price' => [['maxPriceMinor' => -1], 'maxPriceMinor'],
            'rating above 5' => [['minRating' => 6], 'minRating'],
            'date that is not a date' => [['availableFrom' => 'soon'], 'availableFrom'],
            'window that ends before it starts' => [['availableFrom' => '2026-11-20T00:00:00Z', 'availableTo' => '2026-11-19T00:00:00Z'], 'availableTo'],
            'unknown sort' => [['sort' => 'random'], 'sort'],
            'page zero' => [['page' => 0], 'page'],
            'page size above 100' => [['pageSize' => 101], 'pageSize'],
            'search text over 100 characters' => [['q' => str_repeat('a', 101)], 'q'],
        ];
    }

    public function test_recruiters_can_search_too_but_guests_cannot(): void
    {
        $sara = $this->listed('Sara Mostafa');

        $this->api('GET', '/recruiters', token: $sara['accessToken'])->assertOk();
        $this->assertProblem($this->api('GET', '/recruiters'), 401, 'TOKEN_INVALID');
    }

    public function test_the_public_profile_shows_the_recruiter_with_active_offerings_only(): void
    {
        $sara = $this->recruiter('Sara Mostafa', [
            'languages' => ['ar', 'en'],
            'linkedinUrl' => 'https://www.linkedin.com/in/sara-mostafa',
        ]);
        $voice = $this->offer($sara['accessToken']);
        $chat = $this->offer($sara['accessToken'], ['mode' => 'chat', 'price' => ['amountMinor' => 30000, 'currency' => 'EGP']]);
        $this->api('PATCH', "/me/offerings/{$chat['id']}", ['isActive' => false], $sara['accessToken'])->assertOk();

        $profile = $this->api('GET', "/recruiters/{$sara['user']['id']}", token: $this->candidate)->assertOk()->json();

        $this->assertSame([
            'userId', 'fullName', 'avatarUrl', 'currentTitle', 'yearsOfExperience', 'specializations',
            'ratingAvg', 'ratingCount', 'startingPrice', 'nextAvailableAt', 'bio', 'languages', 'linkedinUrl', 'offerings',
        ], array_keys($profile));
        $this->assertSame('Sara Mostafa', $profile['fullName']);
        $this->assertSame(['ar', 'en'], $profile['languages']);
        $this->assertSame('https://www.linkedin.com/in/sara-mostafa', $profile['linkedinUrl']);
        $this->assertSame([$voice['id']], array_column($profile['offerings'], 'id'));
        $this->assertSame(['amountMinor' => 45000, 'currency' => 'EGP'], $profile['startingPrice']);

        // Nothing private leaks out.
        $this->assertArrayNotHasKey('verificationStatus', $profile);
        $this->assertArrayNotHasKey('verificationNote', $profile);
        $this->assertArrayNotHasKey('cancellationCount', $profile);
        $this->assertArrayNotHasKey('email', $profile);
    }

    public function test_an_approved_recruiter_without_offerings_still_has_a_public_profile(): void
    {
        $sara = $this->recruiter('Sara Mostafa');

        $this->api('GET', "/recruiters/{$sara['user']['id']}", token: $this->candidate)
            ->assertOk()
            ->assertJson(['startingPrice' => null, 'nextAvailableAt' => null, 'offerings' => []]);
    }

    public function test_the_public_profile_of_a_recruiter_who_is_not_approved_is_a_404(): void
    {
        $pending = $this->recruiter('Pending', status: VerificationStatus::Pending);
        $candidateId = $this->register()['user']['id'];

        foreach ([$pending['user']['id'], $candidateId, '01a1c737-b065-7130-8d08-f913763e916c', 'not-a-uuid'] as $id) {
            $this->assertProblem($this->api('GET', "/recruiters/$id", token: $this->candidate), 404, 'NOT_FOUND');
            $this->assertProblem(
                $this->api('GET', "/recruiters/$id/slots?from=2026-11-15T00:00:00Z&to=2026-11-30T00:00:00Z", token: $this->candidate),
                404,
                'NOT_FOUND',
            );
        }
    }

    public function test_a_recruiters_slots_show_only_free_future_ones_that_start_in_the_window(): void
    {
        $sara = $this->recruiter('Sara Mostafa');
        $token = $sara['accessToken'];
        $second = $this->slot($token, '+2 days 10:00', '+2 days 11:00');
        $first = $this->slot($token, '+1 day 16:00', '+1 day 17:00');
        $held = $this->slot($token, '+3 days 10:00', '+3 days 11:00');
        $this->slot($token, '+20 days 10:00', '+20 days 11:00');
        $today = $this->slot($token, '+2 hours', '+3 hours');
        AvailabilitySlot::whereKey($held['id'])->update(['status' => SlotStatus::Held]);
        $uri = "/recruiters/{$sara['user']['id']}/slots?from=2026-11-15T00:00:00Z&to=2026-11-30T00:00:00Z";

        $list = $this->api('GET', $uri, token: $this->candidate)->assertOk()->json();
        $this->assertSame([$today['id'], $first['id'], $second['id']], array_column($list, 'id'));
        $this->assertSame(['open', 'open', 'open'], array_column($list, 'status'));

        // Three hours later, today's slot has started and is gone from the list.
        $this->travelTo('2026-11-15 13:00:00');
        $this->candidate = $this->register()['accessToken']; // the old token expired with the time jump
        $list = $this->api('GET', $uri, token: $this->candidate)->json();
        $this->assertSame([$first['id'], $second['id']], array_column($list, 'id'));
    }

    public function test_a_recruiters_slots_need_a_valid_window(): void
    {
        $sara = $this->recruiter('Sara Mostafa');
        $base = "/recruiters/{$sara['user']['id']}/slots";

        $this->assertProblem($this->api('GET', $base, token: $this->candidate), 400, 'VALIDATION_FAILED');
        $this->assertProblem($this->api('GET', "$base?from=2026-11-01T00:00:00Z&to=2026-12-15T00:00:00Z", token: $this->candidate), 400, 'VALIDATION_FAILED');
        $this->assertProblem($this->api('GET', "$base?from=2026-11-15T00:00:00Z&to=2026-11-30T00:00:00Z"), 401, 'TOKEN_INVALID');
    }
}
