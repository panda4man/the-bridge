import { getDb } from '../../src/db.js';
import * as AppModel from '../../src/models/app.js';
import * as DeploymentModel from '../../src/models/deployment.js';
import { AppStatus, DeploymentStatus } from '../../src/enums.js';

describe('App model', () => {
  test('create() inserts an app and returns it with id', () => {
    const app = AppModel.create({ name: 'Test App', repo_url: 'https://github.com/x/y.git', branch: 'main', path: '/tmp/test' });
    expect(app.id).toBeTruthy();
    expect(app.name).toBe('Test App');
    expect(app.status).toBe(AppStatus.Idle);
  });

  test('findById() returns the app', () => {
    const created = AppModel.create({ name: 'Find Me', repo_url: 'https://x.com/r.git', branch: 'main', path: '/tmp/find' });
    const found = AppModel.findById(created.id);
    expect(found!.name).toBe('Find Me');
  });

  test('findById() returns null for unknown id', () => {
    expect(AppModel.findById(9999)).toBeNull();
  });

  test('list() returns all apps newest first', () => {
    AppModel.create({ name: 'A', repo_url: 'r', branch: 'main', path: '/a' });
    AppModel.create({ name: 'B', repo_url: 'r', branch: 'main', path: '/b' });
    const apps = AppModel.list();
    expect(apps.length).toBeGreaterThanOrEqual(2);
    expect(apps[0].name).toBe('B');
  });

  test('update() changes fields and returns updated app', () => {
    const app = AppModel.create({ name: 'Old', repo_url: 'r', branch: 'main', path: '/old' });
    const updated = AppModel.update(app.id, { name: 'New', repo_url: 'r', branch: 'develop', path: '/new' });
    expect(updated!.name).toBe('New');
    expect(updated!.branch).toBe('develop');
  });

  test('updateStatus() changes status', () => {
    const app = AppModel.create({ name: 'Status', repo_url: 'r', branch: 'main', path: '/s' });
    AppModel.updateStatus(app.id, AppStatus.Deploying);
    expect(AppModel.findById(app.id)!.status).toBe(AppStatus.Deploying);
  });

  test('remove() deletes the app', () => {
    const app = AppModel.create({ name: 'Gone', repo_url: 'r', branch: 'main', path: '/gone' });
    AppModel.remove(app.id);
    expect(AppModel.findById(app.id)).toBeNull();
  });
});

describe('Deployment model', () => {
  test('create() inserts a deployment', () => {
    const app = AppModel.create({ name: 'App', repo_url: 'r', branch: 'main', path: '/app' });
    const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
    expect(dep.id).toBeTruthy();
    expect(dep.status).toBe(DeploymentStatus.Pending);
    expect(dep.app_id).toBe(app.id);
  });

  test('findById() includes app data', () => {
    const app = AppModel.create({ name: 'MyApp', repo_url: 'r', branch: 'main', path: '/myapp' });
    const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
    const found = DeploymentModel.findById(dep.id);
    expect(found!.app_name).toBe('MyApp');
  });

  test('appendLog() concatenates chunks', () => {
    const app = AppModel.create({ name: 'App', repo_url: 'r', branch: 'main', path: '/app2' });
    const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Running });
    DeploymentModel.appendLog(dep.id, 'line 1\n');
    DeploymentModel.appendLog(dep.id, 'line 2\n');
    const refreshed = DeploymentModel.findById(dep.id);
    expect(refreshed!.log).toBe('line 1\nline 2\n');
  });

  test('update() changes status and timestamps', () => {
    const app = AppModel.create({ name: 'App', repo_url: 'r', branch: 'main', path: '/app3' });
    const dep = DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
    DeploymentModel.update(dep.id, { status: DeploymentStatus.Success, finished_at: new Date().toISOString() });
    expect(DeploymentModel.findById(dep.id)!.status).toBe(DeploymentStatus.Success);
  });

  test('listForApp() returns deployments for an app newest first', () => {
    const app = AppModel.create({ name: 'App', repo_url: 'r', branch: 'main', path: '/app4' });
    DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Pending });
    DeploymentModel.create({ app_id: app.id, status: DeploymentStatus.Running });
    const deps = DeploymentModel.listForApp(app.id);
    expect(deps.length).toBe(2);
    expect(deps[0].status).toBe(DeploymentStatus.Running);
  });
});
