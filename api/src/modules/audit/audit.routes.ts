import { Router } from 'express';
import { z } from 'zod';
import type { Request, Response } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate } from '../../middleware/auth.js';
import { listLogs } from './audit.service.js';

const querySchema = z.object({
  monitorId: z.string().uuid().optional(),
  level: z.enum(['info', 'warn', 'error']).optional(),
  limit: z.coerce.number().int().min(1).max(200).default(50),
  offset: z.coerce.number().int().min(0).default(0),
});

async function listHandler(req: Request, res: Response): Promise<void> {
  const { monitorId, level, limit, offset } = querySchema.parse(req.query);
  const { items, total } = await listLogs({ monitorId, level, limit, offset });
  res.json({ items, total, limit, offset });
}

export const auditRouter = Router();
auditRouter.use(authenticate);
auditRouter.get('/', asyncHandler(listHandler));
