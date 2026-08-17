<?php

namespace Splicewire\Beam\Licenser\Tests\Fixtures;

use Splicewire\Beam\Capabilities\GatedCapability;
use Splicewire\Beam\Commerce\Entitlements\EntitlementGate;
use Splicewire\Beam\Commerce\Entitlements\GateResponse;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * Stands in for the REAL `DefaultEntitlementGate` (the SaaS plan-tier
 * cascade) in the deployment-mode test. The real class's dependency chain
 * (`EntitlementResolver` -> `Subscription`/`Plan` DB cascade) is a whole
 * commerce fixture out of scope for this package's own test suite — what the
 * SaaS-mode assertion needs to prove is narrower and doesn't depend on which
 * concrete class answers: that a host app's own `EntitlementGate` binding
 * survives BeamLicenserServiceProvider's boot untouched, and no class from
 * THIS package is ever resolved, when `beam.licenser.enabled` is false. This
 * fixture is any real implementation of the same ADR-0023 contract; the
 * assertion is about which binding wins, not this fixture's own behavior.
 */
class StubSaasEntitlementGate implements EntitlementGate
{
    public function authorize(Tenant $tenant, GatedCapability|string $capability): void
    {
        // Intentionally inert — never exercised by the deployment-mode test.
    }

    public function inspect(Tenant $tenant, GatedCapability|string $capability): GateResponse
    {
        return GateResponse::allow(is_string($capability) ? $capability : $capability->key());
    }
}
