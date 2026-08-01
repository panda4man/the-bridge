<?php

namespace Tests\Feature\Api;

use Tests\TestCase;

/**
 * Ported from reference/tests/Feature/apiOpenapi.test.ts.
 */
class ApiOpenApiTest extends TestCase
{
    public function test_openapi_json_returns_200_with_valid_structure_no_auth_required(): void
    {
        $response = $this->getJson('/api/openapi.json');

        $response->assertOk();
        $response->assertJsonPath('openapi', '3.0.3');
        $response->assertJsonPath('info.title', 'The Bridge Deployment API');
        $response->assertJsonStructure(['paths']);
    }

    public function test_openapi_json_includes_all_expected_paths(): void
    {
        $response = $this->getJson('/api/openapi.json');

        $paths = array_keys($response->json('paths'));

        foreach (['/branches', '/apps', '/apps/{id}/deploy', '/deployments/{id}', '/deployments/{id}/log'] as $expected) {
            $this->assertContains($expected, $paths);
        }
    }

    public function test_openapi_json_documents_bearer_auth_security_scheme(): void
    {
        $response = $this->getJson('/api/openapi.json');

        $response->assertJsonPath('components.securitySchemes.BearerAuth.scheme', 'bearer');
    }

    // --- Bug fix ported from the phase brief: the reference marks
    // /branches' repo_url query parameter `required: true` even though the
    // handler (both here and in the reference) explicitly accepts an
    // absent/empty repo_url and returns {"branches": []} rather than a 400.
    // This port's spec follows the code. ---

    public function test_openapi_json_marks_branches_repo_url_parameter_as_optional(): void
    {
        $response = $this->getJson('/api/openapi.json');

        $response->assertJsonPath('paths./branches.get.parameters.0.name', 'repo_url');
        $response->assertJsonPath('paths./branches.get.parameters.0.required', false);
    }

    public function test_docs_returns_200_with_html_containing_swagger_ui(): void
    {
        $response = $this->get('/api/docs');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response->assertSee('swagger-ui', false);
        $response->assertSee('/api/openapi.json', false);
    }
}
