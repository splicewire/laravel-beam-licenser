<?php

namespace Splicewire\Beam\Licenser\Events;

use Splicewire\Beam\Licenser\Data\LicenseData;
use Splicewire\Beam\Licenser\LicenseIssuer;

/**
 * Fired at the tail of {@see LicenseIssuer::issue()}, once the license
 * row is created and activated. Ticket 11's own decoupling seam: this package fires the event and
 * knows NOTHING about who reacts to it — a consumer (e.g. `splicewire/laravel-beam-market`'s
 * `PackageDistributionGatewayResolver` port) reads {@see LicenseData::$licensableType}/
 * {@see LicenseData::$licensableId} to decide whether/how the issued license affects it.
 *
 * Carries the same {@see LicenseData} projection `issue()` itself returns to its caller — no
 * separate event-payload shape invented alongside it.
 */
class LicenseIssued
{
    public function __construct(public LicenseData $license) {}
}
