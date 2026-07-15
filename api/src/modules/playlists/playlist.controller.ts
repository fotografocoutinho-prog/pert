import type { Request, Response } from 'express';
import { z } from 'zod';
import * as service from './playlist.service.js';

const idSchema = z.object({ id: z.string().uuid() });

export const createPlaylistSchema = z.object({
  name: z.string().min(1),
  loop: z.boolean().optional(),
  shuffle: z.boolean().optional(),
  priority: z.number().int().optional(),
  startDate: z.string().datetime().nullable().optional(),
  endDate: z.string().datetime().nullable().optional(),
});

export const updatePlaylistSchema = createPlaylistSchema.partial().extend({
  active: z.boolean().optional(),
});

export const itemsSchema = z.object({
  items: z.array(
    z.object({
      contentId: z.string().uuid(),
      durationSeconds: z.number().positive().optional(),
      scaleMode: z.enum(['fit', 'fill', 'stretch']).optional(),
      transition: z.enum(['none', 'fade', 'slide', 'zoom', 'crossfade']).optional(),
    }),
  ),
});

export async function listHandler(_req: Request, res: Response): Promise<void> {
  res.json(await service.listPlaylists());
}

export async function getHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.getPlaylist(id));
}

export async function createHandler(req: Request, res: Response): Promise<void> {
  const playlist = await service.createPlaylist(req.body as z.infer<typeof createPlaylistSchema>);
  res.status(201).json(playlist);
}

export async function updateHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.updatePlaylist(id, req.body as z.infer<typeof updatePlaylistSchema>));
}

export async function setItemsHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const { items } = req.body as z.infer<typeof itemsSchema>;
  res.json(await service.setItems(id, items));
}

export async function duplicateHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.status(201).json(await service.duplicatePlaylist(id));
}

export async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deletePlaylist(id);
  res.status(204).end();
}
