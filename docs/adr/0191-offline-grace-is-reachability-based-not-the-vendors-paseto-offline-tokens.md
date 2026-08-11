# ADR-0191 — Offline-grace is reachability-based (HTTP exception classification), not the vendor's PASETO offline-token verification

Status: accepted. Date: 2026-08-11.

Numbered 0191 — one above the highest existing ADR number found across the family at authoring time
(`find ~/Workspaces/php/packages ~/Herd -path '*/docs/adr/*.md'`, max 0190) — per this build's own
cross-package numbering convention, not this fleet's general per-repo ADR numbering rule (see
`splicewire-ecosystem docs/conventions/adr-placement.md`, which stays per-repo/local going forward).

## Context

Neither `masterix21/laravel-licensing` (server/issuer) nor `masterix21/laravel-licensing-client`
(client/verifier) is installed anywhere in this fleet today — this is genuinely first-time vendor
integration, the same "decided-but-unadopted" situation `lunarphp/core` is in elsewhere in this build
(marketplace build ticket 04). The vendor's headline feature is **offline verification**: the server
issues short-lived PASETO v4 tokens signed with Ed25519 (root CA → rotating signing keys); a client
verifies a token entirely locally against a stored public-key bundle, with no network call at all,
until `force_online_after_days` forces a refresh. The vendor's high-level facade
(`LucaLongo\LaravelLicensingClient\LaravelLicensingClient`) is built around this: `validate()`/`isValid()`
read a locally stored token, never make an HTTP call.

This ticket also required an **offline-grace window**: verification against an unreachable license
server must still return valid within a configured grace period, and deny once it elapses — and,
critically, a REACHABLE server that explicitly denies (revoked/suspended/expired) must deny immediately,
never ride out the grace window as if it were merely offline. That distinction — reachable-and-said-no
vs. genuinely-unreachable — is the crux of `LicenseIssuer::revoke()`'s round-trip requirement (revoke,
then the next phone-home must reflect it as a denial, not a grace-passthrough).

## Decision

`LicenseClient` phones home through the vendor's **low-level** `LicensingApiClient` (the plain HTTP
transport + exception-mapping service), never the high-level `LaravelLicensingClient` facade. The
grace/deny split rides directly on the vendor client's own exception hierarchy, which already encodes
the distinction we need:

- `Illuminate\Http\Client\ConnectionException` (DNS/timeout — the server was never reached) is
  grace-eligible. `LicenseClient` starts (or continues) a grace window, reusing the vendor's own
  `TokenStorage::storeGracePeriodData()`/`getGracePeriodData()` file store and
  `config('licensing-client.grace_period_days')` — both already shipped, not reinvented.
- `LucaLongo\LaravelLicensingClient\Exceptions\LicensingException` (the server WAS reached and answered
  no — `LicensingApiClient::mapRequestException()` translates the HTTP status: 423 suspended/cancelled,
  410 expired, etc.) is never grace-eligible. It denies immediately and clears any in-progress grace
  window — the server's explicit answer is authoritative the moment it's reachable again.

This means `LicenseClient` builds its own online/grace/denied verdict on top of the vendor's HTTP
transport, and does **not** use the vendor's PASETO token issuance/verification machinery (Ed25519
signing keys, key rotation, public-key bundles, `force_online_after_days`) at all. `verify()` always
makes a real HTTP call; there is no locally-verifiable, network-independent long-term offline mode.

## Considered Options

1. **Use the vendor's high-level facade + PASETO offline tokens** (the vendor's own designed path).
   Rejected for this ticket's scope: `validate()`/`isValid()` are token-only and don't distinguish
   "unreachable, within grace" from "no token stored yet" from "token expired" in the shape this
   ticket needs, and standing up the Ed25519 key hierarchy (root CA + signing keys, `licensing:keys:make-root`)
   is a separate, heavier first-time-integration decision this ticket doesn't need to force. It also
   doesn't test cleanly against `Http::fake()` the way this ticket's acceptance tests require, since it
   never makes an HTTP call to fake.
2. **Wrap the low-level API client and build our own reachability-based grace window** (chosen). Real
   wrapper-layer work — exactly the framing this ticket already uses for `LicenserEntitlementGate` (not
   a thin pass-through) — reusing the vendor's own exception split and grace-period storage rather than
   inventing parallel bookkeeping.
3. **Build fully custom HTTP + custom grace bookkeeping, ignoring the vendor client package's transport
   entirely.** Rejected: would duplicate exception-mapping (423/410/403/409 → meaning) the vendor
   package already ships correctly.

## Consequences

- This package's `verify()` requires network reachability on every call — there is no genuinely
  offline-for-weeks mode. If a later ticket needs that (e.g. an air-gapped self-hosted install), it is a
  **new decision**, not a natural extension of this one: adopting the vendor's PASETO token path means
  standing up the Ed25519 key hierarchy this ADR explicitly deferred.
- The grace window's clock starts at the FIRST unreachable phone-home and is not reset by subsequent
  unreachable polls within the same window (`graceOrDenied()` only writes `started_at` once) — a caller
  polling every few minutes does not get a rolling window.
- Revocation is real: because a reachable-and-denying response is never grace-eligible, the round-trip
  acceptance test (issue → verify valid → revoke → verify denied) holds even if the two `verify()` calls
  happen inside what would otherwise still be an open grace window.
- This is a hard-to-reverse choice for any FUTURE ticket that wants true air-gapped offline
  verification: it would need to layer the vendor's PASETO path in alongside (or instead of) this
  reachability-based one, not simply extend `graceOrDenied()`.
