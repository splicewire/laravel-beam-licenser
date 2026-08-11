<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Deployment mode
    |--------------------------------------------------------------------------
    |
    | The seam a host application's own AppServiceProvider flips (the same shape
    | as `splicewire.entitlements.free_plan` in splicewire-app): a self-hosted
    | install that has a phone-home license sets this true and binds
    | LicenserEntitlementGate; a SaaS install leaves it false (or never even
    | requires this package) and keeps DefaultEntitlementGate/the plan-tier
    | cascade. This package's own service provider reads it to decide whether to
    | bind ANYTHING from itself into the EntitlementGate seam — the package
    | stays inert, never a scattered `if (config(...))` at each call site.
    |
    */
    'enabled' => env('BEAM_LICENSER_ENABLED', false),

];
