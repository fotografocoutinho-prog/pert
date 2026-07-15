import type { Request, Response } from 'express';
import { z } from 'zod';
import type { MonitorCommand } from '@signage/shared';
import * as service from './monitor.service.js';
import { getLatestScreenshot } from './screenshot.service.js';
import { writeLog } from '../audit/audit.service.js';
import { assertScreenQuota } from '../tenants/tenant.service.js';
import { hub } from '../../ws/hub.js';
import { HttpError } from '../../middleware/error.js';

export const createMonitorSchema = z.object({
  name: z.string().min(1),
  groupName: z.string().nullable().optional(),
  location: z.string().nullable().optional(),
  resolution: z.string().nullable().optional(),
  orientation: z.enum(['landscape', 'portrait']).optional(),
});

export const updateMonitorSchema = createMonitorSchema.partial().extend({
  layoutId: z.string().uuid().nullable().optional(),
  playlistId: z.string().uuid().nullable().optional(),
});

export const commandSchema = z.object({
  command: z.enum([
    'restart',
    'shutdown',
    'clear_cache',
    'screenshot',
    'update_content',
    'update_player',
  ]),
  payload: z.unknown().optional(),
});

const idSchema = z.object({ id: z.string().uuid() });

/** Enrich stored status with live socket presence. */
function withPresence<T extends { id: string; status: string }>(m: T): T {
  return { ...m, status: hub.isOnline(m.id) ? 'online' : 'offline' };
}

export async function listHandler(_req: Request, res: Response): Promise<void> {
  const monitors = await service.listMonitors();
  res.json(monitors.map(withPresence));
}

export async function getHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const monitor = await service.getMonitor(id);
  res.json(withPresence(monitor));
}

export async function createHandler(req: Request, res: Response): Promise<void> {
  await assertScreenQuota(req.user!.tenantId);
  const monitor = await service.createMonitor(req.body as z.infer<typeof createMonitorSchema>);
  res.status(201).json(monitor);
}

export async function updateHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const monitor = await service.updateMonitor(id, req.body as z.infer<typeof updateMonitorSchema>);
  hub.send(id, { type: 'sync', reason: 'playlist' });
  res.json(withPresence(monitor));
}

export async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deleteMonitor(id);
  res.status(204).end();
}

export async function commandHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const { command, payload } = req.body as z.infer<typeof commandSchema>;
  await service.getMonitor(id); // 404 if missing
  const { commandId, delivered } = hub.sendCommand(id, command as MonitorCommand, payload);
  await writeLog({
    userId: req.user?.sub ?? null,
    monitorId: id,
    action: `monitor.command.${command}`,
    level: delivered ? 'info' : 'warn',
    detail: { commandId, delivered },
  });
  if (!delivered) {
    throw new HttpError(409, 'offline', 'Monitor is offline; command not delivered');
  }
  res.status(202).json({ commandId, delivered });
}

export async function telemetryHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.getMonitor(id);
  const samples = await service.listTelemetry(id);
  res.json({ latest: samples[0] ?? null, samples });
}

export async function screenshotHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  const shot = await getLatestScreenshot(id);
  res.setHeader('Content-Type', shot.mimeType);
  res.setHeader('X-Captured-At', shot.createdAt);
  res.setHeader('Cache-Control', 'no-store');
  shot.stream.on('error', () => res.destroy());
  shot.stream.pipe(res);
}
