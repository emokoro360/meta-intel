<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\{Image, ForensicReport};
use Illuminate\Support\Facades\{Log, Storage};

// ─────────────────────────────────────────────────────────────────────────────
// SetupElasticsearchCommand  –  creates indices with proper mappings
// ─────────────────────────────────────────────────────────────────────────────
class SetupElasticsearchCommand extends Command
{
    protected $signature   = 'metaintel:setup-elasticsearch {--force : Recreate index if exists}';
    protected $description = 'Create Elasticsearch index with MetaIntel mappings';

    public function handle(\App\Services\MetadataSearchService $searchService): int
    {
        $this->info('Setting up Elasticsearch index...');

        try {
            $client = \Elastic\Elasticsearch\ClientBuilder::create()
                ->setHosts([config('services.elasticsearch.host') . ':' . config('services.elasticsearch.port')])
                ->build();

            $indexName = config('metaintel.elasticsearch.images_index', 'metaintel_images');

            // Delete if force flag is set
            if ($this->option('force')) {
                try {
                    $client->indices()->delete(['index' => $indexName]);
                    $this->line("Deleted existing index: {$indexName}");
                } catch (\Throwable) {}
            }

            // Check if index already exists
            $exists = $client->indices()->exists(['index' => $indexName])->asBool();
            if ($exists && !$this->option('force')) {
                $this->warn("Index '{$indexName}' already exists. Use --force to recreate.");
                return self::SUCCESS;
            }

            // Create index with mappings
            $client->indices()->create([
                'index' => $indexName,
                'body'  => [
                    'settings' => [
                        'number_of_shards'   => 1,
                        'number_of_replicas' => 0,
                        'analysis'           => [
                            'analyzer' => [
                                'filename_analyzer' => [
                                    'type'      => 'custom',
                                    'tokenizer' => 'standard',
                                    'filter'    => ['lowercase', 'stop'],
                                ],
                            ],
                        ],
                    ],
                    'mappings' => [
                        'properties' => [
                            'id'                => ['type' => 'keyword'],
                            'original_filename' => ['type' => 'text',    'analyzer' => 'filename_analyzer'],
                            'mime_type'         => ['type' => 'keyword'],
                            'sha256_hash'       => ['type' => 'keyword'],
                            'status'            => ['type' => 'keyword'],
                            'created_at'        => ['type' => 'date'],
                            'camera_make'       => ['type' => 'keyword'],
                            'camera_model'      => ['type' => 'keyword'],
                            'lens_model'        => ['type' => 'text'],
                            'software'          => ['type' => 'text'],
                            'serial_number'     => ['type' => 'keyword'],
                            'creator'           => ['type' => 'text'],
                            'copyright'         => ['type' => 'text'],
                            'caption'           => ['type' => 'text'],
                            'headline'          => ['type' => 'text'],
                            'keywords'          => ['type' => 'keyword'],
                            'date_taken'        => ['type' => 'date'],
                            'city'              => ['type' => 'keyword'],
                            'country'           => ['type' => 'keyword'],
                            'country_code'      => ['type' => 'keyword'],
                            'location'          => ['type' => 'geo_point'],
                        ],
                    ],
                ],
            ]);

            $this->info("✓ Elasticsearch index '{$indexName}' created successfully");
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->error('Elasticsearch setup failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ReprocessImagesCommand  –  re-run processing on images by status / IDs
// ─────────────────────────────────────────────────────────────────────────────
class ReprocessImagesCommand extends Command
{
    protected $signature = 'metaintel:reprocess
                            {--status=failed   : Reprocess images with this status}
                            {--ids=            : Comma-separated image IDs to reprocess}
                            {--forensics       : Run forensics only}
                            {--limit=100       : Max images to reprocess}';

    protected $description = 'Requeue images for (re)processing';

    public function handle(): int
    {
        $ids     = $this->option('ids')
            ? explode(',', $this->option('ids'))
            : null;

        $query = Image::query();

        if ($ids) {
            $query->whereIn('id', $ids);
        } else {
            $query->where('status', $this->option('status'));
        }

        $images = $query->limit((int)$this->option('limit'))->get();

        if ($images->isEmpty()) {
            $this->warn('No images found matching criteria.');
            return self::SUCCESS;
        }

        $this->info("Requeuing {$images->count()} images...");

        $options = [];
        if ($this->option('forensics')) {
            $options['forensics_only'] = true;
        }

        $bar = $this->output->createProgressBar($images->count());

        foreach ($images as $image) {
            $image->update(['status' => 'queued']);
            \App\Jobs\ProcessImageJob::dispatch($image->id, $options)
                ->onQueue('image-processing');
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✓ {$images->count()} images requeued.");

        return self::SUCCESS;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// CleanupExpiredReportsCommand  –  removes expired report files
// ─────────────────────────────────────────────────────────────────────────────
class CleanupExpiredReportsCommand extends Command
{
    protected $signature   = 'metaintel:cleanup-reports';
    protected $description = 'Delete expired forensic report files from storage';

    public function handle(): int
    {
        $expired = ForensicReport::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->where('status', 'completed')
            ->get();

        $count = 0;
        foreach ($expired as $report) {
            if ($report->file_path && Storage::exists($report->file_path)) {
                Storage::delete($report->file_path);
                $count++;
            }
            $report->update(['status' => 'expired', 'file_path' => null]);
        }

        $this->info("✓ Cleaned up {$count} expired report files.");
        return self::SUCCESS;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// KernelCommand – Artisan schedule registration
// ─────────────────────────────────────────────────────────────────────────────
namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel;

class Kernel extends Kernel
{
    protected function schedule(Schedule $schedule): void
    {
        // Clean up expired reports every night at 2am
        $schedule->command('metaintel:cleanup-reports')->dailyAt('02:00');

        // Re-attempt failed images every hour (up to 24h old)
        $schedule->command('metaintel:reprocess --status=failed --limit=50')
                 ->hourly()
                 ->withoutOverlapping();

        // Prune old telescope records (if Telescope is installed)
        $schedule->command('telescope:prune --hours=48')->daily();

        // Horizon snapshot for metrics
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }
}
