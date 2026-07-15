import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import {
  commandHandler,
  commandSchema,
  createHandler,
  createMonitorSchema,
  deleteHandler,
  getHandler,
  listHandler,
  updateHandler,
  updateMonitorSchema,
} from './monitor.controller.js';

export const monitorRouter = Router();

monitorRouter.use(authenticate);

monitorRouter.get('/', asyncHandler(listHandler));
monitorRouter.get('/:id', asyncHandler(getHandler));
monitorRouter.post('/', requireRole('admin', 'operator'), validate(createMonitorSchema), asyncHandler(createHandler));
monitorRouter.patch('/:id', requireRole('admin', 'operator'), validate(updateMonitorSchema), asyncHandler(updateHandler));
monitorRouter.delete('/:id', requireRole('admin'), asyncHandler(deleteHandler));
monitorRouter.post('/:id/command', requireRole('admin', 'operator'), validate(commandSchema), asyncHandler(commandHandler));
