# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## 0.4.1 — 2026-10

### Security

- Restricted access mode now returns a core 404 page instead of 403 — the
  app's existence is no longer disclosed to non-allowlisted users
- Public share view is anonymous-rate-limited (10 requests / 20 min) —
  bearer-token probing is now throttled instead of unlimited

### Fixed

- Plan publishing is serialized per client inside a transaction, backed by a
  unique `(client_id, revision)` index — concurrent publishes can no longer
  create duplicate published plan versions
- `base_revision` values that are not decimal integers are rejected instead
  of being silently cast

## 0.4.0 — 2026-10

### Added

- Coverage board redesigned: stat tiles (stops planned / needs action /
  uncovered), previous/next-day navigation, today shortcut, uncovered-stop
  callout, per-tour grouped cards with progress
- Filter panels (search + status/qualification) with live result counts on
  clients, members, catalog, tours; status quick-filters on notices
- Mobile card transformation for all list tables (labeled fields, no
  horizontal clipping)
- Client detail section navigation (record / plan / journal / photos /
  exports) with anchored scrolling
- Localized weekday abbreviations and status labels throughout
- Shared Check-family view-component pack (`js/common/view-components.js`,
  `css/common/view-components.css`): stat strips, card grids, filter panels,
  quick-filter pills with roving tabindex, callouts, empty/loading states
- Full locale parity: da, es, fr, it, nb, nl, pl, pt, pt_BR, sv now carry the
  complete catalog (382 keys) — previously ~295
- Repository ship artifacts: README, CHANGELOG, LICENSE, AUTHORS, SECURITY,
  release Makefile, l10n parity/runtime gate scripts

### Changed

- Board progress counts treat `done` and `skipped` as complete
- Create/edit forms moved below lists — find-first flow is no longer buried
- Share links emit absolute route URLs (work behind subdir + index.php
  installs)

### Fixed

- Tour executor references on the board could go stale — `templateNames` now
  refreshes with `loadTours()`
- Member row actions re-bind on every filter re-render
- Settings forms are disabled until first hydration completes — a submit
  during hydration no longer posts empty fields (race)
- l10n JS catalogs no longer emit literally-escaped slashes (`\/` → `/`)
