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
        $createdAt = $this->faker->dateTimeBetween('-90 days', 'now');

        return [
            'id_no' => (string) $this->faker->numberBetween(100, 999999),
            'sex' => $this->faker->randomElement(['M', 'F']),
            'age' => $this->faker->numberBetween(1, 95),
            'ww' => $this->faker->boolean(25),
            'notes' => $this->faker->boolean(40) ? $this->faker->sentence(4) : null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ];
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

