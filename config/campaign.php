<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Campaign Data Duplicate Strategy
    |--------------------------------------------------------------------------
    |
    | Supported values: update, reject, merge
    |
    */
    'duplicate_strategy' => env('CAMPAIGN_DUPLICATE_STRATEGY', 'update'),

    /*
    |--------------------------------------------------------------------------
    | Data Ingestion Chunk Size
    |--------------------------------------------------------------------------
    |
    | The API ingestion endpoint dispatches one job per chunk.
    |
    */
    'ingest_chunk_size' => (int) env('CAMPAIGN_INGEST_CHUNK_SIZE', 500),

    /*
    |--------------------------------------------------------------------------
    | API Rate Limits
    |--------------------------------------------------------------------------
    |
    | Per-client request ceilings. Tune these per environment.
    |
    */
    'rate_limits' => [
        'create_campaign_per_minute' => (int) env('RATE_LIMIT_CREATE_CAMPAIGN_PER_MINUTE', 60),
        'ingest_data_per_minute' => (int) env('RATE_LIMIT_INGEST_DATA_PER_MINUTE', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idempotency Configuration
    |--------------------------------------------------------------------------
    */
    'idempotency' => [
        'header' => env('CAMPAIGN_IDEMPOTENCY_HEADER', 'Idempotency-Key'),
        'ttl_hours' => (int) env('CAMPAIGN_IDEMPOTENCY_TTL_HOURS', 48),
    ],
];
