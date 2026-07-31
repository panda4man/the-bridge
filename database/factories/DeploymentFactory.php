<?php

namespace Database\Factories;

use App\Enums\DeploymentStatus;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Deployment>
 */
class DeploymentFactory extends Factory
{
    protected $model = Deployment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'app_id' => App::factory(),
            'status' => DeploymentStatus::Pending,
            'log' => null,
            'started_at' => null,
            'finished_at' => null,
            'commit_sha' => $this->faker->sha1(),
            'commit_message' => $this->faker->sentence(),
            'rollback_sha' => null,
        ];
    }
}
