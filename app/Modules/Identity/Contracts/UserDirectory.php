<?php

namespace App\Modules\Identity\Contracts;

/**
 * Lets other modules show who a user is without reading the users table themselves.
 */
interface UserDirectory
{
    /**
     * The `ParticipantSummary` shape of the contract for many users, in one query.
     *
     * @param  list<string>  $userIds
     * @return array<string, array{userId: string, fullName: string, avatarUrl: null}> keyed by user id
     */
    public function summaries(array $userIds): array;
}
