<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

class UserRoleTest extends TestCase
{
    public function test_section_admins_are_detected(): void
    {
        $this->assertTrue(User::factory()->admin()->make()->isAdmin());
        $this->assertTrue(User::factory()->nurseAdmin()->make()->isAdmin());
        $this->assertTrue(User::factory()->doctorAdmin()->make()->isAdmin());
        $this->assertFalse(User::factory()->nurse()->make()->isAdmin());
        $this->assertFalse(User::factory()->doctor()->make()->isAdmin());
        $this->assertFalse((new User(['role' => 'user']))->isAdmin());
    }
}
