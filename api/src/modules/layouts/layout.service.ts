import type { CreateLayoutInput, Layout, LayoutPreset, Zone } from '@signage/shared';
import { zonesForPreset } from '@signage/shared';
import { query } from '../../db/pool.js';
import { HttpError } from '../../middleware/error.js';

interface LayoutRow {
  id: string;
  name: string;
  preset: string;
  zones: Zone[];
  created_at: Date;
  updated_at: Date;
}

function toLayout(row: LayoutRow): Layout {
  return {
    id: row.id,
    name: row.name,
    preset: row.preset as LayoutPreset,
    zones: Array.isArray(row.zones) ? row.zones : [],
    createdAt: row.created_at.toISOString(),
    updatedAt: row.updated_at.toISOString(),
  };
}

export async function listLayouts(): Promise<Layout[]> {
  const { rows } = await query<LayoutRow>('SELECT * FROM layouts ORDER BY name ASC');
  return rows.map(toLayout);
}

export async function getLayout(id: string): Promise<Layout> {
  const { rows } = await query<LayoutRow>('SELECT * FROM layouts WHERE id = $1', [id]);
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Layout not found');
  return toLayout(rows[0]);
}

export async function createLayout(input: CreateLayoutInput): Promise<Layout> {
  const preset = input.preset ?? 'single';
  const zones = input.zones ?? zonesForPreset(preset);
  const { rows } = await query<LayoutRow>(
    `INSERT INTO layouts (name, preset, zones) VALUES ($1, $2, $3) RETURNING *`,
    [input.name, preset, JSON.stringify(zones)],
  );
  return toLayout(rows[0]);
}

export async function updateLayout(
  id: string,
  input: { name?: string; preset?: LayoutPreset; zones?: Zone[] },
): Promise<Layout> {
  const sets: string[] = [];
  const values: unknown[] = [];
  let i = 1;
  if (input.name !== undefined) {
    sets.push(`name = $${i++}`);
    values.push(input.name);
  }
  if (input.preset !== undefined) {
    sets.push(`preset = $${i++}`);
    values.push(input.preset);
  }
  if (input.zones !== undefined) {
    sets.push(`zones = $${i++}`);
    values.push(JSON.stringify(input.zones));
  }
  if (sets.length === 0) return getLayout(id);
  sets.push('updated_at = now()');
  values.push(id);
  const { rows } = await query<LayoutRow>(
    `UPDATE layouts SET ${sets.join(', ')} WHERE id = $${i} RETURNING *`,
    values,
  );
  if (rows.length === 0) throw new HttpError(404, 'not_found', 'Layout not found');
  return toLayout(rows[0]);
}

export async function deleteLayout(id: string): Promise<void> {
  const { rowCount } = await query('DELETE FROM layouts WHERE id = $1', [id]);
  if (!rowCount) throw new HttpError(404, 'not_found', 'Layout not found');
}
