import type { Request, Response } from 'express';
import { z } from 'zod';
import * as service from './layout.service.js';

const idSchema = z.object({ id: z.string().uuid() });

const zoneSchema = z.object({
  id: z.string(),
  kind: z.enum([
    'video',
    'image',
    'clock',
    'news',
    'rss',
    'html',
    'website',
    'youtube',
    'weather',
    'text',
  ]),
  x: z.number().min(0).max(100),
  y: z.number().min(0).max(100),
  width: z.number().min(0).max(100),
  height: z.number().min(0).max(100),
  config: z.record(z.unknown()).default({}),
  playlistId: z.string().uuid().nullable().default(null),
});

const presetSchema = z.enum(['single', 'two-zone', 'three-zone', 'four-zone', 'custom']);

export const createLayoutSchema = z.object({
  name: z.string().min(1),
  preset: presetSchema.optional(),
  zones: z.array(zoneSchema).optional(),
});

export const updateLayoutSchema = z.object({
  name: z.string().min(1).optional(),
  preset: presetSchema.optional(),
  zones: z.array(zoneSchema).optional(),
});

export async function listHandler(_req: Request, res: Response): Promise<void> {
  res.json(await service.listLayouts());
}

export async function getHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.getLayout(id));
}

export async function createHandler(req: Request, res: Response): Promise<void> {
  res.status(201).json(await service.createLayout(req.body as z.infer<typeof createLayoutSchema>));
}

export async function updateHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.updateLayout(id, req.body as z.infer<typeof updateLayoutSchema>));
}

export async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deleteLayout(id);
  res.status(204).end();
}
