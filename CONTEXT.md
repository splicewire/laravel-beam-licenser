# laravel-beam-licenser

Server (issuer) + client (verifier) licensing for self-hosted Splicewire installs, wrapping
`masterix21/laravel-licensing` and `masterix21/laravel-licensing-client`.

## Language

**LicenseData**:
The server/issuer-side Data class (Spatie Laravel-Data) projecting an issued `LucaLongo\Licensing\Models\License`
row. Named `LicenseData` — not `License` — specifically to avoid colliding with the vendor's own
Eloquent model of that name when both are imported in the same scope (a `LicenseIssuer` consumer that
also queries the vendor model directly needs the two names to read unambiguously as "the DTO" vs "the
row"). Carries the plain-text license key ONLY right after `LicenseIssuer::issue()` — every other
projection (a revoke, a re-fetch) carries it null, since the vendor persists only a hash.
`graceUntil` (ticket 13, splicewire-marketplace-build) surfaces the vendor's own already-live
server-side grace concept — `License::getGraceDays()`, `expiresAt + grace_days` — for the first
time; null when `expiresAt` is null. `LicenseIssuer::find(string $licenseId): ?LicenseData` is the
plain read a consumer (e.g. a pause-not-cancel subscriber reading the buffer window) calls to get
one back without touching the vendor model directly.
_Avoid_: License (that is the vendor's own Eloquent model — never shadow it), LicenseDto, grace_until
(this DTO's fields are camelCase like every other one here — `graceUntil`, not the ticket-prose
snake_case)

**LicenseVerificationData**:
The client/verifier-side Data class projecting one `LicenseClient` phone-home outcome: `valid`, a
`VerificationSource` (`online`/`grace`/`denied`), an optional `status`/`reason`, and — when
grace-sourced — a `graceExpiresAt` timestamp. Same Data-suffix collision-avoidance reasoning as
**LicenseData**.
_Avoid_: LicenseVerification, VerificationResult

**Deployment mode** (this package's own vocabulary for the seam it plugs into):
The single axis that decides which `EntitlementGate` binding wins — SaaS-hosted (self-hosted's
opposite: stays on `DefaultEntitlementGate`/the plan-tier cascade, this package's classes never
resolve) vs. self-hosted-with-license (`LicenserEntitlementGate` binds). Represented as one config
switch, `beam.licenser.enabled` — read once, at `BeamLicenserServiceProvider::register()` — never a
scattered `if (config(...))` at individual call sites. Mirrors the shape splicewire-app's own
`AppServiceProvider` already uses for `splicewire.entitlements.free_plan` (ADR-0212's bound-contract
seam): a host app flips ITS OWN switch; this package never inspects environment/host state on its own
to guess which mode it's in.
_Avoid_: License mode, install type, hosting mode

**Offline-grace window**:
The span (`config('licensing-client.grace_period_days')`, vendor-owned config, reused rather than
duplicated) during which `LicenseClient::verify()` keeps returning valid after the license server
becomes UNREACHABLE (a `ConnectionException` — DNS/timeout, never reached) — timed from the FIRST
unreachable phone-home, not reset by each subsequent poll. Distinct from an explicit denial: a
REACHABLE server that says no (revoked/suspended/expired — a `LicensingException`, HTTP error response)
is never grace-eligible and denies immediately, even mid-window. Persisted via the vendor client's own
`TokenStorage::storeGracePeriodData()`/`getGracePeriodData()` file store — reused, not reinvented.
_Avoid_: Offline mode, cached verification, stale license

**Phone-home**:
This ticket's own term for `LicenseClient::verify()` (and `activate()`) — the client asking the license
server, over HTTP, whether the license is still good right now. Always exercised through the vendor's
low-level `LicensingApiClient` (HTTP transport + exception mapping), never the vendor's high-level
`LaravelLicensingClient` facade (whose `validate()`/`isValid()` are entirely OFFLINE, reading a locally
stored PASETO token — not what "phone-home" means here).
_Avoid_: Heartbeat (the vendor's own distinct concept — a lighter-weight liveness ping, not a full
verify), license check

**Revoke (mechanism-only)**:
`LicenseIssuer::revoke()` marks a license revoked (mapped onto the vendor's `cancel()` — the vendor's
`LicenseStatus` enum has no `Revoked` case; `cancel()` is the terminal, non-reversible one, distinct
from the pausable `suspend()`) so the next client phone-home reflects it as a denial. Mechanism-only:
this package wires NO caller that decides *when* to revoke — the only invocation in this package's own
scope is its own Feature test. Deciding when is later tickets' policy (review-workflow takedown, Tower
plan downgrade).
_Avoid_: Cancel, deactivate, suspend (the vendor's own distinct, reversible state)

**LicenseIssued** / **LicenseRevoked** (ticket 11):
Domain events fired as the LAST step of `LicenseIssuer::issue()`/`revoke()` respectively, each
carrying the same {@see LicenseData} projection the caller itself receives (including
`licensableType`/`licensableId`, the load-bearing fields for a consumer to decide whether an
issued/revoked license concerns it). This package's own decoupling seam: it fires the event and
stops — it has NO knowledge of, and makes NO direct call into, any consumer (e.g.
`splicewire/laravel-beam-market`'s `PackageDistributionGatewayResolver` port, bound by
`splicewire/laravel-beam-market-packages`' distribution registry). A consumer reacts entirely
through Laravel's event dispatcher.
_Avoid_: a direct method call from `LicenseIssuer` into any consumer package — the event pair is the
only sanctioned seam

**LicenserEntitlementGate**:
The self-hosted binding of app ADR-0023's `EntitlementGate` contract (from `splicewire/laravel-beam-commerce`) —
the SAME interface `DefaultEntitlementGate` (SaaS) and `FreePlanEntitlementGate` (self-hosted, no
license) implement. No separate `hasFeature()`-shaped API is invented alongside it: the vendor client's
own `hasFeature()`/`getFeatures()` are roadmap-only (its README lists them under "Development Roadmap —
Phase 2 — Features & Entitlements", not shipped), so this class answers `inspect()` for real — a
license-validity check via `LicenseClient`, THEN a membership check against the vendor's own
`config('licensing.trials.default_feature_restrictions')` list. A single self-hosted license is
per-INSTALL, not per-tenant, so the interface's `$tenant` argument is accepted but not consulted (same
precedent as `FreePlanEntitlementGate`).
_Avoid_: LicenseFeatureGate, LicensingEntitlementService
