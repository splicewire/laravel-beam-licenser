<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Splicewire\Beam\Licenser\LicenseClient;

/**
 * The phone-home client's offline-grace behavior: an unreachable license
 * server (simulated via `Http::fake()` throwing a real
 * `ConnectionException`, distinct from a reachable-but-denying HTTP error
 * response) still verifies valid within the configured grace window, and
 * denies once the window has elapsed — proven by traveling real time forward
 * with `Carbon::setTestNow()` between two `verify()` calls against the SAME
 * unreachable-server fake, not two different fakes.
 */
it('stays valid within the offline-grace window against an unreachable server', function () {
    config(['licensing-client.grace_period_days' => 3]);

    Http::fake(function () {
        throw new ConnectionException('Could not resolve host: licensing.test');
    });

    $client = app(LicenseClient::class);

    $verification = $client->verify();

    expect($verification->valid)->toBeTrue()
        ->and($verification->source->value)->toBe('grace')
        ->and($verification->graceExpiresAt)->not->toBeNull();
});

it('denies once the offline-grace window has elapsed', function () {
    config(['licensing-client.grace_period_days' => 3]);

    Http::fake(function () {
        throw new ConnectionException('Could not resolve host: licensing.test');
    });

    $client = app(LicenseClient::class);

    $start = now();

    $withinGrace = $client->verify();
    expect($withinGrace->valid)->toBeTrue()
        ->and($withinGrace->source->value)->toBe('grace');

    // Travel past the grace window from the FIRST unreachable phone-home —
    // the grace clock starts when unreachability was first detected, not at
    // each subsequent poll.
    Carbon::setTestNow($start->copy()->addDays(4));

    $afterGrace = $client->verify();

    expect($afterGrace->valid)->toBeFalse()
        ->and($afterGrace->source->value)->toBe('denied')
        ->and($afterGrace->reason)->toBe('offline_grace_period_elapsed');

    Carbon::setTestNow();
});

it('returns to online and clears any grace window once the server is reachable again', function () {
    config(['licensing-client.grace_period_days' => 3]);

    // `Http::fake()` calls merge/append onto previously registered stubs
    // rather than replacing them, so the "server comes back" half of this
    // test is one stateful fake (unreachable on the first call, reachable on
    // the second) rather than two separate `Http::fake()` calls.
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        if ($calls === 1) {
            throw new ConnectionException('Could not resolve host: licensing.test');
        }

        return Http::response([
            'success' => true,
            'data' => ['license' => ['status' => 'active', 'features' => [], 'entitlements' => []], 'usage' => ['status' => 'active']],
        ], 200);
    });

    $client = app(LicenseClient::class);
    $client->verify(); // enters grace

    $backOnline = $client->verify();

    expect($backOnline->valid)->toBeTrue()
        ->and($backOnline->source->value)->toBe('online');
});
