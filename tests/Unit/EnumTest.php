<?php

test('AppStatus has expected cases', function () {
    expect(App\Enums\AppStatus::cases())->toHaveCount(4);
    expect(App\Enums\AppStatus::Idle->value)->toBe('idle');
    expect(App\Enums\AppStatus::Deploying->value)->toBe('deploying');
    expect(App\Enums\AppStatus::Success->value)->toBe('success');
    expect(App\Enums\AppStatus::Failed->value)->toBe('failed');
});

test('DeploymentStatus has expected cases', function () {
    expect(App\Enums\DeploymentStatus::cases())->toHaveCount(4);
    expect(App\Enums\DeploymentStatus::Pending->value)->toBe('pending');
    expect(App\Enums\DeploymentStatus::Running->value)->toBe('running');
    expect(App\Enums\DeploymentStatus::Success->value)->toBe('success');
    expect(App\Enums\DeploymentStatus::Failed->value)->toBe('failed');
});
