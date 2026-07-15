import { Router } from 'express';
import { z } from 'zod';
import type { Request, Response } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import * as service from './update.service.js';

const createSchema = z.object({
  version: z.string().min(1),
  url: z.string().url(),
  checksum: z.string().min(1),
  notes: z.string().nullable().optional(),
});

const idSchema = z.object({ id: z.string().uuid() });

async function listHandler(_req: Request, res: Response): Promise<void> {
  res.json(await service.listReleases());
}

async function latestHandler(_req: Request, res: Response): Promise<void> {
  const manifest = await service.latestManifest();
  if (!manifest) {
    res.status(204).end();
    return;
  }
  res.json(manifest);
}

async function createHandler(req: Request, res: Response): Promise<void> {
  res.status(201).json(await service.createRelease(req.body as z.infer<typeof createSchema>));
}

async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deleteRelease(id);
  res.status(204).end();
}

export const updateRouter = Router();
updateRouter.use(authenticate);

updateRouter.get('/player/latest', asyncHandler(latestHandler));
updateRouter.get('/player', asyncHandler(listHandler));
updateRouter.post('/player', requireRole('admin'), validate(createSchema), asyncHandler(createHandler));
updateRouter.delete('/player/:id', requireRole('admin'), asyncHandler(deleteHandler));
