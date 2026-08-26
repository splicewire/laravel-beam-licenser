<?php

namespace Splicewire\Beam\Licenser\Tests;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\LaravelLicensingClient\LaravelLicensingClientServiceProvider;
use LucaLongo\Licensing\LicensingServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\LaravelData\LaravelDataServiceProvider;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelPackageTools\Package;
use Splicewire\Beam\Licenser\BeamLicenserServiceProvider;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected string $licensingStoragePath;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolate the client's file-backed grace-period/token storage per test
        // (TokenStorage writes real files under this path) so tests never leak
        // state into one another.
        $this->licensingStoragePath = sys_get_temp_dir().'/beam-licenser-tests-'.bin2hex(random_bytes(8));
        config(['licensing-client.storage_path' => $this->licensingStoragePath]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->licensingStoragePath)) {
            (new Filesystem)->deleteDirectory($this->licensingStoragePath);
        }

        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return [
            LicensingServiceProvider::class,
            LaravelLicensingClientServiceProvider::class,

            // Testbench does NOT auto-discover — a package harness boots exactly what this
            // method names, while `src/` freely imports anything it can autoload. This package
            // requires `spatie/laravel-data` and declares two Data classes
            // ({@see \Splicewire\Beam\Licenser\Data\LicenseData},
            // {@see \Splicewire\Beam\Licenser\Data\LicenseVerificationData}), but without this
            // line `config('data')` is NULL inside the suite and every hydration path
            // (`::from()`, `::validateAndCreate()`, rule derivation) FATALs — an ErrorException,
            // not a test failure. Measured here before the fix (`config('data') === null` true).
            // api-surface-coherence tickets 84/85; the same omission was found across thirteen
            // sibling packages. {@see SaasDeploymentTestCase} inherits this list unchanged.
            LaravelDataServiceProvider::class,

            BeamLicenserServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $c = $app['config'];
        $c->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $c->set('database.default', 'testing');
        $c->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        // The self-hosted deployment mode is ON by default in this base
        // TestCase — the SaaS-mode assertion lives in its own TestCase
        // subclass ({@see SaasDeploymentTestCase}) that turns it back off.
        $c->set('beam.licenser.enabled', true);

        $c->set('licensing-client.server_url', 'https://licensing.test');
        $c->set('licensing-client.license_key', 'LIC-TEST-0000-0000');
        $c->set('licensing-client.grace_period_days', 7);
        $c->set('licensing-client.cache.enabled', false);

        // Booting LaravelDataServiceProvider alone would be a FALSE GREEN. The package ships
        // `name_mapping_strategy.input => null`, but the only host that runs this code
        // (`~/Herd/splicewire-app/config/data.php`) sets it to CamelCaseMapper — the ONLY
        // semantic delta between the two files. A DTO hydrates fine under the package default
        // and silently stops mapping under the host's mapper, so the harness mirrors the HOST,
        // not the package default. api-surface-coherence ticket 85.
        $c->set('data.name_mapping_strategy.input', CamelCaseMapper::class);

        // Structure caching points at `app_path('Data')`, which does not exist here; a cached
        // reflection analysis carried across runs is exactly what a harness should not have.
        $c->set('data.structure_caching.enabled', false);
    }

    /**
     * Neither vendor package auto-runs its own migrations (their
     * `configurePackage()` never calls `Package::runsMigrations()` — they only
     * publish `.php.stub` files for a host app's own `database/migrations`).
     * Their own test suites run the stubs directly after RefreshDatabase's
     * `migrate:fresh`; this mirrors that, pulling the migration list + order
     * from each service provider's OWN `configurePackage()` call so this
     * package can't silently drift from the real install order.
     */
    protected function defineDatabaseMigrationsAfterDatabaseRefreshed(): void
    {
        $this->runPackageMigrationStubs(
            new LicensingServiceProvider($this->app),
            'laravel-licensing',
            __DIR__.'/../vendor/masterix21/laravel-licensing/database/migrations',
        );

        $this->runPackageMigrationStubs(
            new LaravelLicensingClientServiceProvider($this->app),
            'laravel-licensing-client',
            __DIR__.'/../vendor/masterix21/laravel-licensing-client/database/migrations',
        );
    }

    private function runPackageMigrationStubs(object $provider, string $packageName, string $migrationsDir): void
    {
        $package = new Package;
        $package->setBasePath($migrationsDir.'/..');
        $package->name($packageName);

        $provider->configurePackage($package);

        foreach ($package->migrationFileNames as $name) {
            $stub = $migrationsDir.'/'.$name.'.php';
            if (! file_exists($stub)) {
                $stub .= '.stub';
            }

            if (file_exists($stub)) {
                (include $stub)->up();
            }
        }
    }
}
