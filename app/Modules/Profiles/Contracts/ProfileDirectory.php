<?php

namespace App\Modules\Profiles\Contracts;

/**
 * What the Scheduling module needs to know from Profiles when a session is booked and shown.
 * Everything comes back as plain arrays in the contract's shapes, never as Profiles' models.
 */
interface ProfileDirectory
{
    /** The specialization on the candidate's profile, or null if they have no profile yet. */
    public function candidateSpecializationId(string $userId): ?string;

    /**
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> `Specialization` shape, keyed by id, in the request's language
     */
    public function specializations(array $ids): array;

    /** Is this a CV the candidate uploaded and has not deleted? */
    public function candidateOwnsCv(string $userId, string $cvId): bool;

    /**
     * Includes CVs the candidate deleted later: a session keeps showing the CV it was booked with.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>> `Cv` shape, keyed by id
     */
    public function cvs(array $ids): array;
}
