<?php

namespace App\Jobs;

use App\Models\BatchJob;
use Illuminate\Bus\{Queueable, Batch};
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\{Dispatchable, PendingBatch};
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{Bus, Log};
use Throwable;

class ProcessBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 1;

    public function __construct(
        private readonly int   $batchId,
        private readonly array $imageIds
    ) {}

    public function handle(): void
    {
        $batch = BatchJob::findOrFail($this->batchId);
        $batch->update(['status' => 'processing', 'started_at' => now()]);

        Log::info("BatchJob {$this->batchId}: Starting processing of " . count($this->imageIds) . " images");

        $options = $batch->options ?? [];

        // Build individual ProcessImageJob instances for all images
        $jobs = collect($this->imageIds)->map(
            fn(string $imageId) => new ProcessImageJob($imageId, $options)
        )->all();

        // Dispatch as Laravel Bus batch for tracking + callbacks
        Bus::batch($jobs)
            ->name("Batch #{$this->batchId}: {$batch->name}")
            ->allowFailures()   // continue on individual failures
            ->progress(function (Batch $busBatch) use ($batch) {
                // Update progress on each completion
                $batch->update([
                    'processed_images' => $busBatch->processedJobs(),
                    'failed_images'    => $busBatch->failedJobs,
                ]);
            })
            ->then(function (Batch $busBatch) use ($batch) {
                $batch->update([
                    'status'           => 'completed',
                    'processed_images' => $busBatch->processedJobs(),
                    'failed_images'    => $busBatch->failedJobs,
                    'completed_at'     => now(),
                ]);

                \App\Events\BatchCompleted::dispatch($batch->fresh());
                Log::info("BatchJob {$batch->id} completed: {$busBatch->processedJobs()} processed, {$busBatch->failedJobs} failed");
            })
            ->catch(function (Batch $busBatch, Throwable $e) use ($batch) {
                Log::error("BatchJob {$batch->id} encountered errors: " . $e->getMessage());
            })
            ->finally(function (Batch $busBatch) use ($batch) {
                // Mark failed if all jobs failed
                if ($busBatch->failedJobs === count($this->imageIds)) {
                    $batch->update(['status' => 'failed']);
                }
            })
            ->onQueue('batch-processing')
            ->dispatch();
    }

    public function failed(Throwable $exception): void
    {
        BatchJob::where('id', $this->batchId)->update([
            'status' => 'failed',
        ]);
        Log::error("ProcessBatchJob failed for batch #{$this->batchId}: " . $exception->getMessage());
    }
}
