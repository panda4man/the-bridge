import { AppStatus, DeploymentStatus } from '../../src/enums.js';

test('AppStatus has 4 expected values', () => {
  expect(Object.values(AppStatus)).toHaveLength(4);
  expect(AppStatus.Idle).toBe('idle');
  expect(AppStatus.Deploying).toBe('deploying');
  expect(AppStatus.Success).toBe('success');
  expect(AppStatus.Failed).toBe('failed');
});

test('DeploymentStatus has 4 expected values', () => {
  expect(Object.values(DeploymentStatus)).toHaveLength(4);
  expect(DeploymentStatus.Pending).toBe('pending');
  expect(DeploymentStatus.Running).toBe('running');
  expect(DeploymentStatus.Success).toBe('success');
  expect(DeploymentStatus.Failed).toBe('failed');
});
