<?php

use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Commerce\Entitlements\EntitlementDeniedException;
use Splicewire\Beam\Licenser\LicenserEntitlementGate;
use Splicewire\Beam\Tenancy\Tenant;

/**
 * LicenserEntitlementGate's allow/deny decision is sourced from
 * LicenseTemplate's `default_feature_restrictions` config — the vendor's own
 * existing restriction list — not a separately invented feature-flag
 * mechanism. A single self-hosted license is per-install, so `$tenant` is
 * accepted (the ADR-0023 EntitlementGate contract requires it) but not
 * consulted, matching FreePlanEntitlementGate's own precedent.
 */
function fakeReachableActiveLicense(): void
{
    Http::fake(fn () => Http::response([
        'success' => true,
        'data' => ['license' => ['status' => 'active', 'features' => [], 'entitlements' => []], 'usage' => ['status' => 'active']],
    ], 200));
}

it('allows a capability not in the license template restricted-features list', function () {
    config(['licensing.trials.default_feature_restrictions' => ['export']]);
    fakeReachableActiveLicense();

    $gate = app(LicenserEntitlementGate::class);
    $tenant = new Tenant;

    $response = $gate->inspect($tenant, 'api_access');

    expect($response->allowed())->toBeTrue();

    $gate->authorize($tenant, 'api_access'); // does not throw
    expect(true)->toBeTrue();
});

it('denies a capability that IS in the license template restricted-features list', function () {
    config(['licensing.trials.default_feature_restrictions' => ['export']]);
    fakeReachableActiveLicense();

    $gate = app(LicenserEntitlementGate::class);
    $tenant = new Tenant;

    $response = $gate->inspect($tenant, 'export');

    expect($response->denied())->toBeTrue()
        ->and($response->denial()->capability)->toBe('export')
        ->and($response->denial()->reason)->toContain('export');

    expect(fn () => $gate->authorize($tenant, 'export'))
        ->toThrow(EntitlementDeniedException::class);
});

it('denies everything when the underlying license itself is invalid', function () {
    config(['licensing.trials.default_feature_restrictions' => []]);

    Http::fake(fn () => Http::response([
        'success' => false,
        'error' => ['code' => 'SUSPENDED_LICENSE', 'message' => 'License is not active'],
    ], 423));

    $gate = app(LicenserEntitlementGate::class);
    $tenant = new Tenant;

    $response = $gate->inspect($tenant, 'anything');

    expect($response->denied())->toBeTrue();
});
