import { tmpdir } from 'node:os';
import { Router } from 'express';
import multer from 'multer';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import {
  deleteHandler,
  downloadHandler,
  getHandler,
  listHandler,
  uploadHandler,
} from './content.controller.js';

const upload = multer({
  dest: tmpdir(),
  limits: { fileSize: 2 * 1024 * 1024 * 1024 }, // 2 GB
});

export const contentRouter = Router();

contentRouter.use(authenticate);

contentRouter.get('/', asyncHandler(listHandler));
contentRouter.get('/:id', asyncHandler(getHandler));
contentRouter.get('/:id/download', asyncHandler(downloadHandler));
contentRouter.post('/', requireRole('admin', 'operator'), upload.single('file'), asyncHandler(uploadHandler));
contentRouter.delete('/:id', requireRole('admin', 'operator'), asyncHandler(deleteHandler));
