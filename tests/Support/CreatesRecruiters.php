<?php

namespace Tests\Support;

use App\Modules\Profiles\Enums\VerificationStatus;
use App\Modules\Profiles\Models\RecruiterProfile;
use App\Modules\Profiles\Models\Specialization;

/**
 * Builds recruiters the way they come to exist in real life: register, fill in the profile
 * through the API, then an admin decision. Only the admin decision is written straight to
 * the database, because the admin endpoints are milestone M7.
 */
trait CreatesRecruiters
{
    /**
     * @param  array<string, mixed>  $profile  overrides for the profile body
     * @return array<string, mixed> the AuthResponse, so tests have the token and user id
     */
    protected function recruiter(
        string $name = 'Sara Mostafa',
        array $profile = [],
        VerificationStatus $status = VerificationStatus::Approved,
    ): array {
        $auth = $this->register(['role' => 'recruiter', 'fullName' => $name]);

        $this->api('PUT', '/me/recruiter-profile', [
            'bio' => 'Technical recruiter for 8 years, hiring backend and mobile engineers for fintech companies.',
            'yearsOfExperience' => 8,
            'specializationIds' => [$this->specialization('backend')->id],
            'timezone' => 'Africa/Cairo',
            ...$profile,
        ], $auth['accessToken'])->assertOk();

        if ($status !== VerificationStatus::Pending) {
            RecruiterProfile::whereKey($auth['user']['id'])->update(['verification_status' => $status]);
        }

        return $auth;
    }

    /** The specialization with this slug, created on first use. */
    protected function specialization(string $slug, ?string $parentSlug = null): Specialization
    {
        return Specialization::firstWhere('slug', $slug) ?? Specialization::factory()->create([
            'slug' => $slug,
            'name_en' => ucfirst($slug),
            'name_ar' => 'تخصص '.$slug,
            'parent_id' => $parentSlug ? $this->specialization($parentSlug)->id : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function offeringBody(array $overrides = []): array
    {
        return [
            'sessionType' => 'mock_interview',
            'mode' => 'voice',
            'durationMinutes' => 45,
            'price' => ['amountMinor' => 45000, 'currency' => 'EGP'],
            ...$overrides,
        ];
    }

    /**
     * Publishes an offering through the API and returns it.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function offer(string $token, array $overrides = []): array
    {
        return $this->api('POST', '/me/offerings', $this->offeringBody($overrides), $token)->assertCreated()->json();
    }

    /**
     * Publishes one slot through the API and returns it. Times are relative to now,
     * e.g. slot($token, '+1 day 16:00', '+1 day 17:00').
     *
     * @return array<string, mixed>
     */
    protected function slot(string $token, string $start, string $end): array
    {
        return $this->api('POST', '/me/slots', ['slots' => [$this->slotBody($start, $end)]], $token)
            ->assertCreated()
            ->json(0);
    }

    /** @return array{startsAt: string, endsAt: string} */
    protected function slotBody(string $start, string $end): array
    {
        return [
            'startsAt' => now()->modify($start)->utc()->format('Y-m-d\TH:i:s\Z'),
            'endsAt' => now()->modify($end)->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}
