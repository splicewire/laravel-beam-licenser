<?php

namespace Splicewire\Beam\Licenser\Enums;

use Splicewire\Beam\Licenser\Data\LicenseVerificationData;
use Splicewire\Beam\Licenser\LicenseClient;

/**
 * Where a {@see LicenseVerificationData} verdict
 * came from — the axis {@see LicenseClient} uses to
 * decide whether an unreachable server denies or grace-passes.
 */
enum VerificationSource: string
{
    /** The license server answered the request (reachable), explicitly. */
    case Online = 'online';

    /** The server was unreachable, but the offline-grace window has not elapsed. */
    case Grace = 'grace';

    /** Denied — either the server explicitly said no, or grace has elapsed. */
    case Denied = 'denied';
}
