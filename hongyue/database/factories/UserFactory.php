<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * User 工厂（测试用）
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'employee_no' => 'T'.strtoupper(Str::random(6)),
            'name' => fake()->name(),
            'phone' => fake()->numerify('139########'),
            'password_hash' => Hash::make('password'),
            'job_type' => User::JOB_WORKER,
            'status' => 1,
        ];
    }

    /**
     * 指定岗位类型
     */
    public function jobType(string $jobType): static
    {
        return $this->state(fn (array $attributes) => ['job_type' => $jobType]);
    }
}