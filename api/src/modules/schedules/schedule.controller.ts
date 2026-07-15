import type { Request, Response } from 'express';
import { z } from 'zod';
import * as service from './schedule.service.js';

const idSchema = z.object({ id: z.string().uuid() });

const timeRegex = /^([01]\d|2[0-3]):[0-5]\d$/;

export const createScheduleSchema = z
  .object({
    name: z.string().min(1),
    monitorId: z.string().uuid().nullable().optional(),
    groupName: z.string().nullable().optional(),
    playlistId: z.string().uuid(),
    priority: z.number().int().optional(),
    startDate: z.string().datetime().nullable().optional(),
    endDate: z.string().datetime().nullable().optional(),
    startTime: z.string().regex(timeRegex).nullable().optional(),
    endTime: z.string().regex(timeRegex).nullable().optional(),
    weekdays: z.array(z.number().int().min(0).max(6)).optional(),
    active: z.boolean().optional(),
  })
  .refine((v) => v.monitorId || v.groupName, {
    message: 'Either monitorId or groupName is required',
  });

export const updateScheduleSchema = z.object({
  name: z.string().min(1).optional(),
  monitorId: z.string().uuid().nullable().optional(),
  groupName: z.string().nullable().optional(),
  playlistId: z.string().uuid().optional(),
  priority: z.number().int().optional(),
  startDate: z.string().datetime().nullable().optional(),
  endDate: z.string().datetime().nullable().optional(),
  startTime: z.string().regex(timeRegex).nullable().optional(),
  endTime: z.string().regex(timeRegex).nullable().optional(),
  weekdays: z.array(z.number().int().min(0).max(6)).optional(),
  active: z.boolean().optional(),
});

export async function listHandler(_req: Request, res: Response): Promise<void> {
  res.json(await service.listSchedules());
}

export async function getHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.getSchedule(id));
}

export async function createHandler(req: Request, res: Response): Promise<void> {
  const body = req.body as z.infer<typeof createScheduleSchema>;
  res.status(201).json(await service.createSchedule(body as service.ScheduleInput));
}

export async function updateHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  res.json(await service.updateSchedule(id, req.body as Partial<service.ScheduleInput>));
}

export async function deleteHandler(req: Request, res: Response): Promise<void> {
  const { id } = idSchema.parse(req.params);
  await service.deleteSchedule(id);
  res.status(204).end();
}
