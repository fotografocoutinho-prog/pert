import { tmpdir } from 'node:os';
import { Router } from 'express';
import multer from 'multer';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticateFlexible, requireRole } from '../../middleware/auth.js';
import {
  deleteHandler,
  downloadHandler,
  getHandler,
  listHandler,
  thumbnailHandler,
  uploadHandler,
} from './content.controller.js';

const upload = multer({
  dest: tmpdir(),
  limits: { fileSize: 2 * 1024 * 1024 * 1024 }, // 2 GB
});

export const contentRouter = Router();

contentRouter.use(authenticateFlexible);

contentRouter.get('/', asyncHandler(listHandler));
contentRouter.get('/:id', asyncHandler(getHandler));
contentRouter.get('/:id/download', asyncHandler(downloadHandler));
contentRouter.get('/:id/thumbnail', asyncHandler(thumbnailHandler));
contentRouter.post('/', requireRole('admin', 'operator'), upload.single('file'), asyncHandler(uploadHandler));
contentRouter.delete('/:id', requireRole('admin', 'operator'), asyncHandler(deleteHandler));
