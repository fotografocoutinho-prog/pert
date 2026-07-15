import { Router } from 'express';
import { asyncHandler } from '../../utils/asyncHandler.js';
import { authenticate, requireRole } from '../../middleware/auth.js';
import { authLimiter } from '../../middleware/rateLimit.js';
import { validate } from '../../middleware/validate.js';
import {
  createUserHandler,
  createUserSchema,
  loginHandler,
  loginSchema,
  logoutHandler,
  meHandler,
  refreshHandler,
  refreshSchema,
} from './auth.controller.js';

export const authRouter = Router();

authRouter.post('/login', authLimiter, validate(loginSchema), asyncHandler(loginHandler));
authRouter.post('/refresh', authLimiter, validate(refreshSchema), asyncHandler(refreshHandler));
authRouter.post('/logout', validate(refreshSchema), asyncHandler(logoutHandler));
authRouter.get('/me', authenticate, asyncHandler(meHandler));
authRouter.post(
  '/users',
  authenticate,
  requireRole('admin'),
  validate(createUserSchema),
  asyncHandler(createUserHandler),
);
