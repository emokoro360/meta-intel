<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\{WebhookCreateRequest, ApiKeyCreateRequest};
use App\Models\{Webhook, ApiKey};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\{Str, Facades\Hash};

// ─────────────────────────────────────────────────────────────────────────────
// WebhookController
// ─────────────────────────────────────────────────────────────────────────────
class WebhookController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $webhooks = Webhook::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($w) => [
                'id'               => $w->id,
                'url'              => $w->url,
                'events'           => $w->events,
                'is_active'        => $w->is_active,
                'last_triggered_at'=> $w->last_triggered_at,
                'last_success_at'  => $w->last_success_at,
                'created_at'       => $w->created_at,
            ]);

        return response()->json($webhooks);
    }

    public function create(WebhookCreateRequest $request): JsonResponse
    {
        $webhook = Webhook::create([
            'user_id'   => $request->user()->id,
            'url'       => $request->url,
            'events'    => $request->events,
            'secret'    => Str::random(48),
            'is_active' => true,
        ]);

        return response()->json([
            'webhook' => $webhook,
            'secret'  => $webhook->secret, // only shown once on creation
            'message' => 'Webhook created. Store the secret securely – it will not be shown again.',
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'url'       => 'nullable|url|max:500',
            'events'    => 'nullable|array',
            'events.*'  => 'string|in:image.processed,batch.completed,tampering.detected,image.failed',
            'is_active' => 'nullable|boolean',
        ]);

        $webhook->update(array_filter($validated, fn($v) => $v !== null));

        return response()->json(['webhook' => $webhook]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        Webhook::where('user_id', $request->user()->id)->findOrFail($id)->delete();
        return response()->json(['message' => 'Webhook deleted']);
    }

    public function test(Request $request, int $id): JsonResponse
    {
        $webhook = Webhook::where('user_id', $request->user()->id)->findOrFail($id);

        \App\Jobs\FireWebhookJob::dispatch($webhook->id, 'ping', [
            'message'    => 'This is a test webhook delivery from MetaIntel',
            'timestamp'  => now()->toIso8601String(),
        ])->onQueue('webhooks');

        return response()->json(['message' => 'Test delivery queued']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ApiKeyController
// ─────────────────────────────────────────────────────────────────────────────
class ApiKeyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $keys = ApiKey::where('user_id', $request->user()->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn($k) => [
                'id'           => $k->id,
                'name'         => $k->name,
                'key_preview'  => 'mi_' . substr($k->key, 0, 8) . '…',
                'scopes'       => $k->scopes,
                'last_used_at' => $k->last_used_at,
                'expires_at'   => $k->expires_at,
                'request_count'=> $k->request_count,
                'is_active'    => $k->is_active,
                'created_at'   => $k->created_at,
            ]);

        return response()->json($keys);
    }

    public function create(ApiKeyCreateRequest $request): JsonResponse
    {
        $maxKeys = config('metaintel.security.max_api_keys', 10);

        if (ApiKey::where('user_id', $request->user()->id)->where('is_active', true)->count() >= $maxKeys) {
            return response()->json([
                'error' => "Maximum of {$maxKeys} active API keys allowed",
            ], 422);
        }

        // Generate a secure random key with prefix
        $rawKey = config('metaintel.security.api_key_prefix', 'mi_') . Str::random(
            config('metaintel.security.api_key_length', 48)
        );

        $apiKey = ApiKey::create([
            'user_id'    => $request->user()->id,
            'name'       => $request->name,
            'key'        => $rawKey,
            'scopes'     => $request->scopes ?? ['*'],
            'expires_at' => $request->expires_at,
            'is_active'  => true,
        ]);

        return response()->json([
            'api_key' => [
                'id'        => $apiKey->id,
                'name'      => $apiKey->name,
                'key'       => $rawKey,      // shown once only
                'scopes'    => $apiKey->scopes,
                'expires_at'=> $apiKey->expires_at,
                'created_at'=> $apiKey->created_at,
            ],
            'message' => 'API key created. Store it securely – the full key will not be shown again.',
        ], 201);
    }

    public function revoke(Request $request, int $id): JsonResponse
    {
        $key = ApiKey::where('user_id', $request->user()->id)->findOrFail($id);
        $key->update(['is_active' => false]);

        // Clear cache entry
        \Illuminate\Support\Facades\Cache::forget('api_key:' . hash('sha256', $key->key));

        return response()->json(['message' => 'API key revoked']);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SearchController
// ─────────────────────────────────────────────────────────────────────────────
class SearchController extends Controller
{
    public function __construct(
        private readonly \App\Services\MetadataSearchService $searchService
    ) {}

    public function search(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q'        => 'required|string|min:2|max:200',
            'page'     => 'integer|min:1',
            'per_page' => 'integer|min:1|max:100',
        ]);

        $imageIds = $this->searchService->search($validated['q'], $validated['per_page'] ?? 20);

        $images = \App\Models\Image::with([
            'metadata:image_id,camera_make,camera_model,anomaly_count,date_time_original',
            'gpsData:image_id,latitude,longitude,city,country',
            'forensicAnalysis:image_id,authenticity_verdict,is_ai_generated',
        ])
        ->whereIn('id', $imageIds)
        ->get()
        ->sortBy(fn($img) => array_search($img->id, $imageIds))
        ->values();

        return response()->json([
            'query'   => $validated['q'],
            'count'   => $images->count(),
            'results' => $images,
        ]);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $q = $request->validate(['q' => 'required|string|min:1|max:100'])['q'];

        // Quick suggestions from DB (no ES needed for short queries)
        $cameras  = \App\Models\MetadataRecord::select('camera_make', 'camera_model')
            ->where('camera_make', 'ilike', "%{$q}%")
            ->orWhere('camera_model', 'ilike', "%{$q}%")
            ->distinct()->limit(5)
            ->get()
            ->map(fn($r) => trim("{$r->camera_make} {$r->camera_model}"));

        $software = \App\Models\MetadataRecord::select('software')
            ->where('software', 'ilike', "%{$q}%")
            ->whereNotNull('software')
            ->distinct()->limit(3)
            ->pluck('software');

        $cities   = \App\Models\GpsData::select('city', 'country')
            ->where('city', 'ilike', "%{$q}%")
            ->whereNotNull('city')
            ->distinct()->limit(4)
            ->get()
            ->map(fn($g) => "{$g->city}, {$g->country}");

        return response()->json([
            'cameras'  => $cameras,
            'software' => $software,
            'locations'=> $cities,
        ]);
    }
}
