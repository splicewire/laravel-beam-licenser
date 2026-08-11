<?php

namespace Splicewire\Beam\Licenser;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;
use Splicewire\Beam\Licenser\Data\LicenseData;

/**
 * The server/issuer role (marketplace fulfillment issues a real license — this
 * is not a phone-home-only consumer). Wraps `LucaLongo\Licensing\Models\License`
 * (`masterix21/laravel-licensing`), the only place in this package that writes
 * to that model.
 */
class LicenseIssuer
{
    /**
     * Issue a new license and activate it. The plain-text key lives only on the
     * returned {@see LicenseData} — the vendor persists only its hash, so this
     * is the one moment the caller can hand it to the licensee.
     */
    public function issue(
        ?LicenseTemplate $template = null,
        ?Model $licensable = null,
        int $maxUsages = 1,
        ?\DateTimeInterface $expiresAt = null,
        array $meta = [],
    ): LicenseData {
        $license = License::createWithKey(array_filter([
            'template_id' => $template?->id,
            'licensable_type' => $licensable?->getMorphClass(),
            'licensable_id' => $licensable?->getKey(),
            'max_usages' => $maxUsages,
            'expires_at' => $expiresAt,
            'meta' => $meta,
        ], static fn ($value) => $value !== null && $value !== []));

        $licenseKey = $license->license_key;

        $license->activate();

        return LicenseData::fromLicense($license->fresh(), $licenseKey);
    }

    /**
     * Mechanism-only: mark the license revoked so the next client phone-home
     * reflects it. Deciding WHEN to revoke is explicitly out of this ticket's
     * scope — no caller is wired here; later tickets (review-workflow
     * takedown, Tower plan downgrade) own that policy and will call this.
     *
     * The vendor's `LicenseStatus` enum has no `Revoked` case — the two
     * terminal-ish states are `Suspended` (implies pausable/reversible) and
     * `Cancelled` (terminal, non-reversible). `cancel()` is the honest mapping
     * for "revoked": {@see LicenseStatus::isUsable()}
     * excludes it (only `Active`/`Grace` are usable), so a phone-home
     * `validate()` denies immediately — see the server's own
     * `LicenseController::guardLicenseState()`, which 423s both `Suspended`
     * and `Cancelled`.
     */
    public function revoke(string $licenseId): LicenseData
    {
        $license = License::where('uid', $licenseId)->firstOrFail();

        $license->cancel();

        return LicenseData::fromLicense($license->fresh());
    }
}
