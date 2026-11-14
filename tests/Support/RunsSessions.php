<?php

namespace Tests\Support;

use App\Modules\Payments\Models\Payment;

/**
 * Helpers that take a session through its life via the real API.
 * Use together with CreatesRecruiters and BooksSessions.
 */
trait RunsSessions
{
    /**
     * A fresh access token for a user. Needed after every time jump: access tokens live 15 minutes.
     *
     * @param  array<string, mixed>  $auth  an AuthResponse from register()
     */
    protected function tokenOf(array $auth): string
    {
        return $this->api('POST', '/auth/login', [
            'email' => $auth['user']['email'],
            'password' => self::PASSWORD,
        ])->assertOk()->json('accessToken');
    }

    /**
     * Books and pays a session with a new recruiter, then plays it to the end: both join at the
     * start time and the recruiter ends it 40 minutes later.
     *
     * @param  array<string, mixed>  $candidate  an AuthResponse
     * @return array{id: string, recruiter: array<string, mixed>, endedAt: string}
     */
    protected function completedSession(array $candidate, string $recruiterName = 'Sara Mostafa', string $day = '+1 day'): array
    {
        $setup = $this->bookable($recruiterName, start: "$day 16:00", end: "$day 17:00");
        $session = $this->bookAndPay($this->tokenOf($candidate), $setup['body']);

        $this->travelTo($session['scheduledStart']);
        $this->api('POST', "/sessions/{$session['id']}/join", token: $this->tokenOf($candidate))->assertOk();
        $this->api('POST', "/sessions/{$session['id']}/join", token: $this->tokenOf($setup['recruiter']))->assertOk();

        $this->travel(40)->minutes();
        $ended = $this->api('POST', "/sessions/{$session['id']}/end", token: $this->tokenOf($setup['recruiter']))->assertOk();

        return ['id' => $session['id'], 'recruiter' => $setup['recruiter'], 'endedAt' => $ended->json('actualEnd')];
    }

    protected function paymentOf(string $sessionId): Payment
    {
        return Payment::where('session_id', $sessionId)->orderByDesc('id')->firstOrFail();
    }
}
