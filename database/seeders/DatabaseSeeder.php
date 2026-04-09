<?php

namespace Database\Seeders;

use App\Models\Patient;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        // Seed lots of patients for quick UI testing & filtering
        // - A guaranteed chunk for "today" so default view isn't empty
        // - The rest spread across the last ~90 days for date-range filters
        Patient::factory()->today()->count(200)->create();
        Patient::factory()->count(300)->create();
    }
}
