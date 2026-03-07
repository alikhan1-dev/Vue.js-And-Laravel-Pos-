<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Require POS Session
    |--------------------------------------------------------------------------
    |
    | When true, all sales and payments created through POS flows must include
    | a valid pos_session_id linked to an open session. This prevents orphaned
    | transactions and ensures cash reconciliation integrity.
    |
    | Set to false for environments where POS sessions are optional (e.g.
    | B2B invoice flows, API-only integrations).
    |
    */
    'require_session' => env('POS_REQUIRE_SESSION', false),

    /*
    |--------------------------------------------------------------------------
    | Require Device ID
    |--------------------------------------------------------------------------
    |
    | When true, every sale must include a device_id identifying the POS
    | terminal. Useful for fraud tracking and offline sync debugging.
    |
    */
    'require_device_id' => env('POS_REQUIRE_DEVICE_ID', false),

];
