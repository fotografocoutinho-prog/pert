import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import {
  createHandler,
  createLayoutSchema,
  deleteHandler,
  getHandler,
  listHandler,
  updateHandler,
  updateLayoutSchema,
} from './layout.controller.js';

export const layoutRouter = Router();

layoutRouter.use(authenticate);

layoutRouter.get('/', asyncHandler(listHandler));
layoutRouter.get('/:id', asyncHandler(getHandler));
layoutRouter.post('/', requireRole('admin', 'operator'), validate(createLayoutSchema), asyncHandler(createHandler));
layoutRouter.patch('/:id', requireRole('admin', 'operator'), validate(updateLayoutSchema), asyncHandler(updateHandler));
layoutRouter.delete('/:id', requireRole('admin', 'operator'), asyncHandler(deleteHandler));
