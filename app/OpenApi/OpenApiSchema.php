<?php

namespace App\OpenApi;

/**
 * Ported from reference/src/openapi.ts.
 *
 * A plain PHP array behind a static method (not a static JSON file) so it
 * stays next to, and typo-checked against, the controllers it describes —
 * the same "one source, no drift" reasoning the reference applies by
 * keeping the schema as a TS module rather than a checked-in JSON file.
 *
 * One deliberate divergence from the reference, called out in the phase
 * brief as a "fix, do not carry forward" defect: `/branches`'s `repo_url`
 * query parameter is `required: false` here. The reference marks it
 * `required: true` even though the endpoint (both here and in the
 * reference) explicitly handles an absent/empty `repo_url` by returning
 * `{"branches": []}` with a 200 — never a 400. See
 * App\Http\Controllers\Api\BranchesController::index().
 */
final class OpenApiSchema
{
    /**
     * @return array<string, mixed>
     */
    public static function toArray(): array
    {
        $errorRef = ['$ref' => '#/components/schemas/Error'];
        $errorResponses = [
            '401' => ['description' => 'Unauthorized', 'content' => ['application/json' => ['schema' => $errorRef]]],
            '503' => ['description' => 'API token not configured', 'content' => ['application/json' => ['schema' => $errorRef]]],
        ];

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'The Bridge Deployment API',
                'version' => '1.0.0',
                'description' => 'API for triggering and monitoring application deployments.',
            ],
            'servers' => [
                ['url' => '/api', 'description' => 'This server'],
            ],
            'components' => [
                'securitySchemes' => [
                    'BearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'description' => 'Set via BRIDGE_API_TOKEN env var or the api_token settings field.',
                    ],
                ],
                'schemas' => [
                    'AppSummary' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'name' => ['type' => 'string'],
                            'branch' => ['type' => 'string'],
                            'status' => ['type' => 'string'],
                            'repo_url' => ['type' => 'string'],
                        ],
                        'required' => ['id', 'name', 'branch', 'status', 'repo_url'],
                    ],
                    'DeploymentDetail' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'app_id' => ['type' => 'integer'],
                            'app_name' => ['type' => 'string'],
                            'status' => ['type' => 'string', 'enum' => ['pending', 'running', 'success', 'failed']],
                            'commit_sha' => ['type' => 'string', 'nullable' => true],
                            'commit_message' => ['type' => 'string', 'nullable' => true],
                            'started_at' => ['type' => 'string', 'nullable' => true],
                            'finished_at' => ['type' => 'string', 'nullable' => true],
                            'log_length' => ['type' => 'integer'],
                        ],
                        'required' => ['id', 'app_id', 'status', 'log_length'],
                    ],
                    'DeploymentQueued' => [
                        'type' => 'object',
                        'properties' => [
                            'deployment_id' => ['type' => 'integer'],
                            'app_id' => ['type' => 'integer'],
                            'status' => ['type' => 'string'],
                        ],
                        'required' => ['deployment_id', 'app_id', 'status'],
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => ['error' => ['type' => 'string']],
                        'required' => ['error'],
                    ],
                ],
            ],
            'paths' => [
                '/branches' => [
                    'get' => [
                        'summary' => 'List remote git branches',
                        'operationId' => 'listBranches',
                        'tags' => ['Git'],
                        'parameters' => [
                            [
                                'name' => 'repo_url',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'string'],
                                'description' => 'Remote git repository URL. Absent or empty returns an empty branch list rather than an error.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Branch list. Always 200 — a fetch failure is reported via the optional `error` key, not the status code.',
                                'content' => [
                                    'application/json' => [
                                        'schema' => [
                                            'type' => 'object',
                                            'properties' => [
                                                'branches' => ['type' => 'array', 'items' => ['type' => 'string']],
                                                'error' => ['type' => 'string'],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                '/apps' => [
                    'get' => [
                        'summary' => 'List all apps',
                        'operationId' => 'listApps',
                        'tags' => ['Apps'],
                        'security' => [['BearerAuth' => []]],
                        'responses' => [
                            '200' => [
                                'description' => 'Array of app summaries',
                                'content' => [
                                    'application/json' => [
                                        'schema' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/AppSummary']],
                                    ],
                                ],
                            ],
                            ...$errorResponses,
                        ],
                    ],
                ],
                '/apps/{id}/deploy' => [
                    'post' => [
                        'summary' => 'Trigger a deployment for an app',
                        'operationId' => 'deployApp',
                        'tags' => ['Deployments'],
                        'security' => [['BearerAuth' => []]],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'App ID'],
                        ],
                        'responses' => [
                            '202' => [
                                'description' => 'Deployment queued',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeploymentQueued']]],
                            ],
                            '404' => ['description' => 'App not found', 'content' => ['application/json' => ['schema' => $errorRef]]],
                            ...$errorResponses,
                        ],
                    ],
                ],
                '/deployments/{id}' => [
                    'get' => [
                        'summary' => 'Get deployment details',
                        'operationId' => 'getDeployment',
                        'tags' => ['Deployments'],
                        'security' => [['BearerAuth' => []]],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Deployment ID'],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Deployment record',
                                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/DeploymentDetail']]],
                            ],
                            '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => $errorRef]]],
                            ...$errorResponses,
                        ],
                    ],
                ],
                '/deployments/{id}/log' => [
                    'get' => [
                        'summary' => 'Stream deployment log text',
                        'operationId' => 'getDeploymentLog',
                        'tags' => ['Deployments'],
                        'security' => [['BearerAuth' => []]],
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer'], 'description' => 'Deployment ID'],
                            [
                                'name' => 'offset',
                                'in' => 'query',
                                'required' => false,
                                'schema' => ['type' => 'integer', 'default' => 0],
                                'description' => 'Offset to read the log from, for incremental polling. Negative or non-numeric values clamp to 0.',
                            ],
                        ],
                        'responses' => [
                            '200' => [
                                'description' => 'Log text chunk from offset',
                                'headers' => [
                                    'X-Log-Offset' => ['schema' => ['type' => 'integer'], 'description' => 'Total length of the log so far — pass this back as the next offset.'],
                                    'X-Deploy-Status' => ['schema' => ['type' => 'string'], 'description' => 'Current deployment status'],
                                    'X-Deploy-Done' => ['schema' => ['type' => 'string', 'enum' => ['true', 'false']], 'description' => 'Whether the deployment has reached a terminal state'],
                                ],
                                'content' => ['text/plain' => ['schema' => ['type' => 'string']]],
                            ],
                            '404' => ['description' => 'Not found', 'content' => ['application/json' => ['schema' => $errorRef]]],
                            ...$errorResponses,
                        ],
                    ],
                ],
            ],
        ];
    }
}
