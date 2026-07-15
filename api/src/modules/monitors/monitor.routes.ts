import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, authenticateFlexible, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import {
  commandHandler,
  commandSchema,
  createHandler,
  createMonitorSchema,
  deleteHandler,
  getHandler,
  listHandler,
  screenshotHandler,
  telemetryHandler,
  updateHandler,
  updateMonitorSchema,
} from './monitor.controller.js';

export const monitorRouter = Router();

// Screenshot is served to <img> elements, so it accepts a query token.
monitorRouter.get('/:id/screenshot', authenticateFlexible, asyncHandler(screenshotHandler));

monitorRouter.get('/', authenticate, asyncHandler(listHandler));
monitorRouter.get('/:id', authenticate, asyncHandler(getHandler));
monitorRouter.get('/:id/telemetry', authenticate, asyncHandler(telemetryHandler));
monitorRouter.post('/', authenticate, requireRole('admin', 'operator'), validate(createMonitorSchema), asyncHandler(createHandler));
monitorRouter.patch('/:id', authenticate, requireRole('admin', 'operator'), validate(updateMonitorSchema), asyncHandler(updateHandler));
monitorRouter.delete('/:id', authenticate, requireRole('admin'), asyncHandler(deleteHandler));
monitorRouter.post('/:id/command', authenticate, requireRole('admin', 'operator'), validate(commandSchema), asyncHandler(commandHandler));
