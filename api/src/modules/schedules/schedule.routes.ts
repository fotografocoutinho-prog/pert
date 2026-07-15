import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import {
  createHandler,
  createScheduleSchema,
  deleteHandler,
  getHandler,
  listHandler,
  updateHandler,
  updateScheduleSchema,
} from './schedule.controller.js';

export const scheduleRouter = Router();

scheduleRouter.use(authenticate);

scheduleRouter.get('/', asyncHandler(listHandler));
scheduleRouter.get('/:id', asyncHandler(getHandler));
scheduleRouter.post('/', requireRole('admin', 'operator'), validate(createScheduleSchema), asyncHandler(createHandler));
scheduleRouter.patch('/:id', requireRole('admin', 'operator'), validate(updateScheduleSchema), asyncHandler(updateHandler));
scheduleRouter.delete('/:id', requireRole('admin', 'operator'), asyncHandler(deleteHandler));
