<?php

use Illuminate\Support\ServiceProvider;
use LucaLongo\LaravelLicensingClient\LaravelLicensingClientServiceProvider;
use LucaLongo\Licensing\LicensingServiceProvider;
use Rushing\Popcorn\Registries\RelativeUriKey;
use Splicewire\Beam\Install\BeamInstallManifest;
use Splicewire\Beam\Licenser\BeamLicenserServiceProvider;

it('publishes both vendor migration sets through Beam even when client entitlement enforcement is off', function () {
    config(['beam.licenser.enabled' => false]);
    $this->app->singleton(BeamInstallManifest::class);
    $provider = new BeamLicenserServiceProvider($this->app);
    $provider->boot();
    $provider->boot();

    $manifest = app(BeamInstallManifest::class);
    $step = $manifest->resolve(RelativeUriKey::of('splicewire/laravel-beam-licenser'));
    expect($manifest->steps())->toHaveCount(1)
        ->and($step->migrates)->toBeTrue()
        ->and($step->publishTags)->toContain('licensing-migrations', 'licensing-client-migrations');

    expect(ServiceProvider::pathsToPublish(LicensingServiceProvider::class, 'licensing-migrations'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(LaravelLicensingClientServiceProvider::class, 'licensing-client-migrations'))->not->toBeEmpty();
});
