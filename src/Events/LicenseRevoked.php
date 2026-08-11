<?php

namespace Splicewire\Beam\Licenser\Events;

use Splicewire\Beam\Licenser\Data\LicenseData;
use Splicewire\Beam\Licenser\LicenseIssuer;

/**
 * Fired at the tail of {@see LicenseIssuer::revoke()}, once the vendor
 * license row is marked `cancelled`. See {@see LicenseIssued} for the decoupling rationale — this
 * package fires the event only, with no knowledge of (and no direct call into) whichever package
 * reacts to a revocation (e.g. `splicewire/laravel-beam-market-packages`' distribution registry
 * dropping the package's resolvability).
 */
class LicenseRevoked
{
    public function __construct(public LicenseData $license) {}
}
