<?php

namespace Splicewire\Beam\Licenser;

use Splicewire\Beam\Capabilities\ExternalCapability;
use Splicewire\Beam\Commerce\Data\EntitlementDenialData;
use Splicewire\Beam\Commerce\Entitlements\DefaultEntitlementGate;
use Splicewire\Beam\Commerce\Entitlements\EntitlementDeniedException;
use Splicewire\Beam\Commerce\Entitlements\EntitlementGate;
use Splicewire\Beam\Commerce\Entitlements\GateResponse;
use Splicewire\Beam\Licenser\Data\LicenseVerificationData;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * The self-hosted binding of app ADR-0023's {@see EntitlementGate} — the SAME
 * contract {@see DefaultEntitlementGate}
 * (SaaS, plan cascade) and `FreePlanEntitlementGate` (self-hosted, no license)
 * implement. No separate `hasFeature()`-shaped API is invented alongside it —
 * the vendor client's own `hasFeature()` is roadmap-only (its README lists it
 * under "Development Roadmap — Phase 2", not shipped), so answering `inspect()`
 * for real is this class's job, not a pass-through to a vendor method that
 * doesn't exist yet.
 *
 * A single-node self-hosted license is per-INSTALL, not per-tenant — like
 * `FreePlanEntitlementGate`, `$tenant` is accepted (the interface requires it)
 * but not consulted; every tenant on this node shares one license verdict.
 *
 * The verdict is two-layered:
 *  1. {@see LicenseClient::verify()} — is the license itself valid right now
 *     (online, offline-grace, or denied)? An invalid license denies everything.
 *  2. Given a valid license, is THIS capability one of the vendor's own
 *     `LicenseTemplate` restricted features (`config('licensing.trials.default_feature_restrictions')`)?
 *     That is the vendor's real, existing restriction list — reused, not
 *     reinvented as a parallel feature-flag mechanism.
 */
class LicenserEntitlementGate implements EntitlementGate
{
    public function __construct(private LicenseClient $client) {}

    public function authorize(Tenant $tenant, ExternalCapability|string $capability): void
    {
        $response = $this->inspect($tenant, $capability);

        if ($response->denied()) {
            throw new EntitlementDeniedException($response->denial());
        }
    }

    public function inspect(Tenant $tenant, ExternalCapability|string $capability): GateResponse
    {
        $capabilityKey = is_string($capability) ? $capability : $capability->key();
        $entitlementKey = is_string($capability) ? $capability : $capability->requiredEntitlement();

        $verification = $this->client->verify();

        if (! $verification->valid) {
            return GateResponse::deny($capabilityKey, new EntitlementDenialData(
                capability: $capabilityKey,
                reason: $this->licenseDenialReason($verification),
                upsell: null,
            ));
        }

        if ($this->isRestrictedFeature($entitlementKey)) {
            return GateResponse::deny($capabilityKey, new EntitlementDenialData(
                capability: $capabilityKey,
                reason: "\"{$entitlementKey}\" is restricted on this license template.",
                upsell: null,
            ));
        }

        return GateResponse::allow($capabilityKey);
    }

    /**
     * The vendor's OWN restricted-features list — `LicenseTemplate`'s
     * `config('licensing.trials.default_feature_restrictions')` — is the
     * single source of truth this gate reads. Never a separately invented
     * feature-flag mechanism.
     */
    private function isRestrictedFeature(string $entitlementKey): bool
    {
        $restrictions = config('licensing.trials.default_feature_restrictions', []);

        return in_array($entitlementKey, $restrictions, true);
    }

    private function licenseDenialReason(LicenseVerificationData $verification): string
    {
        return match (true) {
            $verification->reason !== null => str_replace('_', ' ', $verification->reason),
            default => 'This license is not currently valid.',
        };
    }
}
