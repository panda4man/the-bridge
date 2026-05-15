<?php

namespace Database\Factories;

use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;

class DeploymentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'app_id'      => App::factory(),
            'status'      => 'pending',
            'log'         => null,
            'started_at'  => null,
            'finished_at' => null,
        ];
    }
}
