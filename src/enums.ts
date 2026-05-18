export const AppStatus = Object.freeze({
  Idle: 'idle',
  Deploying: 'deploying',
  Success: 'success',
  Failed: 'failed',
} as const);

export type AppStatusValue = typeof AppStatus[keyof typeof AppStatus];

export const DeploymentStatus = Object.freeze({
  Pending: 'pending',
  Running: 'running',
  Success: 'success',
  Failed: 'failed',
} as const);

export type DeploymentStatusValue = typeof DeploymentStatus[keyof typeof DeploymentStatus];
