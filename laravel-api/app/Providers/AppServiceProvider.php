<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\{Route, Gate, Queue};
use Illuminate\Queue\Events\JobFailed;
use App\Services\{
    MetadataExtractionService,
    ForensicsService,
    GeospatialService,
    AnomalyDetectionService,
    ImageStorageService,
    MetadataSearchService,
    ReportGeneratorService,
};

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // ── Bind services as singletons ───────────────────────────────────────
        $this->app->singleton(MetadataExtractionService::class);
        $this->app->singleton(ForensicsService::class);
        $this->app->singleton(GeospatialService::class);
        $this->app->singleton(AnomalyDetectionService::class);
        $this->app->singleton(ImageStorageService::class);
        $this->app->singleton(MetadataSearchService::class);
        $this->app->singleton(ReportGeneratorService::class);
    }

    public function boot(): void
    {
        // ── Enforce HTTPS in production ───────────────────────────────────────
        if ($this->app->environment('production')) {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // ── Register event listeners ──────────────────────────────────────────
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\ImageProcessed::class,
            [\App\Listeners\DispatchWebhooks::class, 'handleImageProcessed']
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\BatchCompleted::class,
            [\App\Listeners\DispatchWebhooks::class, 'handleBatchCompleted']
        );
        \Illuminate\Support\Facades\Event::listen(
            \App\Events\TamperingDetected::class,
            [\App\Listeners\DispatchWebhooks::class, 'handleTamperingDetected']
        );

        // ── Log all permanently failed jobs ───────────────────────────────────
        Queue::failing(function (JobFailed $event) {
            \Illuminate\Support\Facades\Log::error('Job permanently failed', [
                'job'        => $event->job->resolveName(),
                'connection' => $event->connectionName,
                'queue'      => $event->job->getQueue(),
                'exception'  => $event->exception->getMessage(),
            ]);
        });

        // ── Model macros ──────────────────────────────────────────────────────
        \Illuminate\Database\Eloquent\Model::preventLazyLoading(
            !$this->app->isProduction()
        );

        // ── Elasticsearch index bootstrap ─────────────────────────────────────
        if ($this->app->runningInConsole()) {
            $this->commands([
                \App\Console\Commands\SetupElasticsearchCommand::class,
                \App\Console\Commands\ReprocessImagesCommand::class,
                \App\Console\Commands\CleanupExpiredReportsCommand::class,
            ]);
        }
    }
}
