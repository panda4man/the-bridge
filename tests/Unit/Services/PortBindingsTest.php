<?php

namespace Tests\Unit\Services;

use App\Services\PortBindings;
use Tests\TestCase;

/**
 * Ported from reference/src/services/portBindings.test.ts, case for case
 * (15 cases). Extended with 17 cases the reference suite does not cover but
 * the port's spec — and Opus QC's B7/B8 findings — call for:
 *
 * - The `:`-prefixed interpolation operators (":-", ":+") treat an
 *   empty-but-defined variable as UNSET, while the bare operators ("-", "+")
 *   treat it as set. The reference test file never exercises this
 *   distinction (it only tests ":-"), so these cases exist specifically to
 *   catch a port that collapses "empty" and "unset" into one check —
 *   verified by mutation: collapsing
 *   `$colon === ':' ? ($isSet && $val !== '') : $isSet` down to `$isSet`
 *   alone makes test_bare_plus_alt_variant_uses_alt_when_var_is_defined_empty
 *   and test_bare_minus_default_keeps_the_empty_value fail.
 * - All 8 empty/undefined × bare/colon quadrants across "-"/"+" are now
 *   independently pinned (QC B8), not just the 5 the first pass covered.
 * - The "?"/":?" operator family had zero coverage at all (QC B7) — it
 *   returns '' when unusable, not the text after the "?".
 * - .env parsing edge cases: comment-line skipping and first-equals-only
 *   splitting (verified via reflection on the private readDotenv(), since
 *   neither is observable through interpolation alone — see
 *   readDotenvViaReflection()'s docblock), long-form `host_ip: ""`, and
 *   short forms with more than three ':'-segments.
 */
class PortBindingsTest extends TestCase
{
    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir().'/port-bindings-'.bin2hex(random_bytes(8));
        mkdir($this->workDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    private function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function writeCompose(string $content): void
    {
        file_put_contents($this->workDir.'/docker-compose.yml', $content);
    }

    private function writeDotenv(string $content): void
    {
        file_put_contents($this->workDir.'/.env', $content);
    }

    // --- reference cases ---

    public function test_returns_empty_when_docker_compose_yml_is_missing(): void
    {
        $this->assertSame([], PortBindings::read($this->workDir));
    }

    public function test_returns_empty_when_yaml_is_malformed(): void
    {
        $this->writeCompose("services:\n  web:\n    ports: [::::\n");
        $this->assertSame([], PortBindings::read($this->workDir));
    }

    public function test_parses_short_form_host_container(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    image: nginx
    ports:
      - "8080:80"
YAML);

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_parses_short_form_with_host_ip_and_udp_protocol(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "127.0.0.1:8080:80/udp"
YAML);

        $this->assertSame([
            ['service' => 'web', 'host_ip' => '127.0.0.1', 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'udp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_parses_container_only_short_form(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "80"
YAML);

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_parses_container_only_short_form_with_protocol(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "53/udp"
YAML);

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '53', 'protocol' => 'udp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_parses_long_form_object(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  api:
    ports:
      - target: 8000
        published: 9000
        protocol: tcp
        host_ip: 0.0.0.0
        mode: host
YAML);

        $this->assertSame([
            ['service' => 'api', 'host_ip' => '0.0.0.0', 'host_port' => '9000', 'container_port' => '8000', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_handles_multi_service_compose_and_sorts_deterministically(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "8080:80"
      - "8443:443"
  api:
    ports:
      - "9000:9000"
  db:
    image: postgres
YAML);

        $this->assertSame([
            ['service' => 'api', 'host_ip' => null, 'host_port' => '9000', 'container_port' => '9000', 'protocol' => 'tcp'],
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8443', 'container_port' => '443', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_omits_services_with_no_ports_key(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "8080:80"
  worker:
    image: alpine
YAML);

        $result = PortBindings::read($this->workDir);
        $this->assertCount(1, $result);
        $this->assertSame('web', $result[0]['service']);
    }

    public function test_returns_empty_when_services_key_missing(): void
    {
        $this->writeCompose("version: \"3\"\n");
        $this->assertSame([], PortBindings::read($this->workDir));
    }

    public function test_interpolates_colon_dash_default_to_default_when_env_missing(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  tagarr:
    ports:
      - "${HOST_PORT:-8080}:${BIND_PORT:-8080}"
YAML);

        $this->assertSame([
            ['service' => 'tagarr', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '8080', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_interpolates_colon_dash_default_using_dotenv_override(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  tagarr:
    ports:
      - "${HOST_PORT:-8080}:${BIND_PORT:-8080}"
YAML);
        $this->writeDotenv("HOST_PORT=9000\nBIND_PORT=7000\n");

        $this->assertSame([
            ['service' => 'tagarr', 'host_ip' => null, 'host_port' => '9000', 'container_port' => '7000', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_interpolates_quoted_dotenv_values(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${HOST_PORT}:80"
YAML);
        $this->writeDotenv("HOST_PORT=\"9090\"\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '9090', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_skips_ports_whose_container_port_is_an_unresolved_variable(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "8080:${UNSET_PORT}"
YAML);

        $this->assertSame([], PortBindings::read($this->workDir));
    }

    public function test_interpolates_var_in_long_form_published_field(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  api:
    ports:
      - target: 8000
        published: "${PUBLIC_PORT:-9000}"
        protocol: tcp
YAML);

        $this->assertSame([
            ['service' => 'api', 'host_ip' => null, 'host_port' => '9000', 'container_port' => '8000', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    // --- additional: colon vs bare empty/unset distinction (not covered by the reference suite) ---

    public function test_colon_plus_alt_variant_treats_defined_empty_as_unset(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT:+9999}:80"
YAML);
        $this->writeDotenv("PORT=\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_plus_alt_variant_uses_alt_when_var_is_defined_empty(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT+9999}:80"
YAML);
        $this->writeDotenv("PORT=\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '9999', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_colon_minus_default_variant_treats_defined_empty_as_unset(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT:-8080}:80"
YAML);
        $this->writeDotenv("PORT=\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_minus_default_keeps_the_empty_value(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT-8080}:80"
YAML);
        $this->writeDotenv("PORT=\n");

        // PORT is defined (empty). The bare variant only treats UNDEFINED as
        // unset, so the empty value is kept, host_port collapses to null.
        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_var_without_braces_is_interpolated(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "$HOST_PORT:80"
YAML);
        $this->writeDotenv("HOST_PORT=9500\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '9500', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    // --- additional: remaining empty/undefined × bare/colon quadrants (QC B8) ---

    public function test_bare_minus_default_is_used_when_var_is_undefined(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT-8080}:80"
YAML);
        // No .env at all — PORT is genuinely undefined, not defined-empty.

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_plus_alt_is_empty_when_var_is_undefined(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT+9999}:80"
YAML);
        // No .env at all — PORT is genuinely undefined.

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_plus_alt_is_used_when_var_is_defined_non_empty(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT+9999}:80"
YAML);
        $this->writeDotenv("PORT=8080\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '9999', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_colon_plus_alt_is_used_when_var_is_defined_non_empty(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT:+9999}:80"
YAML);
        $this->writeDotenv("PORT=8080\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '9999', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    // --- additional: the "?"/":?" operator family (QC B7) ---
    //
    // reference/src/services/portBindings.ts:54 returns '' when the
    // variable isn't usable — NOT the error text after the "?". A mutant
    // that emits the error text instead (`return $usable ? $val : $arg;`)
    // passed the full suite before these were added, since nothing
    // exercised this operator at all.

    public function test_bare_question_mark_returns_empty_string_when_var_is_undefined(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT?boom}:80"
YAML);

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_bare_question_mark_returns_the_value_when_var_is_defined(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT?boom}:80"
YAML);
        $this->writeDotenv("PORT=8080\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_colon_question_mark_treats_defined_empty_as_unset(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT:?boom}:80"
YAML);
        $this->writeDotenv("PORT=\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => null, 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_colon_question_mark_returns_the_value_when_defined_non_empty(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "${PORT:?boom}:80"
YAML);
        $this->writeDotenv("PORT=8080\n");

        $this->assertSame([
            ['service' => 'web', 'host_ip' => null, 'host_port' => '8080', 'container_port' => '80', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    // --- additional: .env parsing and short/long-form edge cases (QC non-blocking list) ---

    public function test_dotenv_parsing_skips_comment_lines(): void
    {
        $this->writeDotenv("# this is a comment\nHOST_PORT=8080\n# HOST_PORT=9999 (should be ignored)\n");

        $this->assertSame(['HOST_PORT' => '8080'], $this->readDotenvViaReflection());
    }

    public function test_dotenv_parsing_splits_on_the_first_equals_sign_only(): void
    {
        $this->writeDotenv("TOKEN=aa=bb\n");

        $this->assertSame(['TOKEN' => 'aa=bb'], $this->readDotenvViaReflection());
    }

    public function test_long_form_with_empty_host_ip_omits_it(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  api:
    ports:
      - target: 8000
        published: 9000
        host_ip: ""
YAML);

        $this->assertSame([
            ['service' => 'api', 'host_ip' => null, 'host_port' => '9000', 'container_port' => '8000', 'protocol' => 'tcp'],
        ], PortBindings::read($this->workDir));
    }

    public function test_rejects_short_form_with_more_than_three_segments(): void
    {
        $this->writeCompose(<<<'YAML'
services:
  web:
    ports:
      - "1:2:3:4"
YAML);

        $this->assertSame([], PortBindings::read($this->workDir));
    }

    /**
     * readDotenv() is private — reflection is used here deliberately rather
     * than only observing through PortBindings::read()'s interpolation, since
     * a key derived from a comment line (e.g. "# HOST_PORT") can never
     * collide with a real variable name looked up by the interpolator (the
     * leading '#' makes it unmatchable), so disabling the comment-skip is
     * unobservable through the public API alone — it only shows up by
     * inspecting readDotenv()'s return value directly.
     *
     * @return array<string, string>
     */
    private function readDotenvViaReflection(): array
    {
        $method = new \ReflectionMethod(PortBindings::class, 'readDotenv');
        $method->setAccessible(true);

        return $method->invoke(null, $this->workDir);
    }
}
