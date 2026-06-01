import { Request, Response, NextFunction } from 'express';
import { timingSafeEqual } from 'crypto';
import * as SettingsModel from '../models/settings.js';

function resolveToken(): string | null {
  const fromEnv = (process.env.BRIDGE_API_TOKEN || '').trim();
  if (fromEnv) return fromEnv;
  const fromSettings = (SettingsModel.get('api_token') || '').trim();
  return fromSettings || null;
}

export function requireApiToken(req: Request, res: Response, next: NextFunction): void {
  const expected = resolveToken();
  if (!expected) {
    res.status(503).json({ error: 'API token not configured' });
    return;
  }

  const header = req.headers['authorization'] || '';
  const match = /^Bearer\s+(.+)$/.exec(Array.isArray(header) ? header[0] : header);
  if (!match) {
    res.status(401).json({ error: 'Missing or malformed Authorization header' });
    return;
  }

  const provided = match[1].trim();
  const expectedBuf = Buffer.from(expected);
  const providedBuf = Buffer.from(provided);
  const valid = expectedBuf.length === providedBuf.length &&
    timingSafeEqual(expectedBuf, providedBuf);
  if (!valid) {
    res.status(401).json({ error: 'Invalid token' });
    return;
  }

  next();
}
