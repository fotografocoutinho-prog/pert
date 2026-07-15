import type { NextFunction, Request, Response } from 'express';
import type { ZodTypeAny, infer as ZodInfer } from 'zod';

type Source = 'body' | 'query' | 'params';

/**
 * Validates a request part against a Zod schema and replaces it with the
 * parsed (and typed) value. Throws ZodError on failure — handled centrally.
 */
export function validate<S extends ZodTypeAny>(schema: S, source: Source = 'body') {
  return (req: Request, _res: Response, next: NextFunction): void => {
    const parsed = schema.parse(req[source]) as ZodInfer<S>;
    // Reassign the parsed value so downstream handlers get typed data.
    (req as Record<Source, unknown>)[source] = parsed;
    next();
  };
}
