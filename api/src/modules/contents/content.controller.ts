import type { Request, Response } from 'express';
import { z } from 'zod';
import * as service from './content.service.js';
import { storage } from './storage.js';
import { HttpError } from '../../middleware/error.js';

const idSchema = z.object({ id: z.string().uuid() });

export async function listHandler(_req: Request, res: Response): Promise<void> {
  res.json(await service.listContents());
}

export async function getHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.getContent(id));
}

export async function uploadHandler(req: Request, res: Response): Promise<void> {
  const file = req.file;
  if (!file) throw new HttpError(400, 'no_file', 'No file provided (field name: "file")');
  const content = await service.ingestUpload({
    originalName: file.originalname,
    mimeType: file.mimetype,
    tempPath: file.path,
  });
  res.status(201).json(content);
}

export async function downloadHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const content = await service.getContent(id);
  res.setHeader('Content-Type', content.mimeType);
  res.setHeader('X-Checksum', content.checksum);
  const stream = await storage.read(content.storageKey);
  stream.on('error', () => res.destroy());
  stream.pipe(res);
}

export async function thumbnailHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const content = await service.getContent(id);
  if (!content.thumbnailKey) {
    throw new HttpError(404, 'no_thumbnail', 'No thumbnail available for this content');
  }
  res.setHeader('Content-Type', 'image/webp');
  res.setHeader('Cache-Control', 'public, max-age=86400');
  const stream = await storage.read(content.thumbnailKey);
  stream.on('error', () => res.destroy());
  stream.pipe(res);
}

export async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deleteContent(id);
  res.status(204).end();
}
