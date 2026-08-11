<?php

namespace Splicewire\Beam\Licenser\Data;

use Spatie\LaravelData\Data;
use Splicewire\Beam\Licenser\Enums\VerificationSource;
use Splicewire\Beam\Licenser\LicenseClient;

/**
 * The client/verifier-side outcome of a {@see LicenseClient}
 * phone-home check.
 *
 * Named `LicenseVerificationData` — not `LicenseVerification` or anything that
 * shadows the vendor's own `LucaLongo\Licensing\Models\License` model — for the
 * same reason as {@see LicenseData}: a caller may import the vendor model
 * alongside this DTO in one scope (e.g. a Feature test asserting against both
 * the server-side row and the client-side verdict) without an alias.
 *
 * `source` is the load-bearing field: {@see VerificationSource::Online} means
 * the server was reached and answered; {@see VerificationSource::Grace} means
 * the server was unreachable but the offline-grace window (client config
 * `licensing-client.grace_period_days`) has not elapsed; {@see
 * VerificationSource::Denied} covers BOTH an explicit server denial (revoked/
 * suspended/expired — reachable, said no) and grace having elapsed (unreachable,
 * too long) — `reason` disambiguates which.
 */
class LicenseVerificationData extends Data
{
    public function __construct(
        public bool $valid,
        public VerificationSource $source,
        public ?string $status,
        public ?string $reason,
        public ?string $graceExpiresAt,
        public string $verifiedAt,
    ) {}

    public static function online(?string $status, string $verifiedAt): self
    {
        return new self(
            valid: true,
            source: VerificationSource::Online,
            status: $status,
            reason: null,
            graceExpiresAt: null,
            verifiedAt: $verifiedAt,
        );
    }

    public static function grace(string $graceExpiresAt, string $verifiedAt): self
    {
        return new self(
            valid: true,
            source: VerificationSource::Grace,
            status: null,
            reason: 'license_server_unreachable',
            graceExpiresAt: $graceExpiresAt,
            verifiedAt: $verifiedAt,
        );
    }

    public static function denied(string $reason, string $verifiedAt, ?string $status = null): self
    {
        return new self(
            valid: false,
            source: VerificationSource::Denied,
            status: $status,
            reason: $reason,
            graceExpiresAt: null,
            verifiedAt: $verifiedAt,
        );
    }
}
