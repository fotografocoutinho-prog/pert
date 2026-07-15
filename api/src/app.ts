import express, { type Express } from 'express';
import cors from 'cors';
import helmet from 'helmet';
import swaggerUi from 'swagger-ui-express';
import { env } from './config/env.js';
import { apiLimiter } from './middleware/rateLimit.js';
import { errorHandler, notFound } from './middleware/error.js';
import { authRouter } from './modules/auth/auth.routes.js';
import { monitorRouter } from './modules/monitors/monitor.routes.js';
import { contentRouter } from './modules/contents/content.routes.js';
import { playlistRouter } from './modules/playlists/playlist.routes.js';
import { dashboardRouter } from './modules/dashboard/dashboard.routes.js';
import { openApiDocument } from './docs/swagger.js';

export function createApp(): Express {
  const app = express();

  app.use(helmet({ crossOriginResourcePolicy: { policy: 'cross-origin' } }));
  app.use(cors({ origin: env.corsOrigin, credentials: true }));
  app.use(express.json({ limit: '1mb' }));
  app.use(express.urlencoded({ extended: true }));

  app.get('/health', (_req, res) => {
    res.json({ status: 'ok', time: new Date().toISOString() });
  });

  app.use('/api', apiLimiter);
  app.use('/api/auth', authRouter);
  app.use('/api/monitors', monitorRouter);
  app.use('/api/contents', contentRouter);
  app.use('/api/playlists', playlistRouter);
  app.use('/api/dashboard', dashboardRouter);

  app.use('/docs', swaggerUi.serve, swaggerUi.setup(openApiDocument));
  app.get('/openapi.json', (_req, res) => res.json(openApiDocument));

  app.use(notFound);
  app.use(errorHandler);

  return app;
}
