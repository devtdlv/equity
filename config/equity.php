<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SQLite financial BEGIN IMMEDIATE
    |--------------------------------------------------------------------------
    |
    | When true, FinancialDatabaseTransaction requests BEGIN IMMEDIATE for
    | outermost SQLite financial write transactions. Default remains false
    | (DEFERRED). Does not affect MariaDB/MySQL. Do not enable in production
    | until Phase 4C service wiring and concurrency verification are complete.
    |
    | This value is not read from .env in Phase 4C.1 — keep the default false.
    |
    */

    'sqlite' => [
        'financial_immediate' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Visual audit (Phase 6) disposable environment
    |--------------------------------------------------------------------------
    |
    | Enabled only via .env.visual-audit (EQUITY_VISUAL_AUDIT=true). Never wire
    | VisualAuditSeeder into DatabaseSeeder. allow_memory_for_tests is for Pest
    | only and must stay false outside automated tests.
    |
    */

    'visual_audit' => [
        'enabled' => (bool) env('EQUITY_VISUAL_AUDIT', false),
        'demo_email' => 'demo@equity.local',
        'allow_memory_for_tests' => false,
    ],

];
