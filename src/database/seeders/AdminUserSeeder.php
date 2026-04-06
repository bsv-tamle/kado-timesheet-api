<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminEmail = env('ADMIN_EMAIL', 'admin@kado.local');
        $adminPassword = env('ADMIN_PASSWORD', 'Admin@123456');
        $adminName = env('ADMIN_FULL_NAME', 'System Administrator');

        // Upsert default admin by email.
        User::query()->updateOrCreate(
            ['email' => $adminEmail],
            [
                'full_name' => $adminName,
                'role' => 'admin',
                'status' => 'active',
                'must_change_password' => false,
                'password' => Hash::make($adminPassword),
                'email_verified_at' => now(),
            ]
        );
    }
}

