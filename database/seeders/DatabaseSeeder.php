<?php

namespace Database\Seeders;

use App\Models\Patient;
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
        if (is_string($adminUsername) && trim($adminUsername) !== '' && is_string($adminPassword) && trim($adminPassword) !== '') {
            $adminUsername = trim($adminUsername);
            $exists = DB::table('users')->where('username', $adminUsername)->exists();
            if (!$exists) {
                DB::table('users')->insert([
                    'name' => 'Admin',
                    'username' => $adminUsername,
                    'email' => null,
                    'password' => Hash::make($adminPassword),
                    'role' => 'admin',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        // Seed lots of patients for quick UI testing & filtering
        // - A guaranteed chunk for "today" so default view isn't empty
        // - The rest spread across the last ~90 days for date-range filters
        Patient::factory()->today()->count(200)->create();
        Patient::factory()->count(300)->create();
    }
}
