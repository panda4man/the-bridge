<?php

namespace Database\Factories;

use App\Enums\AppStatus;
use App\Models\App;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<App>
 */
class AppFactory extends Factory
{
    protected $model = App::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $slug = Str::slug($this->faker->unique()->words(2, true));

        return [
            'name' => ucwords(str_replace('-', ' ', $slug)),
            'repo_url' => "https://github.com/example/{$slug}.git",
            'branch' => 'main',
            'path' => "/repos/{$slug}",
            'status' => AppStatus::Idle,
            'health_url' => null,
            'health_check_interval' => 60,
            'webhook_secret' => null,
            'deploy_steps' => null,
        ];
    }
}
