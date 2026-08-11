# laravel-beam-licenser

Server (issuer) + client (verifier) licensing for self-hosted Splicewire installs. Wraps
`masterix21/laravel-licensing` (server, pinned `2.2.1`) and `masterix21/laravel-licensing-client`
(client, pinned `2.0.0`) — the marketplace's own fulfillment backend is a real issuer, not just a
phone-home consumer, so a client-only wrapper wouldn't cover the shape.

- **`LicenseIssuer`** — server role: `issue()` creates + activates a license, returning `LicenseData`
  (the plaintext key exists on this DTO exactly once). `revoke()` is mechanism-only — marks a license
  revoked (mapped onto the vendor's `cancel()`) so the next phone-home reflects it. No caller decides
  *when* to revoke; that is later tickets' policy.
- **`LicenseClient`** — client role: `activate()`/`verify()` phone-home over the vendor's low-level
  HTTP transport, with a reachability-based offline-grace window (see `docs/adr/0191-*.md`). A
  reachable server that explicitly denies (revoked/suspended/expired) always denies immediately, never
  grace-eligible; only genuine unreachability (`ConnectionException`) is graced, up to
  `config('licensing-client.grace_period_days')`.
- **`LicenserEntitlementGate`** — implements app ADR-0023's `EntitlementGate` contract (from
  `splicewire/laravel-beam-commerce`), the same interface `DefaultEntitlementGate` (SaaS) and
  `FreePlanEntitlementGate` (self-hosted, no license) implement. Sourced from the client's verdict plus
  the vendor's own `config('licensing.trials.default_feature_restrictions')` — no separate
  `hasFeature()`-shaped API invented alongside it.

## Deployment-mode-decides-the-gate

`BeamLicenserServiceProvider` binds nothing into the shared `EntitlementGate` seam unless
`config('beam.licenser.enabled')` is true — a self-hosted install with a phone-home license flips it
on; a SaaS-hosted install leaves it off (or never requires this package at all) and the existing
`DefaultEntitlementGate`/plan-tier cascade answers, untouched. See `tests/Deployment/`.

See `CONTEXT.md` for the full glossary and `AGENTS.md` for the vendored family-package conventions
this wrapper carries forward.
