<?php

namespace App\Events;

use App\Models\Image;
use Illuminate\Broadcasting\{InteractsWithSockets, Channel};
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ─────────────────────────────────────────────────────────────────────────────
// ImageProcessed Event
// Fired when an image finishes the full processing pipeline
// ─────────────────────────────────────────────────────────────────────────────
class ImageProcessed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly Image $image) {}

    public function broadcastOn(): array
    {
        return [new Channel('image-processing')];
    }

    public function broadcastAs(): string
    {
        return 'image.processed';
    }

    public function broadcastWith(): array
    {
        return [
            'id'                  => $this->image->id,
            'status'              => $this->image->status,
            'filename'            => $this->image->original_filename,
            'authenticity_verdict'=> $this->image->forensicAnalysis?->authenticity_verdict,
            'anomaly_count'       => $this->image->metadata?->anomaly_count ?? 0,
            'has_gps'             => $this->image->gpsData !== null,
            'processed_at'        => now()->toIso8601String(),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BatchCompleted Event
// ─────────────────────────────────────────────────────────────────────────────
class BatchCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly \App\Models\BatchJob $batch) {}
}

// ─────────────────────────────────────────────────────────────────────────────
// TamperingDetected Event  –  high-priority alert
// ─────────────────────────────────────────────────────────────────────────────
class TamperingDetected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Image  $image,
        public readonly string $verdict,
        public readonly float  $score
    ) {}
}

namespace App\Listeners;

use App\Events\{ImageProcessed, BatchCompleted, TamperingDetected};
use App\Models\{Webhook, Image};
use Illuminate\Support\Facades\{Http, Log, Queue};
use Illuminate\Contracts\Queue\ShouldQueue;

// ─────────────────────────────────────────────────────────────────────────────
// DispatchWebhooks  –  fires all registered webhooks for an event
// ─────────────────────────────────────────────────────────────────────────────
class DispatchWebhooks implements ShouldQueue
{
    public function handleImageProcessed(ImageProcessed $event): void
    {
        $this->dispatch('image.processed', $event->broadcastWith(), $event->image->user_id);

        // Check if tampering was detected and fire additional event
        $fa = $event->image->forensicAnalysis;
        if ($fa && in_array($fa->authenticity_verdict, ['likely_tampered', 'tampered'])) {
            TamperingDetected::dispatch($event->image, $fa->authenticity_verdict, $fa->authenticity_score ?? 0);
        }
    }

    public function handleBatchCompleted(BatchCompleted $event): void
    {
        $this->dispatch('batch.completed', [
            'batch_id'    => $event->batch->id,
            'name'        => $event->batch->name,
            'total'       => $event->batch->total_images,
            'processed'   => $event->batch->processed_images,
            'failed'      => $event->batch->failed_images,
            'completed_at'=> now()->toIso8601String(),
        ], $event->batch->user_id);
    }

    public function handleTamperingDetected(TamperingDetected $event): void
    {
        $this->dispatch('tampering.detected', [
            'image_id'   => $event->image->id,
            'filename'   => $event->image->original_filename,
            'verdict'    => $event->verdict,
            'score'      => $event->score,
            'detected_at'=> now()->toIso8601String(),
        ], $event->image->user_id);
    }

    private function dispatch(string $eventName, array $payload, ?int $userId): void
    {
        $webhooks = Webhook::where('is_active', true)
            ->whereJsonContains('events', $eventName)
            ->when($userId, fn($q) => $q->where('user_id', $userId))
            ->get();

        foreach ($webhooks as $webhook) {
            \App\Jobs\FireWebhookJob::dispatch($webhook->id, $eventName, $payload)
                ->onQueue('webhooks')
                ->delay(now()->addSeconds(2));
        }
    }
}

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{Http, Log};

// ─────────────────────────────────────────────────────────────────────────────
// FireWebhookJob  –  delivers a single webhook with HMAC signature + retries
// ─────────────────────────────────────────────────────────────────────────────
class FireWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 3;
    public int $timeout = 15;
    public array $backoff = [30, 120, 300]; // 30s, 2min, 5min

    public function __construct(
        private readonly int    $webhookId,
        private readonly string $event,
        private readonly array  $payload
    ) {}

    public function handle(): void
    {
        $webhook = Webhook::find($this->webhookId);
        if (!$webhook || !$webhook->is_active) return;

        $body      = json_encode([
            'event'      => $this->event,
            'payload'    => $this->payload,
            'timestamp'  => now()->toIso8601String(),
            'delivery_id'=> \Str::uuid()->toString(),
        ]);

        // HMAC-SHA256 signature for verification
        $signature = 'sha256=' . hash_hmac('sha256', $body, $webhook->secret);

        try {
            $response = Http::withHeaders([
                'Content-Type'           => 'application/json',
                'X-MetaIntel-Event'      => $this->event,
                'X-MetaIntel-Signature'  => $signature,
                'X-MetaIntel-Timestamp'  => now()->timestamp,
                'User-Agent'             => 'MetaIntel-Webhook/1.0',
            ])
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post($webhook->url);

            if ($response->successful()) {
                $webhook->update(['last_success_at' => now()]);
                Log::info("Webhook {$webhook->id} ({$this->event}) delivered: {$response->status()}");
            } else {
                Log::warning("Webhook {$webhook->id} received {$response->status()}: {$response->body()}");
                $this->fail("Webhook endpoint returned HTTP {$response->status()}");
            }

            $webhook->update(['last_triggered_at' => now()]);

        } catch (\Throwable $e) {
            Log::error("Webhook delivery failed for {$webhook->id}: " . $e->getMessage());
            throw $e;
        }
    }
}
