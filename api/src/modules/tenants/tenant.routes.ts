import { Router } from 'express';
import { z } from 'zod';
import type { Request, Response } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import { getLicense, getTenant, provisionTenant } from './tenant.service.js';

const provisionSchema = z.object({
  name: z.string().min(1),
  plan: z.enum(['free', 'pro', 'enterprise']).optional(),
  adminEmail: z.string().email(),
  adminName: z.string().min(1),
  adminPassword: z.string().min(8),
});

async function meHandler(req: Request, res: Response): Promise<void> {
  res.json(await getTenant(req.user!.tenantId));
}

async function licenseHandler(req: Request, res: Response): Promise<void> {
  res.json(await getLicense(req.user!.tenantId));
}

async function provisionHandler(req: Request, res: Response): Promise<void> {
  const result = await provisionTenant(req.body as z.infer<typeof provisionSchema>);
  res.status(201).json(result);
}

export const tenantRouter = Router();
tenantRouter.use(authenticate);

tenantRouter.get('/me', asyncHandler(meHandler));
tenantRouter.get('/license', asyncHandler(licenseHandler));
// Provisioning a new organization is an admin action.
tenantRouter.post('/', requireRole('admin'), validate(provisionSchema), asyncHandler(provisionHandler));
