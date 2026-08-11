> You are in **splicewire/laravel-beam-licenser** — server (issuer) + client (verifier) licensing for self-hosted Splicewire installs.

Wraps BOTH roles of `masterix21/laravel-licensing` (server/issuer, pinned `2.2.1`) and its client sibling
`masterix21/laravel-licensing-client` (pinned `2.0.0`) — the marketplace's own purchase-fulfillment
backend is a real issuer, not just a phone-home consumer, so a client-only wrapper wouldn't cover the
shape. `LicenseIssuer` issues/revokes (server); `LicenseClient` activates/verifies/phones-home with an
offline-grace window (client); `LicenserEntitlementGate implements` app ADR-0023's `EntitlementGate`
contract (from `splicewire/laravel-beam-commerce`), backed by the client's phone-home verdict plus the
vendor's own `LicenseTemplate`-driven `default_feature_restrictions` config. Deployment mode decides the
gate: `config('beam.licenser.enabled')` is the switch a self-hosted host app's own `AppServiceProvider`
flips (the same shape as splicewire-app's `splicewire.entitlements.free_plan`) — `BeamLicenserServiceProvider`
binds nothing into the shared `EntitlementGate` seam unless it's on, so a SaaS-hosted install (this flag
off, or the package never required at all) stays on the existing `DefaultEntitlementGate`/plan-tier path
untouched.

## Vendored family-package conventions

Both vendor packages ship their own agent-facing guidance (`masterix21/laravel-licensing` ships
`AGENTS.md` + `AI_GUIDELINES.md`; `masterix21/laravel-licensing-client` ships `CLAUDE.md`). Duplicated
here per the ecosystem's vendored-family-package instruction-discovery rule — this section stays
self-contained even if this repo goes public later, never a pointer back to `splicewire-ecosystem`.

**Key security principles (from the server package's `AI_GUIDELINES.md`) — apply wherever this
package's own code touches license keys/tokens:**
1. Never store or log a plain activation key — the vendor persists only `key_hash`; `LicenseIssuer::issue()`
   returns the plaintext key on its `LicenseData` DTO exactly once (creation), never again.
2. Constant-time comparison for key verification (`hash_equals()`, used by the vendor's own `verifyKey()`).
3. Private/signing keys are encrypted at rest (Argon2id + XSalsa20-Poly1305); tokens are *signed, not
   encrypted* — clients only ever need public keys.
4. API errors never expose internals — this package's own `LicenseVerificationData::denied()` reasons
   should stay similarly generic (no stack traces, no internal identifiers) if ever surfaced to an end user.
5. Fingerprints are validated `max:255` on all vendor API inputs — `LicenseClient` passes the vendor's own
   `FingerprintGenerator` output through untouched, never a caller-supplied raw value.
6. Rate limiting is enforced by the vendor on all its API endpoints by default — `LicenseClient`'s
   phone-home calls should stay infrequent (one `verify()` per meaningful check, not a polling loop) so
   they don't trip it.

**Naming — from the server package's own `AGENTS.md`:** its artisan commands use a `licensing:` prefix
(e.g. `licensing:keys:rotate`). If this package ever ships its own artisan commands, prefix them
`beam:licenser:` (matching this fleet's own `beam.` config-namespace convention), never `licensing:` —
that prefix belongs to the vendor and a collision would be genuinely ambiguous about which package a
command belongs to.

**Editing-through note:** neither vendor package is ever edited through into by this repo (it consumes
them purely via Composer); their own `AGENTS.md`/`CLAUDE.md` conventions about *their own* source layout,
PHPStan/Pint setup, and PR process are therefore not carried here — only the conventions above, which
bear on how a consumer should use them, are relevant to this package.
