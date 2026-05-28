import { existsSync, readFileSync } from 'fs';
import { join } from 'path';
import yaml from 'js-yaml';
import type { PortBinding } from '../types.js';

type Protocol = 'tcp' | 'udp';

function normalizeProtocol(value: unknown): Protocol {
  return value === 'udp' ? 'udp' : 'tcp';
}

function readDotenv(appPath: string): Record<string, string> {
  const envPath = join(appPath, '.env');
  if (!existsSync(envPath)) return {};

  let raw: string;
  try {
    raw = readFileSync(envPath, 'utf-8');
  } catch {
    return {};
  }

  const result: Record<string, string> = {};
  for (const line of raw.split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) continue;
    const eq = trimmed.indexOf('=');
    if (eq < 0) continue;
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"')) ||
      (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    if (key) result[key] = value;
  }
  return result;
}

// Compose-style interpolation: ${VAR}, ${VAR:-default}, ${VAR-default},
// ${VAR:+alt}, ${VAR+alt}, ${VAR:?err}, ${VAR?err}, and $VAR.
function interpolate(input: string, env: Record<string, string>): string {
  const braced = input.replace(
    /\$\{([A-Za-z_][A-Za-z0-9_]*)(?:(:?)([-+?])([^}]*))?\}/g,
    (_match, name: string, colon: string, op: string | undefined, arg: string) => {
      const val = env[name];
      const isSet = val !== undefined;
      const usable = colon === ':' ? isSet && val !== '' : isSet;
      if (!op) return val ?? '';
      if (op === '-') return usable ? (val as string) : arg;
      if (op === '+') return usable ? arg : '';
      if (op === '?') return usable ? (val as string) : '';
      return val ?? '';
    }
  );
  return braced.replace(/\$([A-Za-z_][A-Za-z0-9_]*)/g, (_m, name: string) => env[name] ?? '');
}

function interpolateIfString(value: unknown, env: Record<string, string>): unknown {
  return typeof value === 'string' ? interpolate(value, env) : value;
}

function parseShortForm(raw: string, service: string, env: Record<string, string>): PortBinding | null {
  const resolved = interpolate(raw, env).trim();
  if (resolved.length === 0) return null;

  const [hostAndContainer, protoRaw] = resolved.split('/');
  const protocol = normalizeProtocol(protoRaw);
  const segments = hostAndContainer.split(':');

  let host_ip: string | undefined;
  let host_port: string | undefined;
  let container_port: string | undefined;

  if (segments.length === 1) {
    container_port = segments[0];
  } else if (segments.length === 2) {
    host_port = segments[0];
    container_port = segments[1];
  } else if (segments.length === 3) {
    host_ip = segments[0];
    host_port = segments[1];
    container_port = segments[2];
  } else {
    return null;
  }

  if (!container_port) return null;
  if (host_port === '') host_port = undefined;
  if (host_ip === '') host_ip = undefined;

  return { service, host_ip, host_port, container_port, protocol };
}

function parseLongForm(
  entry: Record<string, unknown>,
  service: string,
  env: Record<string, string>
): PortBinding | null {
  const target = interpolateIfString(entry.target, env);
  if (target === undefined || target === null || target === '') return null;

  const published = interpolateIfString(entry.published, env);
  const host_ip_val = interpolateIfString(entry.host_ip, env);
  const protocol_val = interpolateIfString(entry.protocol, env);

  return {
    service,
    host_ip: typeof host_ip_val === 'string' && host_ip_val !== '' ? host_ip_val : undefined,
    host_port:
      published === undefined || published === null || published === ''
        ? undefined
        : String(published),
    container_port: String(target),
    protocol: normalizeProtocol(protocol_val),
  };
}

function parseEntry(entry: unknown, service: string, env: Record<string, string>): PortBinding | null {
  if (typeof entry === 'string') return parseShortForm(entry, service, env);
  if (typeof entry === 'number') return parseShortForm(String(entry), service, env);
  if (entry && typeof entry === 'object') return parseLongForm(entry as Record<string, unknown>, service, env);
  return null;
}

export function readPortBindings(appPath: string): PortBinding[] {
  const composePath = join(appPath, 'docker-compose.yml');
  if (!existsSync(composePath)) return [];

  let raw: string;
  try {
    raw = readFileSync(composePath, 'utf-8');
  } catch {
    return [];
  }

  let doc: unknown;
  try {
    doc = yaml.load(raw);
  } catch {
    return [];
  }

  if (!doc || typeof doc !== 'object') return [];
  const services = (doc as Record<string, unknown>).services;
  if (!services || typeof services !== 'object') return [];

  const env = readDotenv(appPath);
  const bindings: PortBinding[] = [];
  for (const [serviceName, serviceDef] of Object.entries(services as Record<string, unknown>)) {
    if (!serviceDef || typeof serviceDef !== 'object') continue;
    const ports = (serviceDef as Record<string, unknown>).ports;
    if (!Array.isArray(ports)) continue;
    for (const entry of ports) {
      const parsed = parseEntry(entry, serviceName, env);
      if (parsed) bindings.push(parsed);
    }
  }

  bindings.sort((a, b) => {
    if (a.service !== b.service) return a.service.localeCompare(b.service);
    return a.container_port.localeCompare(b.container_port, undefined, { numeric: true });
  });

  return bindings;
}
