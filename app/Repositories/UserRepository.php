<?php

namespace App\Repositories;

use App\Models\User;

/**
 * Creates accounts and retrieves users for credential verification.
 */
final class UserRepository
{
    /** @param array{name: string, email: string, password: string} $attributes */
    public function create(array $attributes): User
    {
        return User::query()->create($attributes);
    }

    public function findByEmail(string $email): ?User
    {
        return User::query()->where('email', $email)->first();
    }
}
