<?php

namespace Database\Seeders;

// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $adminUsername = env('ADMIN_USERNAME');
        $adminPassword = env('ADMIN_PASSWORD');
        $adminEmail = env('ADMIN_EMAIL');

        if (is_string($adminUsername) && trim($adminUsername) !== '' && is_string($adminPassword) && trim($adminPassword) !== '') {
            $adminUsername = trim($adminUsername);
            $exists = DB::table('users')->where('username', $adminUsername)->exists();
            if (!$exists) {
                $email = is_string($adminEmail) && trim($adminEmail) !== ''
                    ? trim($adminEmail)
                    : ($adminUsername.'@example.com');

                if (DB::table('users')->where('email', $email)->exists()) {
                    $email = $adminUsername.'+admin@example.com';
                }

                DB::table('users')->insert([
                    'name' => 'Admin',
                    'username' => $adminUsername,
                    'email' => $email,
                    'password' => Hash::make($adminPassword),
                    'role' => 'admin',
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }
}
