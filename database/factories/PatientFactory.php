<?php

namespace Database\Factories;

use App\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        $createdAt = now();

        return [
            'id_no' => (string) fake()->unique()->numberBetween(100, 999999),
            'sex' => fake()->randomElement(['M', 'F']),
            'age' => fake()->numberBetween(0, 95),
            'section' => 'nurse',
            'room' => fake()->randomElement(['room1', 'room2']),
            'ww' => false,
            'lab' => false,
            'burn' => false,
            'notes' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
    }

    public function doctor(): static
    {
        return $this->state(fn () => ['section' => 'doctor']);
    }

    public function today(): static
    {
        $now = now();
        return $this->state(fn () => [
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
