# Security Policy

CareCheck processes **health-adjacent personal data** (client records, care
plans, visit documentation, signatures). We treat security reports with the
highest priority.

## Reporting a vulnerability

**Do not open a public issue for security vulnerabilities.**

Please report vulnerabilities privately by email to:

- **info@software-by-design.de** — subject: `CareCheck: Security report`

Include:

- Affected version(s) and Nextcloud version
- Steps to reproduce / proof of concept
- Impact assessment (what data or privilege is at risk)

You will receive an acknowledgement within a few days. We aim to release a
fix or a documented mitigation promptly, and we credit reporters in the
changelog unless you prefer to stay anonymous.

## Scope notes

- The web app, the companion API (`/api/v1/*`), and the pairing flow are
  in scope.
- Break-glass access is an intentional audited feature — it is logged,
  time-boxed, and flagged; it is not a bug.
- Client-side encrypted data (photos, voice notes) uses sealed blobs;
  reporting a decryption path that bypasses scope checks is in scope.

## Hardening references

- Append-only, hash-chained journal — `occ carecheck:verify-chain`
- Field-level encryption for sensitive client attributes
- Break-glass grant audit + expiry
- Uniform 404 for out-of-scope IDs (no existence oracles)
- CSRF on all cookie-authed mutations; bearer tokens on companion API only
