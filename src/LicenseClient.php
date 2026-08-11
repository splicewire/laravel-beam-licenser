<?php

namespace Splicewire\Beam\Licenser;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use LucaLongo\LaravelLicensingClient\Exceptions\LicensingException;
use LucaLongo\LaravelLicensingClient\Services\FingerprintGenerator;
use LucaLongo\LaravelLicensingClient\Services\LicensingApiClient;
use LucaLongo\LaravelLicensingClient\Services\TokenStorage;
use Splicewire\Beam\Licenser\Data\LicenseVerificationData;

/**
 * The client/verifier role: activate/verify/phone-home with an offline-grace
 * window. Wraps `masterix21/laravel-licensing-client`'s low-level
 * {@see LicensingApiClient} (the HTTP transport + exception mapping) rather
 * than its high-level `LaravelLicensingClient` facade, because that facade's
 * `validate()`/`isValid()` are entirely OFFLINE (they read a locally stored
 * PASETO token) — this class's job is the ONLINE phone-home + the decision
 * about what an unreachable server means, which the vendor client doesn't ship
 * as a single verdict.
 *
 * The vendor's own exception split does the hard work for us:
 * {@see ConnectionException} means the server was never reached (DNS/timeout —
 * grace-eligible); {@see LicensingException} means it WAS reached and answered
 * no (revoked/suspended/expired/etc., via `LicensingApiClient::mapRequestException()`
 * translating the HTTP status) — that is never grace-eligible, it denies
 * immediately. Conflating the two would let a revoked license ride out the
 * grace window as if the server were merely offline.
 */
class LicenseClient
{
    public function __construct(
        private LicensingApiClient $apiClient,
        private FingerprintGenerator $fingerprintGenerator,
        private TokenStorage $tokenStorage,
    ) {}

    /**
     * Activate this install's license with the server (registers a seat/usage
     * for this fingerprint).
     */
    public function activate(?string $licenseKey = null): LicenseVerificationData
    {
        return $this->phoneHome('activate', $licenseKey);
    }

    /**
     * Phone-home verify: ask the license server whether the license is still
     * valid right now. See class docblock for the online/grace/denied split.
     */
    public function verify(?string $licenseKey = null): LicenseVerificationData
    {
        return $this->phoneHome('validate', $licenseKey);
    }

    private function phoneHome(string $mode, ?string $licenseKey): LicenseVerificationData
    {
        $licenseKey ??= config('licensing-client.license_key');
        $fingerprint = $this->fingerprintGenerator->generate();
        $now = Carbon::now();

        try {
            $response = $mode === 'activate'
                ? $this->apiClient->activate($licenseKey, $fingerprint, $this->fingerprintGenerator->getMetadata())
                : $this->apiClient->validate($licenseKey, $fingerprint);

            $this->clearGracePeriod();

            return LicenseVerificationData::online(
                status: $response['data']['license']['status'] ?? null,
                verifiedAt: $now->toIso8601String(),
            );
        } catch (ConnectionException) {
            return $this->graceOrDenied($now);
        } catch (LicensingException $e) {
            // Reachable, explicit deny — authoritative, never grace-eligible.
            $this->clearGracePeriod();

            return LicenseVerificationData::denied(
                reason: $e->getMessage(),
                verifiedAt: $now->toIso8601String(),
            );
        }
    }

    private function graceOrDenied(Carbon $now): LicenseVerificationData
    {
        $graceData = $this->tokenStorage->getGracePeriodData();

        if (! $graceData) {
            $graceData = ['started_at' => $now->toIso8601String()];
            $this->tokenStorage->storeGracePeriodData($graceData);
        }

        $graceDays = (int) config('licensing-client.grace_period_days', 7);
        $graceUntil = Carbon::parse($graceData['started_at'])->addDays($graceDays);

        if ($graceUntil->isFuture()) {
            return LicenseVerificationData::grace(
                graceExpiresAt: $graceUntil->toIso8601String(),
                verifiedAt: $now->toIso8601String(),
            );
        }

        return LicenseVerificationData::denied(
            reason: 'offline_grace_period_elapsed',
            verifiedAt: $now->toIso8601String(),
        );
    }

    /**
     * A successful phone-home (online, either way) clears any in-progress
     * grace window — we're back in contact with the server, its answer is
     * authoritative again.
     */
    private function clearGracePeriod(): void
    {
        $this->tokenStorage->storeGracePeriodData([]);
    }
}
