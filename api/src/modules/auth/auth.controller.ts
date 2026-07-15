import type { Request, Response } from 'express';
import { z } from 'zod';
import * as authService from './auth.service.js';
import { getUserById } from './auth.service.js';

export const loginSchema = z.object({
  email: z.string().email(),
  password: z.string().min(1),
});

export const refreshSchema = z.object({
  refreshToken: z.string().min(1),
});

export const createUserSchema = z.object({
  email: z.string().email(),
  name: z.string().min(1),
  password: z.string().min(8),
  role: z.enum(['admin', 'operator', 'client']),
});

export async function loginHandler(req: Request, res: Response): Promise<void> {
  const { email, password } = req.body as z.infer<typeof loginSchema>;
  const result = await authService.login(email, password);
  res.json(result);
}

export async function refreshHandler(req: Request, res: Response): Promise<void> {
  const { refreshToken } = req.body as z.infer<typeof refreshSchema>;
  const result = await authService.refresh(refreshToken);
  res.json(result);
}

export async function logoutHandler(req: Request, res: Response): Promise<void> {
  const { refreshToken } = req.body as z.infer<typeof refreshSchema>;
  await authService.logout(refreshToken);
  res.status(204).end();
}

export async function meHandler(req: Request, res: Response): Promise<void> {
  const user = await getUserById(req.user!.sub);
  res.json(user);
}

export async function createUserHandler(req: Request, res: Response): Promise<void> {
  const user = await authService.createUser(req.body as z.infer<typeof createUserSchema>);
  res.status(201).json(user);
}
