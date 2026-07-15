import { Router } from 'express';
import { z } from 'zod';
import type { Request, Response } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate } from '../../middleware/auth.js';
import { getPlayStats } from './stats.service.js';

const querySchema = z.object({
  from: z.string().datetime().optional(),
  to: z.string().datetime().optional(),
});

async function statsHandler(req: Request, res: Response): Promise<void> {
  const { from, to } = querySchema.parse(req.query);
  const toDate = to ? new Date(to) : new Date();
  const fromDate = from ? new Date(from) : new Date(toDate.getTime() - 7 * 24 * 3600 * 1000);
  res.json(await getPlayStats(fromDate, toDate));
}

export const statsRouter = Router();
statsRouter.use(authenticate);
statsRouter.get('/play', asyncHandler(statsHandler));
