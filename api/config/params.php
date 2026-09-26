<?php

declare(strict_types=1);

return [
    'jwt.issuer' => (string) env('JWT_ISSUER', 'coalshield-api'),
    'jwt.ttlHours' => (int) env('JWT_TTL_HOURS', 12),
    'cors.origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ORIGINS', 'http://localhost:5173'))))),
    'listing.defaultPerPage' => 50,
    'listing.maxPerPage' => 200,
    'languages' => ['en', 'hi', 'bn', 'or', 'te', 'mr'],
    'dataOutDir' => (string) env('DATA_OUT_DIR', '../data/out'),
    'fileStorage.dir' => (string) env('FILE_STORAGE_DIR', '@app/storage/files'),
    'fileStorage.maxBytes' => (int) env('FILE_MAX_BYTES', 10 * 1024 * 1024),
    'fileStorage.mimeTypes' => ['application/pdf', 'image/jpeg', 'image/png'],
    // The prototype's scoring weights and risk bands (env-driven in the old backend, brief rule on
    // weights). They are demo settings, not legal values; the legal sensor limits live in
    // data/schema/rules.yaml.
    'score.weightPpe' => 5.0,
    'score.weightEnv' => 3.0,
    'score.bandLowMin' => 80,
    'score.bandMediumMin' => 50,
    'score.breachWindowHours' => 0.0033,
];
