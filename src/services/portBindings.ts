import { existsSync, readFileSync } from 'fs';
import { join } from 'path';
import yaml from 'js-yaml';
import type { PortBinding } from '../types.js';

type Protocol = 'tcp' | 'udp';

function normalizeProtocol(value: unknown): Protocol {
  return value === 'udp' ? 'udp' : 'tcp';
}

function parseShortForm(raw: string, service: string): PortBinding | null {
  const trimmed = raw.trim();
  if (trimmed.length === 0) return null;

  const [hostAndContainer, protoRaw] = trimmed.split('/');
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

  return { service, host_ip, host_port, container_port, protocol };
}

function parseLongForm(entry: Record<string, unknown>, service: string): PortBinding | null {
  const target = entry.target;
  if (target === undefined || target === null) return null;

  const published = entry.published;
  const host_ip = typeof entry.host_ip === 'string' ? entry.host_ip : undefined;

  return {
    service,
    host_ip,
    host_port: published === undefined || published === null ? undefined : String(published),
    container_port: String(target),
    protocol: normalizeProtocol(entry.protocol),
  };
}

function parseEntry(entry: unknown, service: string): PortBinding | null {
  if (typeof entry === 'string') return parseShortForm(entry, service);
  if (typeof entry === 'number') return parseShortForm(String(entry), service);
  if (entry && typeof entry === 'object') return parseLongForm(entry as Record<string, unknown>, service);
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

  const bindings: PortBinding[] = [];
  for (const [serviceName, serviceDef] of Object.entries(services as Record<string, unknown>)) {
    if (!serviceDef || typeof serviceDef !== 'object') continue;
    const ports = (serviceDef as Record<string, unknown>).ports;
    if (!Array.isArray(ports)) continue;
    for (const entry of ports) {
      const parsed = parseEntry(entry, serviceName);
      if (parsed) bindings.push(parsed);
    }
  }

  bindings.sort((a, b) => {
    if (a.service !== b.service) return a.service.localeCompare(b.service);
    return a.container_port.localeCompare(b.container_port, undefined, { numeric: true });
  });

  return bindings;
}
