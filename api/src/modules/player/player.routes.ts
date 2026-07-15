import { Router } from 'express';
import { z } from 'zod';
import type { Request, Response } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate } from '../../middleware/auth.js';
import { resolvePlayerState } from './player.service.js';

const paramsSchema = z.object({ monitorId: z.string().uuid() });

async function stateHandler(req: Request, res: Response): Promise<void> {
  const { monitorId } = paramsSchema.parse(req.params);
  res.json(await resolvePlayerState(monitorId));
}

export const playerRouter = Router();

playerRouter.use(authenticate);
playerRouter.get('/:monitorId/state', asyncHandler(stateHandler));
