import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { validate } from '../../middleware/validate.js';
import {
  createHandler,
  createPlaylistSchema,
  deleteHandler,
  duplicateHandler,
  getHandler,
  itemsSchema,
  listHandler,
  setItemsHandler,
  updateHandler,
  updatePlaylistSchema,
} from './playlist.controller.js';

export const playlistRouter = Router();

playlistRouter.use(authenticate);

playlistRouter.get('/', asyncHandler(listHandler));
playlistRouter.get('/:id', asyncHandler(getHandler));
playlistRouter.post('/', requireRole('admin', 'operator'), validate(createPlaylistSchema), asyncHandler(createHandler));
playlistRouter.patch('/:id', requireRole('admin', 'operator'), validate(updatePlaylistSchema), asyncHandler(updateHandler));
playlistRouter.put('/:id/items', requireRole('admin', 'operator'), validate(itemsSchema), asyncHandler(setItemsHandler));
playlistRouter.post('/:id/duplicate', requireRole('admin', 'operator'), asyncHandler(duplicateHandler));
playlistRouter.delete('/:id', requireRole('admin', 'operator'), asyncHandler(deleteHandler));
