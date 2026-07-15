import type { NextFunction, Request, Response } from 'express';
import { ZodError } from 'zod';
import { logger } from '../utils/logger.js';

export class HttpError extends Error {
  constructor(
    public statusCode: number,
    public code: string,
    message: string,
    public details?: unknown,
  ) {
    super(message);
  }
}

export const notFound = (_req: Request, res: Response): void => {
  res.status(404).json({ error: 'not_found', message: 'Resource not found' });
};

export const errorHandler = (
  err: unknown,
  _req: Request,
  res: Response,
  _next: NextFunction,
): void => {
  if (err instanceof ZodError) {
    res.status(422).json({
      error: 'validation_error',
      message: 'Invalid request payload',
      details: err.flatten(),
    });
    return;
  }
  if (err instanceof HttpError) {
    res.status(err.statusCode).json({
      error: err.code,
      message: err.message,
      details: err.details,
    });
    return;
  }
  logger.error('Unhandled error', { error: String(err) });
  res.status(500).json({ error: 'internal_error', message: 'Internal server error' });
};
