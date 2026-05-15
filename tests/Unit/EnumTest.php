<?php

namespace Tests\Unit;

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use PHPUnit\Framework\TestCase;

class EnumTest extends TestCase
{
    public function test_app_status_has_expected_cases(): void
    {
        $this->assertCount(4, AppStatus::cases());
        $this->assertSame('idle', AppStatus::Idle->value);
        $this->assertSame('deploying', AppStatus::Deploying->value);
        $this->assertSame('success', AppStatus::Success->value);
        $this->assertSame('failed', AppStatus::Failed->value);
    }

    public function test_deployment_status_has_expected_cases(): void
    {
        $this->assertCount(4, DeploymentStatus::cases());
        $this->assertSame('pending', DeploymentStatus::Pending->value);
        $this->assertSame('running', DeploymentStatus::Running->value);
        $this->assertSame('success', DeploymentStatus::Success->value);
        $this->assertSame('failed', DeploymentStatus::Failed->value);
    }
}
