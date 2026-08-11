<?php

use Splicewire\Beam\Commerce\Entitlements\EntitlementGate;
use Splicewire\Beam\Licenser\LicenseClient;
use Splicewire\Beam\Licenser\LicenseIssuer;
use Splicewire\Beam\Licenser\LicenserEntitlementGate;
use Splicewire\Beam\Licenser\Tests\Fixtures\StubSaasEntitlementGate;
use Splicewire\Beam\Licenser\Tests\SaasDeploymentTestCase;

/**
 * Deployment-mode-decides-the-gate, the SaaS half: under a SaaS-hosted
 * deployment (`beam.licenser.enabled` false — a self-hosted install is the
 * only thing that flips it on), BeamLicenserServiceProvider must bind
 * NOTHING from this package into the shared EntitlementGate seam. The
 * pre-existing binding a host `AppServiceProvider` made (here, the
 * {@see StubSaasEntitlementGate} fixture standing in for the real
 * `DefaultEntitlementGate`/plan-tier cascade — see {@see SaasDeploymentTestCase})
 * is what answers, untouched, and none of this package's own classes are
 * ever resolved out of the container.
 */
it('never binds or resolves anything from this package under SaaS deployment mode', function () {
    expect(config('beam.licenser.enabled'))->toBeFalse();

    $resolved = app(EntitlementGate::class);

    expect($resolved)->toBeInstanceOf(StubSaasEntitlementGate::class)
        ->and($resolved)->not->toBeInstanceOf(LicenserEntitlementGate::class);

    expect(app()->resolved(LicenserEntitlementGate::class))->toBeFalse()
        ->and(app()->resolved(LicenseClient::class))->toBeFalse()
        ->and(app()->resolved(LicenseIssuer::class))->toBeFalse()
        ->and(app()->bound(LicenserEntitlementGate::class))->toBeFalse();
});
