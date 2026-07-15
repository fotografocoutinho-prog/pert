import { createServer } from 'node:http';
import { createApp } from './app.js';
import { attachWebSocketServer } from './ws/server.js';
import { runMigrations } from './db/migrate.js';
import { adminPool, pool } from './db/pool.js';
import { mqttBridge } from './modules/integrations/mqtt.js';
import { env } from './config/env.js';
import { logger } from './utils/logger.js';

async function main(): Promise<void> {
  await runMigrations();

  await mqttBridge.start();

  const app = createApp();
  const server = createServer(app);
  attachWebSocketServer(server);

  server.listen(env.apiPort, () => {
    logger.info(`API listening on :${env.apiPort}`, {
      docs: `http://localhost:${env.apiPort}/docs`,
      env: env.nodeEnv,
    });
  });

  const shutdown = async (signal: string): Promise<void> => {
    logger.info(`Received ${signal}, shutting down`);
    server.close();
    await Promise.allSettled([pool.end(), adminPool.end()]);
    process.exit(0);
  };
  process.on('SIGINT', () => void shutdown('SIGINT'));
  process.on('SIGTERM', () => void shutdown('SIGTERM'));
}

main().catch((err) => {
  logger.error('Fatal startup error', { error: String(err) });
  process.exit(1);
});
