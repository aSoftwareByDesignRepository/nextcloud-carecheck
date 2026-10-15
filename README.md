# CareCheck — mobile care documentation for Nextcloud

App ID in Nextcloud: **`carecheck`**. Clone path:

```bash
git clone https://github.com/aSoftwareByDesignRepository/nextcloud-carecheck.git /path/to/nextcloud/apps/carecheck
```

---

CareCheck brings ambulatory care documentation (ambulante Pflege- und
Betreuungsdienste) onto your own Nextcloud: recurring tour templates that
materialize into daily tours, an offline-first companion app for caregivers,
signed visit bundles with per-visit plan snapshots, and a tamper-evident,
hash-chained journal an auditor can verify independently.

Health-adjacent data stays on the provider's own server — no SaaS silo,
no third-party telemetry.

## Features

### Coverage & tours

- **Coverage board** — day overview with stat tiles, date navigation,
  per-tour grouping, uncovered-stop queue, and live progress per tour
- **Recurring tour templates** — weekly patterns materialize into daily tour
  instances; publish, reassign, covered/uncovered tracking
- **Bezugspflege & Vertretung** — named caregiver per client plus substitutes,
  with qualification gating (soft warn / hard block)
- **Time rules on stops** — window / fixed / deadline; missed-window
  escalation to Zentrale; late documentation is allowed and recorded
- **Team scoping (NH-11)** — team leads see only their teams' clients and
  tours; Zentrale keeps full visibility

### Visit documentation

- **Visit bundles** — check-in → briefing card → checklist → optional value
  fields → signature → check-out; atomic, idempotent, offline-capable
- **Task results** — done / refused / not-done + reason codes
- **Signatures** — on-screen client signature, representative path, or
  recorded "unable to sign"
- **Clinical photos (NH-04)** — consent-gated, encrypted blob store
- **Voice notes (NH-01)** — recorded on the companion, transcribed via
  Nextcloud TaskProcessing when a provider is configured
- **Group sessions (NH-12)** — one worker, many clients, Teilnehmerliste
- **QR/NFC presence proof (NH-14)** — scanned checkpoint token attaches to
  check-in as evidence hardening

### Records, journal & audit

- **Client records** — field-level encryption for sensitive attributes
- **Versioned care plans** — published versions; each visit snapshots the
  plan version it documented (no retroactive rewrite)
- **Append-only journal** — hash-chained, anchored externally,
  `occ carecheck:verify-chain` for independent verification
- **Break-glass** — audited, reason-coded, time-boxed cover access with a
  visible marker on affected screens and exports
- **Access audit log** — read/export events for DPO/MDK review
- **Documents** — client document links (NH-19) with expiry warnings (NH-15)

### Zentrale & exports

- **Notices (Rückmeldungen)** — incident / supply / plan-change / info with
  acknowledgement workflow and review queue for rejected sync bundles
- **Leistungsnachweis export** — PDF + CSV (formula-injection-safe,
  allow-listed fields), structured JSON/XML billing input (NH-07),
  InvoiceCheck draft invoices (NH-21)
- **QM dashboard (NH-17)** — incident/overdue/unsigned-visit statistics;
  vital-value trends (NH-18)
- **Client CSV import (NH-20)** — switch-onboarding for Klientenstamm
- **Diagnostics (NH-10)** — license/device/sync state without client data

### Platform

- **WCAG 2.1 AA** — keyboard, screen reader, forced-colors, reduced motion,
  low-literacy copy
- **Fully responsive** — desktop table views collapse to labeled cards on
  phones
- **12 locales** — en/de plus da, es, fr, it, nb, nl, pl, pt, pt_BR, sv
- **Docker-friendly** — SQLite/MySQL/PostgreSQL; strict DB portability rules

## Companion app

The native companion (`mobile/carecheck`, Expo/React Native) is licensed
separately via `CCK2` seats. It provides offline-first visit execution,
PIN + biometric unlock, QR pairing, break-glass, group sessions, voice notes,
photo documentation, and station/kiosk mode. The web app stays free AGPL —
never paywalled.

## Installation

### Requirements

- Nextcloud 32 – 35
- PHP 8.2 – 8.5
- MariaDB/MySQL or PostgreSQL

### Steps

1. Place the app in your Nextcloud `apps` (or `custom_apps`) directory
2. Enable: `occ app:enable carecheck`
3. Open **CareCheck** in the app menu — seed a task catalog, create clients
   and plans, build tour templates

## occ commands

| Command | Purpose |
|---|---|
| `carecheck:verify-chain` | Verify the hash chain of the client journal |
| `carecheck:upgrade-backup` | Create/restore a full data backup around upgrades |

## Development

```bash
composer install          # PHP deps + phpunit
vendor/bin/phpunit        # unit + integration suites
npm install               # e2e harness
npm run e2e               # Playwright (desktop + mobile projects)
php scripts/check-l10n-parity.php    # locale catalog parity
php scripts/check-l10n-runtime.php   # source strings ⊆ en.json
```

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE).
