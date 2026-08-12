<?php

namespace Splicewire\Beam\Licenser\Data;

use LucaLongo\Licensing\Models\License;
use Spatie\LaravelData\Data;

/**
 * The server/issuer-side view of an issued license.
 *
 * Named `LicenseData` — not `License` — specifically because the vendor package
 * (`masterix21/laravel-licensing`) ships its OWN Eloquent model at
 * `LucaLongo\Licensing\Models\License`. A caller that imports both this Data
 * class and the vendor model in the same file (any `LicenseIssuer` consumer
 * that also wants to query the vendor model directly) needs the two names to
 * never collide — `LicenseData` vs `License` reads immediately as "the DTO"
 * vs "the row."
 *
 * `licenseKey` is populated ONLY by {@see LicenseIssuer::issue()}, right after
 * `License::createWithKey()` — the plain-text key exists in memory for that one
 * request and is never persisted or re-derivable afterward (the vendor stores
 * only a hash). Every other projection (revoke, a re-fetch) carries it null.
 *
 * `graceUntil` (splicewire-marketplace-build ticket 13) surfaces the vendor's own SERVER-side
 * grace concept — `LucaLongo\Licensing\Models\License::getGraceDays()`/`gracePeriodExpired()`
 * (`expires_at->addDays(getPolicy('grace_days'))`), already live vendor machinery — onto this
 * DTO for the first time. Deliberately NOT the same thing as this package's own CLIENT-side
 * offline-grace (`LicenseVerificationData::$graceExpiresAt`, timed from the first unreachable
 * phone-home): that one is about an install that can't reach the server; `graceUntil` here is
 * the issuer's own record of how long a license stays usable (`LicenseStatus::Grace` is
 * `isUsable()`) past its `expiresAt` before it truly lapses to `Expired`. Null when `expiresAt`
 * is null (a non-expiring license has no grace boundary to compute).
 */
class LicenseData extends Data
{
    public function __construct(
        public string $id,
        public string $status,
        public ?string $licenseKey,
        public ?int $templateId,
        public ?string $licensableType,
        public ?string $licensableId,
        public ?string $activatedAt,
        public ?string $expiresAt,
        public ?string $graceUntil,
        public int $maxUsages,
    ) {}

    public static function fromLicense(License $license, ?string $licenseKey = null): self
    {
        return new self(
            id: $license->uid,
            status: $license->status->value,
            licenseKey: $licenseKey,
            templateId: $license->template_id,
            licensableType: $license->licensable_type,
            licensableId: $license->licensable_id !== null ? (string) $license->licensable_id : null,
            activatedAt: $license->activated_at?->toIso8601String(),
            expiresAt: $license->expires_at?->toIso8601String(),
            graceUntil: $license->expires_at?->copy()->addDays($license->getGraceDays())->toIso8601String(),
            maxUsages: $license->max_usages,
        );
    }
}
