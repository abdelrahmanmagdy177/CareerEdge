<?php

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Contracts\UserDirectory;
use App\Modules\Identity\Models\User;

final class EloquentUserDirectory implements UserDirectory
{
    public function summaries(array $userIds): array
    {
        return User::whereIn('id', array_unique($userIds))
            ->get(['id', 'full_name'])
            ->mapWithKeys(fn (User $user) => [$user->id => [
                'userId' => $user->id,
                'fullName' => $user->full_name,
                // There is no avatar upload endpoint in the contract yet.
                'avatarUrl' => null,
            ]])
            ->all();
    }
}
