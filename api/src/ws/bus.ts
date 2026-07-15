import type { ServerToPlayer } from '@signage/shared';
import { env } from '../config/env.js';
import { logger } from '../utils/logger.js';

export interface BusMessage {
  monitorId: string;
  message: ServerToPlayer;
}

/**
 * Fan-out channel used so a command reaches whichever API node holds a given
 * player's socket. In-memory for a single node; Redis pub/sub across a cluster.
 */
export interface MessageBus {
  /** True when messages can reach other nodes (Redis). */
  readonly distributed: boolean;
  publish(msg: BusMessage): void;
  onMessage(handler: (msg: BusMessage) => void): void;
}

class InMemoryBus implements MessageBus {
  readonly distributed = false;
  private handler: ((msg: BusMessage) => void) | null = null;
  publish(_msg: BusMessage): void {
    // Single node: local delivery is handled directly by the hub.
  }
  onMessage(handler: (msg: BusMessage) => void): void {
    this.handler = handler;
    void this.handler;
  }
}

const CHANNEL = 'signage:player-commands';

class RedisBus implements MessageBus {
  readonly distributed = true;
  private pub: unknown;
  private handler: ((msg: BusMessage) => void) | null = null;

  constructor(private readonly url: string) {
    void this.init();
  }

  private async init(): Promise<void> {
    try {
      const { default: Redis } = (await import('ioredis' as string)) as {
        default: new (url: string) => {
          publish(ch: string, msg: string): void;
          subscribe(ch: string): Promise<unknown>;
          on(ev: string, cb: (ch: string, msg: string) => void): void;
          duplicate(): unknown;
        };
      };
      this.pub = new Redis(this.url);
      const sub = new Redis(this.url);
      await sub.subscribe(CHANNEL);
      sub.on('message', (_ch, raw) => {
        try {
          this.handler?.(JSON.parse(raw) as BusMessage);
        } catch {
          /* ignore malformed */
        }
      });
      logger.info('Redis message bus connected', { url: this.url });
    } catch (err) {
      logger.error('Redis bus init failed; falling back to local delivery', {
        error: String(err),
      });
    }
  }

  publish(msg: BusMessage): void {
    (this.pub as { publish?: (ch: string, m: string) => void } | undefined)?.publish?.(
      CHANNEL,
      JSON.stringify(msg),
    );
  }

  onMessage(handler: (msg: BusMessage) => void): void {
    this.handler = handler;
  }
}

export const bus: MessageBus = env.redisUrl ? new RedisBus(env.redisUrl) : new InMemoryBus();
