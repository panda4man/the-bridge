import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { mkdtempSync, writeFileSync, rmSync } from 'fs';
import { tmpdir } from 'os';
import { join } from 'path';
import { readPortBindings } from './portBindings.js';

let workDir: string;

beforeEach(() => {
  workDir = mkdtempSync(join(tmpdir(), 'port-bindings-'));
});

afterEach(() => {
  rmSync(workDir, { recursive: true, force: true });
});

function writeCompose(content: string): void {
  writeFileSync(join(workDir, 'docker-compose.yml'), content, 'utf-8');
}

describe('readPortBindings', () => {
  it('returns empty when docker-compose.yml is missing', () => {
    expect(readPortBindings(workDir)).toEqual([]);
  });

  it('returns empty when YAML is malformed', () => {
    writeCompose('services:\n  web:\n    ports: [::::\n');
    expect(readPortBindings(workDir)).toEqual([]);
  });

  it('parses short-form host:container', () => {
    writeCompose(`
services:
  web:
    image: nginx
    ports:
      - "8080:80"
`);
    expect(readPortBindings(workDir)).toEqual([
      { service: 'web', host_ip: undefined, host_port: '8080', container_port: '80', protocol: 'tcp' },
    ]);
  });

  it('parses short-form with host IP and udp protocol', () => {
    writeCompose(`
services:
  web:
    ports:
      - "127.0.0.1:8080:80/udp"
`);
    expect(readPortBindings(workDir)).toEqual([
      { service: 'web', host_ip: '127.0.0.1', host_port: '8080', container_port: '80', protocol: 'udp' },
    ]);
  });

  it('parses container-only short form', () => {
    writeCompose(`
services:
  web:
    ports:
      - "80"
`);
    expect(readPortBindings(workDir)).toEqual([
      { service: 'web', host_ip: undefined, host_port: undefined, container_port: '80', protocol: 'tcp' },
    ]);
  });

  it('parses container-only short form with protocol', () => {
    writeCompose(`
services:
  web:
    ports:
      - "53/udp"
`);
    expect(readPortBindings(workDir)).toEqual([
      { service: 'web', host_ip: undefined, host_port: undefined, container_port: '53', protocol: 'udp' },
    ]);
  });

  it('parses long-form object', () => {
    writeCompose(`
services:
  api:
    ports:
      - target: 8000
        published: 9000
        protocol: tcp
        host_ip: 0.0.0.0
        mode: host
`);
    expect(readPortBindings(workDir)).toEqual([
      { service: 'api', host_ip: '0.0.0.0', host_port: '9000', container_port: '8000', protocol: 'tcp' },
    ]);
  });

  it('handles multi-service compose and sorts deterministically', () => {
    writeCompose(`
services:
  web:
    ports:
      - "8080:80"
      - "8443:443"
  api:
    ports:
      - "9000:9000"
  db:
    image: postgres
`);
    const result = readPortBindings(workDir);
    expect(result).toEqual([
      { service: 'api', host_ip: undefined, host_port: '9000', container_port: '9000', protocol: 'tcp' },
      { service: 'web', host_ip: undefined, host_port: '8080', container_port: '80', protocol: 'tcp' },
      { service: 'web', host_ip: undefined, host_port: '8443', container_port: '443', protocol: 'tcp' },
    ]);
  });

  it('omits services with no ports key', () => {
    writeCompose(`
services:
  web:
    ports:
      - "8080:80"
  worker:
    image: alpine
`);
    const result = readPortBindings(workDir);
    expect(result).toHaveLength(1);
    expect(result[0].service).toBe('web');
  });

  it('returns empty when services key missing', () => {
    writeCompose('version: "3"\n');
    expect(readPortBindings(workDir)).toEqual([]);
  });
});
