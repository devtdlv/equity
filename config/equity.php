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

];
