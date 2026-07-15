import type { NextFunction, Request, Response } from 'express';
import type { JwtPayload, UserRole } from '@signage/shared';
import { verifyAccessToken } from '../utils/jwt.js';
import { HttpError } from './error.js';

declare global {
  // eslint-disable-next-line @typescript-eslint/no-namespace
  namespace Express {
    interface Request {
      user?: JwtPayload;
    }
  }
}

export function authenticate(req: Request, _res: Response, next: NextFunction): void {
  const header = req.headers.authorization;
  if (!header?.startsWith('Bearer ')) {
    throw new HttpError(401, 'unauthorized', 'Missing or malformed Authorization header');
  }
  try {
    req.user = verifyAccessToken(header.slice('Bearer '.length));
    next();
  } catch {
    throw new HttpError(401, 'unauthorized', 'Invalid or expired access token');
  }
}

export function requireRole(...roles: UserRole[]) {
  return (req: Request, _res: Response, next: NextFunction): void => {
    if (!req.user) throw new HttpError(401, 'unauthorized', 'Not authenticated');
    if (!roles.includes(req.user.role)) {
      throw new HttpError(403, 'forbidden', 'Insufficient permissions');
    }
    next();
  };
}
