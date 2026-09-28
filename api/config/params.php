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
    'fileStorage.mimeTypes' => ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'],
    // Where data/schema lives (rules.yaml: legal sensor limits; violation_categories.yaml).
    'dataSchemaDir' => (string) env('DATA_SCHEMA_DIR', '../data/schema'),
    // data/reference: the offline map's state and district outlines (Phase 5B).
    'dataReferenceDir' => (string) env('DATA_REFERENCE_DIR', '../data/reference'),
    // The prototype's scoring weights, bands and windows, env-driven as in the old backend (brief
    // Phase 2: "scoring weights stay env-driven"). Demo settings, not legal values.
    'score.weightPpe' => (float) env('WEIGHT_PPE', 5.0),
    'score.weightEnv' => (float) env('WEIGHT_ENV', 3.0),
    'score.bandLowMin' => 80,
    'score.bandMediumMin' => 50,
    // A breach counts against the score only while younger than this (0.0033 h = 12 s: six 2 s
    // simulator ticks). 0 counts every breach on record.
    'score.breachWindowHours' => (float) env('BREACH_WINDOW_HOURS', 0.0033),
    // Inspection prioritisation: urgency = (100 - score) + max(0, trend delta) * weightTrend.
    'priority.weightTrend' => (float) env('WEIGHT_TREND', 2.0),
    'priority.trendWindowHours' => (int) env('TREND_WINDOW_HOURS', 24),
    // Government overview: with no state selected the board shows the worst N mines nationally.
    'dashboard.nationalBoardLimit' => 5,
    'dashboard.inspectionPreview' => 3,
    // Bucket size of the breach-frequency chart.
    'sensor.breachBucketHours' => 6,
    // ai-service (PPE vision). Down or slow -> the API degrades, never crashes (brief section 2).
    'ai.baseUrl' => (string) env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
    'ai.timeoutSeconds' => (float) env('AI_SERVICE_TIMEOUT', 20),
    // Phase 7: the anomaly detectors and the risk model. auto = ask ai-service, fall back to the PHP
    // twins when it is down; php = never call it (tests, the evaluation); ai-service = no fallback.
    'ai.engine' => (string) env('AI_ENGINE', 'auto'),
    'ai.detectorTimeoutSeconds' => (float) env('AI_DETECTOR_TIMEOUT', 30),
    // Signed file links (annotated frames, proof images) stay valid this long.
    'files.linkTtlSeconds' => 3600,
    // Public grievance endpoints (no login): fixed-window limits per client IP, and the upload
    // limits for a grievance attachment (stricter than the general file store).
    'grievance.submitPerHour' => (int) env('GRIEVANCE_SUBMIT_PER_HOUR', 5),
    'grievance.trackPerMinute' => (int) env('GRIEVANCE_TRACK_PER_MINUTE', 20),
    'grievance.maxFileBytes' => 5 * 1024 * 1024,
    'grievance.fileMimeTypes' => ['application/pdf', 'image/jpeg', 'image/png'],
];
