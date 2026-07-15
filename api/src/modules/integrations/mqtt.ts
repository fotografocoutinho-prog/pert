import type { MonitorTelemetry } from '@signage/shared';
import { env } from '../../config/env.js';
import { logger } from '../../utils/logger.js';

interface MqttClient {
  publish(topic: string, payload: string, opts?: { retain?: boolean }): void;
  end(): void;
  on(event: string, cb: (...args: unknown[]) => void): void;
}

/**
 * Optional MQTT bridge — publishes monitor status and telemetry, and announces
 * each screen to Home Assistant via MQTT discovery. Enabled only when MQTT_URL
 * is set; otherwise every method is a no-op. `mqtt` is loaded lazily so it is an
 * optional dependency.
 */
class MqttBridge {
  private client: MqttClient | null = null;
  private connected = false;
  private readonly announced = new Set<string>();

  async start(): Promise<void> {
    if (!env.mqtt.url) return;
    try {
      const mqtt = (await import('mqtt' as string)) as {
        connect(url: string): MqttClient;
      };
      this.client = mqtt.connect(env.mqtt.url);
      this.client.on('connect', () => {
        this.connected = true;
        logger.info('MQTT bridge connected', { url: env.mqtt.url });
      });
      this.client.on('error', (err) => logger.warn('MQTT error', { error: String(err) }));
    } catch (err) {
      logger.error('MQTT bridge init failed (is the `mqtt` package installed?)', {
        error: String(err),
      });
    }
  }

  private publish(topic: string, payload: unknown, retain = false): void {
    if (!this.client || !this.connected) return;
    this.client.publish(`${env.mqtt.topicPrefix}/${topic}`, JSON.stringify(payload), { retain });
  }

  publishStatus(monitorId: string, online: boolean): void {
    this.ensureDiscovery(monitorId);
    this.publish(`${monitorId}/status`, { online, at: new Date().toISOString() }, true);
  }

  publishTelemetry(monitorId: string, telemetry: MonitorTelemetry): void {
    this.publish(`${monitorId}/telemetry`, telemetry, true);
  }

  /** Publishes Home Assistant MQTT discovery configs once per monitor. */
  private ensureDiscovery(monitorId: string): void {
    if (!this.client || !this.connected || this.announced.has(monitorId)) return;
    this.announced.add(monitorId);
    const base = `${env.mqtt.topicPrefix}/${monitorId}`;
    const device = {
      identifiers: [`signage_${monitorId}`],
      name: `Signage ${monitorId.slice(0, 8)}`,
      manufacturer: 'Digital Signage',
      model: 'Player',
    };
    const sensors: { key: string; name: string; unit?: string; value: string }[] = [
      { key: 'cpu', name: 'CPU', unit: '%', value: '{{ value_json.cpuPercent }}' },
      { key: 'ram', name: 'RAM', unit: '%', value: '{{ value_json.ramPercent }}' },
      { key: 'temp', name: 'Temperature', unit: '°C', value: '{{ value_json.temperatureC }}' },
    ];
    for (const s of sensors) {
      const topic = `${env.mqtt.haDiscoveryPrefix}/sensor/signage_${monitorId}_${s.key}/config`;
      this.client.publish(
        topic,
        JSON.stringify({
          name: s.name,
          unique_id: `signage_${monitorId}_${s.key}`,
          state_topic: `${base}/telemetry`,
          unit_of_measurement: s.unit,
          value_template: s.value,
          device,
        }),
        { retain: true },
      );
    }
  }

  stop(): void {
    this.client?.end();
  }
}

export const mqttBridge = new MqttBridge();
