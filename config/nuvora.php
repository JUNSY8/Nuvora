<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Default presentation timezone
    |--------------------------------------------------------------------------
    |
    | Timestamps are stored in UTC. Tenant-local rules use the parking lot
    | timezone once that module exists. America/Bogota is the initial default.
    |
    */

    'default_timezone' => env('NUVORA_DEFAULT_TIMEZONE', 'America/Bogota'),

    /*
    |--------------------------------------------------------------------------
    | Payments
    |--------------------------------------------------------------------------
    |
    | Minutes an exit quote keeps its price while the cashier collects
    | (ADR-019) and minutes a payment link stays valid (ADR-020).
    |
    */

    'payments' => [
        'quote_hold_minutes' => (int) env('NUVORA_QUOTE_HOLD_MINUTES', 5),
        'link_minutes' => (int) env('NUVORA_PAYMENT_LINK_MINUTES', 60),
    ],

    'features' => [
        'reservations' => (bool) env('FEATURE_RESERVATIONS', false),
        'lpr' => (bool) env('FEATURE_LPR', false),
        'online_payments' => (bool) env('FEATURE_ONLINE_PAYMENTS', false),
        'advanced_reports' => (bool) env('FEATURE_ADVANCED_REPORTS', false),
        'ai_assistant' => (bool) env('FEATURE_AI_ASSISTANT', false),
        'dynamic_pricing' => (bool) env('FEATURE_DYNAMIC_PRICING', false),
        'electronic_invoicing' => (bool) env('FEATURE_ELECTRONIC_INVOICING', false),
    ],

];
