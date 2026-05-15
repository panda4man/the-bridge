<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AppFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name'      => $this->faker->words(2, true),
            'repo_url'  => 'https://github.com/example/repo.git',
            'branch'    => 'main',
            'path'      => '/tmp/repos/' . $this->faker->slug(),
            'status'    => 'idle',
        ];
    }
}
