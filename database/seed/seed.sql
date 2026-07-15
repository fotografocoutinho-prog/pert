-- Deterministic demo data. The default admin user is created by the API seed
-- script (api: `npm run seed`) so its password hash is generated reliably.

-- A demo single-zone layout.
INSERT INTO layouts (name, preset, zones)
VALUES (
  'Fullscreen',
  'single',
  '[{"id":"00000000-0000-0000-0000-000000000001","kind":"image","x":0,"y":0,"width":100,"height":100,"config":{},"playlistId":null}]'::jsonb
)
ON CONFLICT DO NOTHING;
