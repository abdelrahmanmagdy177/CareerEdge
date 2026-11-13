<?php

namespace App\Modules\Profiles\Services;

use App\Modules\Profiles\Contracts\ProfileDirectory;
use App\Modules\Profiles\Http\Resources\CvResource;
use App\Modules\Profiles\Http\Resources\SpecializationResource;
use App\Modules\Profiles\Models\CandidateProfile;
use App\Modules\Profiles\Models\Cv;
use App\Modules\Profiles\Models\Specialization;

final class EloquentProfileDirectory implements ProfileDirectory
{
    public function candidateSpecializationId(string $userId): ?string
    {
        return CandidateProfile::whereKey($userId)->value('specialization_id');
    }

    public function specializations(array $ids): array
    {
        return Specialization::whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Specialization $s) => [$s->id => (new SpecializationResource($s))->resolve()])
            ->all();
    }

    public function candidateOwnsCv(string $userId, string $cvId): bool
    {
        return Cv::whereKey($cvId)->where('user_id', $userId)->exists();
    }

    public function cvs(array $ids): array
    {
        return Cv::withTrashed()->whereIn('id', array_unique($ids))->get()
            ->mapWithKeys(fn (Cv $cv) => [$cv->id => (new CvResource($cv))->resolve()])
            ->all();
    }
}
