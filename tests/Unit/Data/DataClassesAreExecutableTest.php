<?php

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Mappers\CamelCaseMapper;
use Spatie\LaravelData\Support\DataConfig;

/**
 * The executability gate for this package's declared Data classes
 * (api-surface-coherence tickets 84/85).
 *
 * Ticket 84 measured, in `splicewire/tower`, that a package harness which never registers
 * `Spatie\LaravelData\LaravelDataServiceProvider` leaves `config('data')` NULL — and every
 * hydration call then dies with `ErrorException: Trying to access array offset on null`, a
 * FATAL rather than a failure. Thirteen sibling packages shared the omission; this one did too
 * (measured null here before the fix).
 *
 * Declaring a wire shape is only worth what the declaration can be EXECUTED to prove, so this
 * walks every concrete `BaseData` subclass under `src/` and runs the two halves of the
 * hydration path that `::from()` / `::validateAndCreate()` reach before touching any payload:
 *
 *  1. `DataConfig::getDataClass()` — the full reflection analysis: every property's type, every
 *     attribute instantiated, casts / mappers / nested Data classes resolved.
 *  2. `::getValidationRules([])` — the rule inferrers plus any hand-written `rules()`, projected
 *     through the input name mapper.
 *
 * Neither needs a fixture payload, which is what lets it be data-driven; a per-class payload
 * test is a finer instrument and belongs with the class that needs one.
 */
function licenserDataClasses(): array
{
    $root = dirname(__DIR__, 3).'/src';

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $classes = [];

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (substr($path, -4) !== '.php') {
            continue;
        }

        // Namespace is the path under src/, PSR-4'd onto Splicewire\Beam\Licenser\.
        $relative = substr($path, strlen($root) + 1, -4);
        $class = 'Splicewire\\Beam\\Licenser\\'.str_replace('/', '\\', $relative);

        if (! class_exists($class)) {
            continue;
        }

        if (! is_subclass_of($class, BaseData::class)) {
            continue;
        }

        if ((new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        $classes[$class] = [$class];
    }

    ksort($classes);

    return $classes;
}

it('has the laravel-data provider booted, mirroring the host config', function () {
    // Guards the fix itself. Without the provider `config('data')` is null and every case below
    // fatals rather than fails, which is a much worse signal.
    expect(config('data'))->not->toBeNull();

    // And guards against the FALSE GREEN: the package default is `null`, only the host sets the
    // mapper, and a DTO that hydrates without it can still fail to map under the host.
    expect(config('data.name_mapping_strategy.input'))
        ->toBe(CamelCaseMapper::class);
});

it('finds declared Data classes to check', function () {
    // A discovery bug that silently found zero classes would make every case below vacuous.
    expect(count(licenserDataClasses()))->toBeGreaterThan(0);
});

it('can analyse and derive validation rules for every declared Data class', function (string $class) {
    $dataClass = app(DataConfig::class)->getDataClass($class);

    expect($dataClass->name)->toBe($class);

    expect($class::getValidationRules([]))->toBeArray();
})->with(licenserDataClasses());
