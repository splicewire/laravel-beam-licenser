<?php

namespace Splicewire\Beam\Licenser\Tests;

use Splicewire\Beam\Commerce\Entitlements\EntitlementGate;
use Splicewire\Beam\Licenser\Tests\Fixtures\StubSaasEntitlementGate;

/**
 * The SaaS-hosted deployment-mode variant of {@see TestCase}: flips
 * `beam.licenser.enabled` off and pre-binds {@see EntitlementGate} to a stub
 * standing in for the host's own SaaS binding — reproducing what a real host
 * `AppServiceProvider` does BEFORE this package's own service provider ever
 * boots (see `defineEnvironment()`'s ordering note).
 */
abstract class SaasDeploymentTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('beam.licenser.enabled', false);

        // Orchestra Testbench calls defineEnvironment() BEFORE registering
        // package service providers (getPackageProviders()), so this binding
        // is in place exactly the way a host AppServiceProvider's own
        // EntitlementGate binding would be before BeamLicenserServiceProvider
        // gets a chance to (not) touch it.
        $app->bind(EntitlementGate::class, StubSaasEntitlementGate::class);
    }
}
