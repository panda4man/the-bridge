const schema = {
  openapi: '3.0.3',
  info: {
    title: 'The Bridge Deployment API',
    version: '1.0.0',
    description: 'API for triggering and monitoring application deployments.',
  },
  servers: [{ url: '/api', description: 'This server' }],
  components: {
    securitySchemes: {
      BearerAuth: {
        type: 'http',
        scheme: 'bearer',
        description: 'Set via BRIDGE_API_TOKEN env var or the api_token settings field.',
      },
    },
    schemas: {
      AppSummary: {
        type: 'object',
        properties: {
          id:       { type: 'integer' },
          name:     { type: 'string' },
          branch:   { type: 'string' },
          status:   { type: 'string' },
          repo_url: { type: 'string' },
        },
        required: ['id', 'name', 'branch', 'status', 'repo_url'],
      },
      DeploymentDetail: {
        type: 'object',
        properties: {
          id:              { type: 'integer' },
          app_id:          { type: 'integer' },
          app_name:        { type: 'string' },
          status:          { type: 'string', enum: ['pending', 'running', 'success', 'failed'] },
          commit_sha:      { type: 'string', nullable: true },
          commit_message:  { type: 'string', nullable: true },
          started_at:      { type: 'string', nullable: true },
          finished_at:     { type: 'string', nullable: true },
          log_length:      { type: 'integer' },
        },
        required: ['id', 'app_id', 'status', 'log_length'],
      },
      DeploymentQueued: {
        type: 'object',
        properties: {
          deployment_id: { type: 'integer' },
          app_id:        { type: 'integer' },
          status:        { type: 'string' },
        },
        required: ['deployment_id', 'app_id', 'status'],
      },
      Error: {
        type: 'object',
        properties: { error: { type: 'string' } },
        required: ['error'],
      },
    },
  },
  paths: {
    '/branches': {
      get: {
        summary: 'List remote git branches',
        operationId: 'listBranches',
        tags: ['Git'],
        parameters: [
          {
            name: 'repo_url',
            in: 'query',
            required: true,
            schema: { type: 'string' },
            description: 'Remote git repository URL',
          },
        ],
        responses: {
          '200': {
            description: 'Branch list',
            content: {
              'application/json': {
                schema: {
                  type: 'object',
                  properties: {
                    branches: { type: 'array', items: { type: 'string' } },
                  },
                },
              },
            },
          },
        },
      },
    },
    '/apps': {
      get: {
        summary: 'List all apps',
        operationId: 'listApps',
        tags: ['Apps'],
        security: [{ BearerAuth: [] }],
        responses: {
          '200': {
            description: 'Array of app summaries',
            content: {
              'application/json': {
                schema: { type: 'array', items: { $ref: '#/components/schemas/AppSummary' } },
              },
            },
          },
          '401': { description: 'Unauthorized', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '503': { description: 'API token not configured', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
        },
      },
    },
    '/apps/{id}/deploy': {
      post: {
        summary: 'Trigger a deployment for an app',
        operationId: 'deployApp',
        tags: ['Deployments'],
        security: [{ BearerAuth: [] }],
        parameters: [
          { name: 'id', in: 'path', required: true, schema: { type: 'integer' }, description: 'App ID' },
        ],
        responses: {
          '202': {
            description: 'Deployment queued',
            content: { 'application/json': { schema: { $ref: '#/components/schemas/DeploymentQueued' } } },
          },
          '401': { description: 'Unauthorized', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '404': { description: 'App not found', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '503': { description: 'API token not configured', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
        },
      },
    },
    '/deployments/{id}': {
      get: {
        summary: 'Get deployment details',
        operationId: 'getDeployment',
        tags: ['Deployments'],
        security: [{ BearerAuth: [] }],
        parameters: [
          { name: 'id', in: 'path', required: true, schema: { type: 'integer' }, description: 'Deployment ID' },
        ],
        responses: {
          '200': {
            description: 'Deployment record',
            content: { 'application/json': { schema: { $ref: '#/components/schemas/DeploymentDetail' } } },
          },
          '401': { description: 'Unauthorized', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '404': { description: 'Not found', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '503': { description: 'API token not configured', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
        },
      },
    },
    '/deployments/{id}/log': {
      get: {
        summary: 'Stream deployment log text',
        operationId: 'getDeploymentLog',
        tags: ['Deployments'],
        security: [{ BearerAuth: [] }],
        parameters: [
          { name: 'id', in: 'path', required: true, schema: { type: 'integer' }, description: 'Deployment ID' },
          { name: 'offset', in: 'query', required: false, schema: { type: 'integer', default: 0 }, description: 'Byte offset to read from (for incremental polling)' },
        ],
        responses: {
          '200': {
            description: 'Log text chunk from offset',
            headers: {
              'X-Log-Offset':    { schema: { type: 'integer' }, description: 'Total bytes written so far' },
              'X-Deploy-Status': { schema: { type: 'string' }, description: 'Current deployment status' },
              'X-Deploy-Done':   { schema: { type: 'string', enum: ['true', 'false'] }, description: 'Whether the deployment has reached a terminal state' },
            },
            content: { 'text/plain': { schema: { type: 'string' } } },
          },
          '401': { description: 'Unauthorized', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '404': { description: 'Not found', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
          '503': { description: 'API token not configured', content: { 'application/json': { schema: { $ref: '#/components/schemas/Error' } } } },
        },
      },
    },
  },
} as const;

export default schema;
