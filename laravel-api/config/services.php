<?php
// config/services.php additions for MetaIntel microservices

return [

    // ── Standard Laravel services ─────────────────────────────────────────────
    'mailgun'  => ['domain' => env('MAILGUN_DOMAIN'), 'secret' => env('MAILGUN_SECRET'), 'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net')],
    'postmark' => ['token' => env('POSTMARK_TOKEN')],
    'ses'      => ['key' => env('AWS_ACCESS_KEY_ID'), 'secret' => env('AWS_SECRET_ACCESS_KEY'), 'region' => env('AWS_DEFAULT_REGION', 'us-east-1')],
    'slack'    => ['notifications' => ['bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'), 'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL')]],

    // ── MetaIntel Python Microservices ────────────────────────────────────────
    'metadata' => [
        'url'     => env('METADATA_SERVICE_URL',    'http://metadata-service:8001'),
        'timeout' => env('METADATA_SERVICE_TIMEOUT', 60),
    ],
    'forensics' => [
        'url'     => env('FORENSICS_SERVICE_URL',    'http://forensics-service:8002'),
        'timeout' => env('FORENSICS_SERVICE_TIMEOUT', 120),
    ],
    'geospatial' => [
        'url'     => env('GEOSPATIAL_SERVICE_URL',    'http://geospatial-service:8003'),
        'timeout' => env('GEOSPATIAL_SERVICE_TIMEOUT', 30),
    ],

    // ── Elasticsearch ─────────────────────────────────────────────────────────
    'elasticsearch' => [
        'host'   => env('ELASTICSEARCH_HOST', 'elasticsearch'),
        'port'   => env('ELASTICSEARCH_PORT', 9200),
        'scheme' => env('ELASTICSEARCH_SCHEME', 'http'),
        'user'   => env('ELASTICSEARCH_USER'),
        'pass'   => env('ELASTICSEARCH_PASSWORD'),
    ],

    // ── Geocoding APIs ────────────────────────────────────────────────────────
    'mapbox' => [
        'token' => env('MAPBOX_TOKEN'),
    ],
    'google' => [
        'maps_api_key' => env('GOOGLE_MAPS_API_KEY'),
    ],

];
