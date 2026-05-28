<?php

use Laravel\Horizon\Horizon;

/*
|──────────────────────────────────────────────────────────────────────────────
| Horizon Configuration
|
| Queue worker pools, supervisors, and dashboard settings for MetaIntel.
| Docs: https://laravel.com/docs/horizon
|──────────────────────────────────────────────────────────────────────────────
*/

return [

    'domain'    => env('HORIZON_DOMAIN'),
    'path'      => env('HORIZON_PATH', 'horizon'),
    'use'       => 'default',
    'prefix'    => env('HORIZON_PREFIX', 'metaintel_horizon:'),
    'middleware'=> ['web'],
    'waits'     => ['redis:default' => 60],
    'trim'      => [
        'recent'        => 60,   // minutes
        'pending'       => 60,
        'completed'     => 60,
        'recent_failed' => 10080, // 1 week
        'failed'        => 10080,
        'monitored'     => 10080,
    ],
    'silenced'  => [],
    'metrics'   => [
        'trim_snapshots' => ['job' => 24, 'queue' => 24],
    ],
    'fast_termination' => false,
    'memory_limit' => 512, // MB per worker

    'defaults' => [
        'supervisor-1' => [
            'connection'       => 'redis',
            'queue'            => ['default'],
            'balance'          => 'auto',
            'autoScalingStrategy' => 'time',
            'maxProcesses'     => 4,
            'maxTime'          => 0,
            'maxJobs'          => 0,
            'memory'           => 128,
            'tries'            => 3,
            'timeout'          => 60,
            'nice'             => 0,
        ],
    ],

    'environments' => [
        'production' => [

            // ── High-priority image processing ────────────────────────────────
            'supervisor-image-processing' => [
                'connection'       => 'redis',
                'queue'            => ['image-processing'],
                'balance'          => 'auto',
                'autoScalingStrategy' => 'time',
                'minProcesses'     => 2,
                'maxProcesses'     => 8,
                'balanceMaxShift'  => 1,
                'balanceCooldown'  => 3,
                'memory'           => 256,
                'tries'            => 3,
                'timeout'          => 300, // 5 min for full pipeline
                'nice'             => 0,
            ],

            // ── Batch processing (lower priority, more concurrent) ────────────
            'supervisor-batch' => [
                'connection'       => 'redis',
                'queue'            => ['batch-processing'],
                'balance'          => 'auto',
                'autoScalingStrategy' => 'size',
                'minProcesses'     => 1,
                'maxProcesses'     => 6,
                'memory'           => 256,
                'tries'            => 3,
                'timeout'          => 600,
                'nice'             => 5, // lower priority than single image
            ],

            // ── Report generation ──────────────────────────────────────────────
            'supervisor-reports' => [
                'connection'       => 'redis',
                'queue'            => ['reports'],
                'balance'          => 'simple',
                'processes'        => 2,
                'memory'           => 512, // PDF gen is memory hungry
                'tries'            => 2,
                'timeout'          => 180,
                'nice'             => 5,
            ],

            // ── Elasticsearch indexing ─────────────────────────────────────────
            'supervisor-indexing' => [
                'connection'       => 'redis',
                'queue'            => ['indexing'],
                'balance'          => 'auto',
                'minProcesses'     => 1,
                'maxProcesses'     => 4,
                'memory'           => 128,
                'tries'            => 3,
                'timeout'          => 30,
                'nice'             => 10,
            ],

            // ── Webhooks (fast, fire-and-forget) ──────────────────────────────
            'supervisor-webhooks' => [
                'connection'       => 'redis',
                'queue'            => ['webhooks'],
                'balance'          => 'simple',
                'processes'        => 3,
                'memory'           => 64,
                'tries'            => 3,
                'timeout'          => 15,
                'nice'             => 0,
            ],

            // ── Image sanitization ────────────────────────────────────────────
            'supervisor-sanitization' => [
                'connection'       => 'redis',
                'queue'            => ['sanitization'],
                'balance'          => 'simple',
                'processes'        => 2,
                'memory'           => 128,
                'tries'            => 2,
                'timeout'          => 60,
                'nice'             => 5,
            ],

            // ── General default queue ─────────────────────────────────────────
            'supervisor-default' => [
                'connection'       => 'redis',
                'queue'            => ['default'],
                'balance'          => 'auto',
                'minProcesses'     => 1,
                'maxProcesses'     => 4,
                'memory'           => 128,
                'tries'            => 3,
                'timeout'          => 60,
                'nice'             => 0,
            ],
        ],

        'local' => [
            'supervisor-local' => [
                'connection'  => 'redis',
                'queue'       => [
                    'image-processing', 'batch-processing', 'reports',
                    'indexing', 'webhooks', 'sanitization', 'default',
                ],
                'balance'     => 'auto',
                'minProcesses'=> 1,
                'maxProcesses'=> 4,
                'memory'      => 256,
                'tries'       => 3,
                'timeout'     => 300,
                'nice'        => 0,
            ],
        ],
    ],
];
