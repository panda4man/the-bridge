<?php

namespace Database\Factories;

use App\Enums\HealthStatus;
use App\Models\App;
use App\Models\HealthCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HealthCheck>
 */
class HealthCheckFactory extends Factory
{
    protected $model = HealthCheck::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'status' => HealthStatus::Up,
            'http_status_code' => 200,
            'response_time_ms' => $this->faker->numberBetween(10, 300),
            'checked_at' => now(),
        ];
    }
}
