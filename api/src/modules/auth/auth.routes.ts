import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { authLimiter } from '../../middleware/rateLimit.js';
import { validate } from '../../middleware/validate.js';
import {
  createUserHandler,
  createUserSchema,
  deleteUserHandler,
  listUsersHandler,
  loginHandler,
  loginSchema,
  logoutHandler,
  meHandler,
  refreshHandler,
  refreshSchema,
  updateUserHandler,
  updateUserSchema,
} from './auth.controller.js';

export const authRouter = Router();

authRouter.post('/login', authLimiter, validate(loginSchema), asyncHandler(loginHandler));
authRouter.post('/refresh', authLimiter, validate(refreshSchema), asyncHandler(refreshHandler));
authRouter.post('/logout', validate(refreshSchema), asyncHandler(logoutHandler));
authRouter.get('/me', authenticate, asyncHandler(meHandler));
authRouter.get('/users', authenticate, requireRole('admin'), asyncHandler(listUsersHandler));
authRouter.post(
  '/users',
  authenticate,
  requireRole('admin'),
  validate(createUserSchema),
  asyncHandler(createUserHandler),
);
authRouter.patch(
  '/users/:id',
  authenticate,
  requireRole('admin'),
  validate(updateUserSchema),
  asyncHandler(updateUserHandler),
);
authRouter.delete('/users/:id', authenticate, requireRole('admin'), asyncHandler(deleteUserHandler));
