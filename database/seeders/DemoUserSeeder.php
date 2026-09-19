<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DemoUserSeeder extends Seeder
{
    public function run(): void
    {
        User::query()->updateOrCreate(
            ['email' => 'ahmed@example.com'],
            ['name' => 'Ahmed', 'password' => 'password'],
        );

        User::query()->updateOrCreate(
            ['email' => 'sara@example.com'],
            ['name' => 'Sara', 'password' => 'password'],
        );
    }
}
