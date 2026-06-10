import request from 'supertest';

async function loadApp() {
  const { default: app } = await import('../../src/app.js');
  return app;
}

describe('GET /api/openapi.json', () => {
  it('returns 200 with valid OpenAPI structure (no auth required)', async () => {
    const app = await loadApp();
    const res = await request(app).get('/api/openapi.json');

    expect(res.status).toBe(200);
    expect(res.body.openapi).toBe('3.0.3');
    expect(res.body.info.title).toBeTruthy();
    expect(res.body.paths).toBeDefined();
  });

  it('includes all expected API paths', async () => {
    const app = await loadApp();
    const res = await request(app).get('/api/openapi.json');

    const paths = Object.keys(res.body.paths);
    expect(paths).toContain('/branches');
    expect(paths).toContain('/apps');
    expect(paths).toContain('/apps/{id}/deploy');
    expect(paths).toContain('/deployments/{id}');
    expect(paths).toContain('/deployments/{id}/log');
  });

  it('documents BearerAuth security scheme', async () => {
    const app = await loadApp();
    const res = await request(app).get('/api/openapi.json');

    expect(res.body.components.securitySchemes.BearerAuth).toBeDefined();
    expect(res.body.components.securitySchemes.BearerAuth.scheme).toBe('bearer');
  });
});

describe('GET /api/docs', () => {
  it('returns 200 with HTML containing Swagger UI', async () => {
    const app = await loadApp();
    const res = await request(app).get('/api/docs');

    expect(res.status).toBe(200);
    expect(res.headers['content-type']).toContain('text/html');
    expect(res.text).toContain('swagger-ui');
    expect(res.text).toContain('/api/openapi.json');
  });
});
