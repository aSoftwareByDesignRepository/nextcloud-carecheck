import { test, expect } from '@playwright/test';
import { login } from './helpers/auth.js';
import { execSync } from 'node:child_process';
import { randomUUID } from 'node:crypto';

const COMPOSE = '../../docker-compose.yml';
const db = (sql) => execSync(
  `docker compose -f ${COMPOSE} exec -T mariadb mariadb -u nextcloud -pnextcloud_password nextcloud -N -e "${sql}"`,
  { encoding: 'utf8' }).trim();

// Every test seeds its own client through the real API — a created client is
// active and in the actor's scope, unlike `SELECT id ... LIMIT 1` which can
// return an archived or foreign-team row (canonical 404, correct deny).
async function seedClient(request, rt, tag) {
  const res = await request.fetch('/apps/carecheck/api/clients', {
    method: 'POST',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { name: `Race ${tag} ${randomUUID()}`, street: 'E2E Weg 1', zip: '10115', city: 'Berlin' },
  });
  const body = await res.json();
  expect(body.success, JSON.stringify(body)).toBe(true);
  return Number(body.data.id);
}

async function sessionRequest(page) {
  await login(page, { username: 'cck_smoke', password: 'CckSmoke!2026' });
  const request = page.context().request;
  const html = await (await request.get('/apps/carecheck/')).text();
  const rt = (html.match(/data-requesttoken="([^"]+)"/) || [])[1] || '';
  expect(rt).not.toBe('');
  return { request, rt };
}

test('concurrent group-session open with same idem key creates exactly one session', async ({ page }) => {
  const { request, rt } = await sessionRequest(page);
  const cid = await seedClient(request, rt, 'gsession');
  const idem = `race-${randomUUID()}`;

  const req = () => request.fetch('/apps/carecheck/api/group-sessions', {
    method: 'POST',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { title: 'Race test', client_ids: [cid], idem_key: idem },
  }).then(async (r) => ({ status: r.status(), body: await r.json() }));

  const results = await Promise.all([req(), req(), req()]);
  console.log('responses:', JSON.stringify(results));
  const ids = new Set(results.map((r) => r.body?.data?.session?.id));
  expect(ids.size).toBe(1);
  expect([...ids][0]).toBeGreaterThan(0);
  const rows = db(`SELECT COUNT(*) FROM oc_cck_gsession WHERE idem_key='${idem}'`);
  expect(Number(rows)).toBe(1); // ground truth: exactly one row
});

test('concurrent plan publishes with same base_revision yield exactly one winner', async ({ page }) => {
  const { request, rt } = await sessionRequest(page);
  const cid = await seedClient(request, rt, 'plan');
  // fresh client — no published plan yet, so the CAS token is 0 (same as the
  // plan editor sends on first publish)
  const baseRev = 0;
  const before = db(`SELECT COUNT(*) FROM oc_cck_plan_v WHERE client_id=${cid} AND status='published'`);
  expect(Number(before)).toBe(0);

  const req = () => request.fetch(`/apps/carecheck/api/clients/${cid}/plan/publish`, {
    method: 'POST',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { tasks: [{ title: 'Race task' }], base_revision: baseRev },
  }).then(async (r) => ({ status: r.status(), body: await r.json() }));

  const results = await Promise.all([req(), req(), req()]);
  console.log('publish race:', JSON.stringify(results.map((r) => r.status)));
  const winners = results.filter((r) => r.status === 201);
  const losers = results.filter((r) => r.status === 409);
  expect(winners.length).toBe(1); // exactly one may win the CAS
  expect(losers.length).toBe(2);
  const after = db(`SELECT COUNT(*) FROM oc_cck_plan_v WHERE client_id=${cid} AND status='published'`);
  expect(Number(after)).toBe(1);
  const dupRev = db(`SELECT COUNT(*) FROM (SELECT revision FROM oc_cck_plan_v WHERE client_id=${cid} GROUP BY revision HAVING COUNT(*)>1) t`);
  expect(Number(dupRev)).toBe(0); // ground truth: no duplicate revisions

  // malformed base_revision must fail closed — never silently cast
  const bad = await request.fetch(`/apps/carecheck/api/clients/${cid}/plan/publish`, {
    method: 'POST',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { tasks: [{ title: 'x' }], base_revision: '2.9' },
  });
  expect(bad.status()).toBe(409);
});

test('concurrent client updates with same base_revision — exactly one winner, no lost update', async ({ page }) => {
  const { request, rt } = await sessionRequest(page);
  const cid = await seedClient(request, rt, 'update');
  const rev = db(`SELECT revision FROM oc_cck_client WHERE id=${cid}`);
  expect(Number(rev)).toBe(1); // freshly created clients start at revision 1
  const mark = `race-${randomUUID()}`;

  // two writers merge different fields against the same base revision
  const req = (field) => request.fetch(`/apps/carecheck/api/clients/${cid}`, {
    method: 'PUT',
    headers: { requesttoken: rt, 'Content-Type': 'application/json' },
    data: { [field]: mark + '-' + field, base_revision: Number(rev) },
  }).then((r) => r.status());

  const statuses = await Promise.all([req('street'), req('city'), req('street')]);
  console.log('update race:', JSON.stringify(statuses));
  expect(statuses.filter((s) => s === 200).length).toBe(1);
  expect(statuses.filter((s) => s === 409).length).toBe(2);
  // ground truth: revision advanced exactly once — no silent double-write
  const revAfter = db(`SELECT revision FROM oc_cck_client WHERE id=${cid}`);
  expect(Number(revAfter)).toBe(Number(rev) + 1);
});
