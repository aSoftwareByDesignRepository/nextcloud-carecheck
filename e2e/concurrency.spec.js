import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';

const COMPOSE = '../../docker-compose.yml';
const db = (sql) => execSync(
  `docker compose -f ${COMPOSE} exec -T mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
  { encoding: 'utf8' }).trim();

test('concurrent group-session open with same idem key creates exactly one session', async ({ page }) => {
  await login(page, { username: 'cck_smoke', password: 'CckSmoke!2026' });
  const request = page.context().request;
  const html = await (await request.get('/apps/carecheck/')).text();
  const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
  expect(rt).not.toBe('');

  const cid = db('SELECT id FROM oc_cck_client LIMIT 1');
  expect(Number(cid)).toBeGreaterThan(0);
  const idem = `race-${Date.now()}`;

  const req = () => request.fetch('/apps/carecheck/api/group-sessions', {
    method: 'POST',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { title: 'Race test', client_ids: [Number(cid)], idem_key: idem },
  }).then(async (r) => ({ status: r.status(), body: await r.json() }));

  const results = await Promise.all([req(), req(), req()]);
  console.log('responses:', JSON.stringify(results));
  const ids = new Set(results.map((r) => r.body?.data?.session?.id));
  expect(ids.size).toBe(1);
  expect([...ids][0]).toBeGreaterThan(0);
  const rows = db(`SELECT COUNT(*) FROM oc_cck_gsession WHERE idem_key='${idem}'`);
  expect(Number(rows)).toBe(1); // ground truth: exactly one row
});
