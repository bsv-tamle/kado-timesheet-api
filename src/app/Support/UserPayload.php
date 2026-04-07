<?php

namespace App\Support;

use App\Models\User;

class UserPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(User $user): array
    {
        return [
            'id' => $user->id,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'phone' => $user->phone,
            'department_id' => $user->department_id,
            'position_id' => $user->position_id,
            'role' => $user->role,
            'status' => $user->status,
            'must_change_password' => (bool) $user->must_change_password,
            'updated_at' => $user->updated_at?->toISOString(),
        ];
    }
}
