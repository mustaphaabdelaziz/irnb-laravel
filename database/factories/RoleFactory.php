<?php

namespace Database\Factories;

use App\Models\Role;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'key' => $this->faker->unique()->slug(2),
            'name' => ['en' => $this->faker->jobTitle(), 'fr' => 'Rôle', 'ar' => 'دور'],
            'permissions' => ['players' => ['view']],
            'is_system' => false,
        ];
    }
}
