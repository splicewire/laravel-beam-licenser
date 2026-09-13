<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use LucaLongo\Licensing\Models\License;
use Splicewire\Beam\Licenser\Events\LicenseIssued;
use Splicewire\Beam\Licenser\Events\LicenseRevoked;
use Splicewire\Beam\Licenser\LicenseClient;
use Splicewire\Beam\Licenser\LicenseIssuer;

/**
 * The end-to-end server (issuer) + client (verifier) round trip: issue a
 * license, phone-home verify it (valid), revoke it via LicenseIssuer::revoke()
 * (the ONLY call site for revoke() in this whole package — mechanism-only, no
 * production caller), then phone-home verify again and confirm the SAME
 * license now denies.
 *
 * The fake HTTP responses are driven by re-reading the real `License` row's
 * current status after each issuer action — so "the next client phone-home
 * reflects the revocation" is proven against genuine server-side state, not a
 * canned response sequence that happens to match the test's own expectations.
 */
function fakeLicensingServer(License $license): void
{
    Http::fake(function ($request) use ($license) {
        $license->refresh();

        if (in_array($license->status->value, ['suspended', 'cancelled'], true)) {
            return Http::response([
                'success' => false,
                'error' => ['code' => 'SUSPENDED_LICENSE', 'message' => 'License is not active'],
            ], 423);
        }

        return Http::response([
            'success' => true,
            'data' => [
                'license' => [
                    'id' => $license->uid,
                    'status' => $license->status->value,
                    'features' => [],
                    'entitlements' => [],
                ],
                'usage' => [
                    'id' => 1,
                    'status' => 'active',
                ],
            ],
        ], 200);
    });
}

it('issues, verifies, revokes, and reflects the revocation on the next phone-home', function () {
    $issuer = app(LicenseIssuer::class);
    $client = app(LicenseClient::class);

    $issued = $issuer->issue(maxUsages: 1, expiresAt: now()->addYear());

    expect($issued->status)->toBe('active')
        ->and($issued->licenseKey)->not->toBeNull();

    $license = License::where('uid', $issued->id)->firstOrFail();

    fakeLicensingServer($license);

    $verification = $client->verify(licenseKey: $issued->licenseKey);

    expect($verification->valid)->toBeTrue()
        ->and($verification->source->value)->toBe('online')
        ->and($verification->status)->toBe('active');

    $revoked = $issuer->revoke($issued->id);

    expect($revoked->status)->toBe('cancelled');

    $secondVerification = $client->verify(licenseKey: $issued->licenseKey);

    expect($secondVerification->valid)->toBeFalse()
        ->and($secondVerification->source->value)->toBe('denied');
});

/**
 * Ticket 11: `LicenseIssuer::issue()`/`revoke()` fire `LicenseIssued`/`LicenseRevoked` as their
 * last step — the ONLY seam a consumer package (e.g. `laravel-beam-market`'s
 * `PackageDistributionGatewayResolver` port) reacts through. Asserts the event payload carries the
 * same `LicenseData` projection the caller itself receives, including the licensable reference.
 */
it('fires LicenseIssued and LicenseRevoked carrying the same LicenseData the caller receives', function () {
    Event::fake([LicenseIssued::class, LicenseRevoked::class]);

    $issuer = app(LicenseIssuer::class);
    $licensable = License::createWithKey();

    $issued = $issuer->issue(licensable: $licensable, maxUsages: 1);

    Event::assertDispatched(LicenseIssued::class, function (LicenseIssued $event) use ($issued, $licensable) {
        return $event->license->id === $issued->id
            && $event->license->licensableType === $licensable->getMorphClass()
            && $event->license->licensableId === (string) $licensable->getKey();
    });

    $revoked = $issuer->revoke($issued->id);

    Event::assertDispatched(LicenseRevoked::class, fn (LicenseRevoked $event) => $event->license->id === $revoked->id
        && $event->license->status === 'cancelled');
});

it('revokes on the requested connection without changing the ambient database', function () {
    Event::fake([LicenseRevoked::class]);
    $issuer = app(LicenseIssuer::class);
    $issued = $issuer->issue();

    config(['database.connections.unrelated' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
    DB::setDefaultConnection('unrelated');

    try {
        $revoked = $issuer->revoke($issued->id, 'testing');

        expect($revoked->status)->toBe('cancelled')
            ->and($revoked->licenseKey)->toBeNull()
            ->and(License::on('testing')->where('uid', $issued->id)->firstOrFail()->status->value)->toBe('cancelled')
            ->and(DB::getDefaultConnection())->toBe('unrelated');
        Event::assertDispatched(LicenseRevoked::class, fn (LicenseRevoked $event) => $event->license->id === $issued->id);
    } finally {
        DB::setDefaultConnection('testing');
        DB::purge('unrelated');
    }
});
