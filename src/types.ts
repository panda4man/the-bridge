export interface AppRecord {
  id: number;
  name: string;
  path: string;
  repo_url: string;
  branch: string;
  status: string;
  created_at: string;
  updated_at: string;
}

export interface DeploymentRecord {
  id: number;
  app_id: number;
  status: string;
  log: string | null;
  started_at: string | null;
  finished_at: string | null;
  created_at: string;
  updated_at: string;
  app_name?: string;
  app_path?: string;
  app_branch?: string;
  app_repo_url?: string;
  app_status?: string;
}

export interface PortBinding {
  service: string;
  host_ip?: string;
  host_port?: string;
  container_port: string;
  protocol: 'tcp' | 'udp';
}

export interface JobRecord {
  id: number;
  queue: string;
  payload: string;
  attempts: number;
  reserved_at: number | null;
  available_at: number;
  created_at: number;
}

declare global {
  namespace Express {
    interface Request {
      app_record?: AppRecord;
      fullPath?: string;
      skipClone?: boolean;
    }
  }
}
