<?php

namespace Splicewire\Beam\Licenser;

use Illuminate\Support\ServiceProvider;
use Splicewire\Beam\Commerce\Entitlements\EntitlementGate;
use Splicewire\Beam\Install\BeamInstallManifest;

/**
 * Deployment-mode-decides-the-gate (this ticket's own vocabulary): this
 * provider binds NOTHING into the shared {@see EntitlementGate} seam unless
 * `config('beam.licenser.enabled')` is true — the same shape as
 * splicewire-app's own `config('splicewire.entitlements.free_plan')` switch in
 * `AppServiceProvider` (ADR-0212's bound-contract seam). A SaaS-hosted install
 * either never requires this package at all, or requires it but leaves the
 * flag off; either way `LicenserEntitlementGate` (and, since nothing here ever
 * resolves it lazily either, {@see LicenseClient}/{@see LicenseIssuer}) is
 * never bound and the existing `DefaultEntitlementGate`/plan-tier binding the
 * host app already made stands untouched. A self-hosted install with a
 * phone-home license flips the flag on and this provider takes over the seam.
 */
class BeamLicenserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/beam/licenser.php', 'beam.licenser');

        if (! config('beam.licenser.enabled', false)) {
            return;
        }

        $this->app->singleton(LicenseIssuer::class);
        $this->app->singleton(LicenseClient::class);

        $this->app->bind(EntitlementGate::class, LicenserEntitlementGate::class);
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/beam/licenser.php' => config_path('beam/licenser.php'),
        ], 'beam-licenser-config');

        // Both vendor roles are installed with this package. Their providers publish
        // migrations without loading them; a fresh Beam host must publish both sets.
        // Installation is independent of the optional client entitlement gate above.
        if ($this->app->runningInConsole() && $this->app->bound(BeamInstallManifest::class)) {
            $this->app->make(BeamInstallManifest::class)->register(
                package: 'splicewire/laravel-beam-licenser',
                publishTags: [
                    'beam-licenser-config',
                    'licensing-migrations',
                    'licensing-client-migrations',
                ],
                migrates: true,
                order: 110,
            );
        }
    }
}
