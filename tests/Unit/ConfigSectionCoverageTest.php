<?php

use Splicewire\Beam\Config\ConfigSectionCoverage;
use Splicewire\Beam\Config\ConfigSectionRegistry;
use Splicewire\Beam\Licenser\Config\BeamLicenserSection;

it('registers the beam.licenser config section from the service provider', function () {
    $registered = app(ConfigSectionRegistry::class)->get('beam.licenser');

    expect($registered)->not->toBeNull()
        ->and($registered->section)->toBe(BeamLicenserSection::class)
        ->and($registered->owner)->toBe('splicewire/laravel-beam-licenser');
});

it('covers every beam.licenser key and carries no draft copy', function () {
    expect(ConfigSectionCoverage::uncovered(BeamLicenserSection::class, config('beam.licenser')))->toBe([])
        ->and(ConfigSectionCoverage::drafts(BeamLicenserSection::class))->toBe([]);
});
