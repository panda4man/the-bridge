<?php

namespace Tests\Unit;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\HealthStatus;
use PHPUnit\Framework\TestCase;

/**
 * Ported from reference/tests/Unit/enums.test.ts, case for case.
 */
class EnumsTest extends TestCase
{
    public function test_app_status_has_4_expected_values(): void
    {
        $this->assertCount(4, AppStatus::cases());
        $this->assertSame('idle', AppStatus::Idle->value);
        $this->assertSame('deploying', AppStatus::Deploying->value);
        $this->assertSame('success', AppStatus::Success->value);
        $this->assertSame('failed', AppStatus::Failed->value);
    }

    public function test_deployment_status_has_4_expected_values(): void
    {
        $this->assertCount(4, DeploymentStatus::cases());
        $this->assertSame('pending', DeploymentStatus::Pending->value);
        $this->assertSame('running', DeploymentStatus::Running->value);
        $this->assertSame('success', DeploymentStatus::Success->value);
        $this->assertSame('failed', DeploymentStatus::Failed->value);
    }

    /**
     * Not present in the reference suite (HealthStatus wasn't exercised by
     * enums.test.ts), but added here since HealthStatus is part of this
     * port's scope.
     */
    public function test_health_status_has_3_expected_values(): void
    {
        $this->assertCount(3, HealthStatus::cases());
        $this->assertSame('up', HealthStatus::Up->value);
        $this->assertSame('down', HealthStatus::Down->value);
        $this->assertSame('unknown', HealthStatus::Unknown->value);
    }
}
