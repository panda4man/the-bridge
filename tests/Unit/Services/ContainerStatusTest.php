<?php

namespace Tests\Unit\Services;

use App\Services\ContainerStatus;
use Tests\Support\FakeProcessRunner;
use Tests\TestCase;

/**
 * Not present in the reference test suite (containerStatus.ts has no
 * dedicated .test.ts) — added because the service is still part of Phase
 * 2's deliverables. Covers NDJSON parsing (one JSON object per line, not a
 * JSON array), the empty-output case, non-zero exit, and malformed JSON —
 * all of which must return [] silently per the spec.
 */
class ContainerStatusTest extends TestCase
{
    public function test_parses_ndjson_output_into_container_info(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess(
            '{"Name":"app-web-1","Service":"web","State":"running","Status":"Up 2 hours","Ports":"0.0.0.0:8080->80/tcp"}'."\n"
            .'{"Name":"app-worker-1","Service":"worker","State":"exited","Status":"Exited (0)","Ports":""}'
        );
        $service = new ContainerStatus($runner);

        $result = $service->forWorkDir('/tmp/app');

        $this->assertSame([
            ['name' => 'app-web-1', 'service' => 'web', 'state' => 'running', 'status' => 'Up 2 hours', 'ports' => '0.0.0.0:8080->80/tcp'],
            ['name' => 'app-worker-1', 'service' => 'worker', 'state' => 'exited', 'status' => 'Exited (0)', 'ports' => ''],
        ], $result);
    }

    public function test_lowercases_state(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess('{"Name":"a","Service":"a","State":"RUNNING","Status":"Up","Ports":""}');
        $service = new ContainerStatus($runner);

        $result = $service->forWorkDir('/tmp/app');

        $this->assertSame('running', $result[0]['state']);
    }

    public function test_defaults_missing_fields(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess('{}');
        $service = new ContainerStatus($runner);

        $result = $service->forWorkDir('/tmp/app');

        $this->assertSame([
            ['name' => '', 'service' => '', 'state' => 'unknown', 'status' => '', 'ports' => ''],
        ], $result);
    }

    public function test_returns_empty_array_when_output_is_blank(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess('');
        $service = new ContainerStatus($runner);

        $this->assertSame([], $service->forWorkDir('/tmp/app'));
    }

    public function test_returns_empty_array_on_non_zero_exit(): void
    {
        // QC: queuing an empty-stdout failure doesn't actually pin the
        // `! $result->successful()` guard — the blank-output check right
        // after it already returns [] regardless of exit code, so removing
        // the guard entirely still passed. Non-empty, validly-parseable
        // stdout on a non-zero exit (a partial/failed `docker compose ps`
        // that still printed something before erroring) is what actually
        // discriminates: only the exit-code guard stands between it and a
        // parsed result.
        $runner = new FakeProcessRunner;
        $runner->queueFailure(
            1,
            '{"Name":"a","Service":"a","State":"running","Status":"Up","Ports":""}',
            'no configuration file provided'
        );
        $service = new ContainerStatus($runner);

        $this->assertSame([], $service->forWorkDir('/tmp/app'));
    }

    public function test_returns_empty_array_on_malformed_json_line(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess('{not-json}');
        $service = new ContainerStatus($runner);

        $this->assertSame([], $service->forWorkDir('/tmp/app'));
    }

    public function test_handles_a_json_array_response_not_just_ndjson(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess(
            '[{"Name":"a","Service":"a","State":"running","Status":"Up","Ports":""},'
            .'{"Name":"b","Service":"b","State":"running","Status":"Up","Ports":""}]'
        );
        $service = new ContainerStatus($runner);

        $result = $service->forWorkDir('/tmp/app');

        $this->assertCount(2, $result);
    }

    public function test_runs_with_an_8_second_timeout(): void
    {
        $runner = new FakeProcessRunner;
        $runner->queueSuccess('');
        $service = new ContainerStatus($runner);

        $service->forWorkDir('/tmp/app');

        $this->assertSame(8.0, $runner->calls[0]['timeout']);
        $this->assertSame(['docker', 'compose', 'ps', '--format', 'json'], $runner->calls[0]['command']);
        $this->assertSame('/tmp/app', $runner->calls[0]['cwd']);
    }
}
