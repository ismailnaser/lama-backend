<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->numerify('user#######'),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'nurse',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function nurse(): static
    {
        return $this->state(fn () => ['role' => 'nurse']);
    }

    public function nurseAdmin(): static
    {
        return $this->state(fn () => ['role' => 'nurse_admin']);
    }

    public function doctor(): static
    {
        return $this->state(fn () => ['role' => 'doctor']);
    }

    public function doctorAdmin(): static
    {
        return $this->state(fn () => ['role' => 'doctor_admin']);
    }

    public function admin(): static
    {
        return $this->state(fn () => ['role' => 'admin']);
    }

    public function disabled(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
