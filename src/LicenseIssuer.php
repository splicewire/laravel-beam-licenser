<?php

namespace Splicewire\Beam\Licenser;

use Illuminate\Database\Eloquent\Model;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseTemplate;
use Splicewire\Beam\Licenser\Data\LicenseData;
use Splicewire\Beam\Licenser\Events\LicenseIssued;
use Splicewire\Beam\Licenser\Events\LicenseRevoked;

/**
 * The server/issuer role (marketplace fulfillment issues a real license — this
 * is not a phone-home-only consumer). Wraps `LucaLongo\Licensing\Models\License`
 * (`masterix21/laravel-licensing`), the only place in this package that writes
 * to that model.
 *
 * `issue()`/`revoke()` each fire a domain event ({@see LicenseIssued}/{@see LicenseRevoked},
 * ticket 11) as their last step — the ONLY way any other package learns a license changed state.
 * This package never calls into a consumer directly (e.g. it has no knowledge whatsoever of
 * `splicewire/laravel-beam-market-packages`' distribution registry); a consumer reacts to the
 * event and reads {@see LicenseData::$licensableType}/{@see LicenseData::$licensableId} to decide
 * whether/how it applies.
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

        $issued = LicenseData::fromLicense($license->fresh(), $licenseKey);

        event(new LicenseIssued($issued));

        return $issued;
    }

    /**
     * A plain read of an issued license by its public `uid`, projected exactly like
     * {@see issue()}/{@see revoke()} (the plain-text key is never known outside issuance, so it
     * always comes back null here). Ticket 13's own reason to exist: a consumer (e.g.
     * `laravel-beam-market`'s pause-not-cancel `SellerConnectRestricted` listener) that needs
     * `LicenseData::$graceUntil` as a buffer window has no other sanctioned way to read a license
     * back without reaching into the vendor model directly.
     */
    public function find(string $licenseId): ?LicenseData
    {
        $license = License::where('uid', $licenseId)->first();

        return $license !== null ? LicenseData::fromLicense($license) : null;
    }

    /**
     * Mechanism-only: mark the license revoked so the next client phone-home
     * reflects it, and fire {@see LicenseRevoked} so a distribution-gateway
     * (or any other) consumer can react. Deciding WHEN to revoke is explicitly
     * out of this ticket's scope — no caller is wired here; later tickets
     * (review-workflow takedown, Tower plan downgrade) own that policy and
     * will call this.
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

        $revoked = LicenseData::fromLicense($license->fresh());

        event(new LicenseRevoked($revoked));

        return $revoked;
    }
}
