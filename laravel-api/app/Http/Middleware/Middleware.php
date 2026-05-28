<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use Closure;
use Illuminate\Http\{Request, JsonResponse};
use Illuminate\Support\Facades\{Cache, Log};

// ─────────────────────────────────────────────────────────────────────────────
// ApiKeyMiddleware  –  validates X-API-Key header for public/SDK routes
// ─────────────────────────────────────────────────────────────────────────────
class ApiKeyMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $key = $request->header('X-API-Key') ?? $request->query('api_key');

        if (!$key) {
            return response()->json([
                'error'   => 'API key required',
                'message' => 'Provide your API key in the X-API-Key header or api_key query parameter',
            ], 401);
        }

        // Cache lookup to avoid DB hit on every request (TTL: 5 min)
        $cacheKey = 'api_key:' . hash('sha256', $key);
        $apiKeyRecord = Cache::remember($cacheKey, 300, function () use ($key) {
            return ApiKey::where('key', $key)
                ->where('is_active', true)
                ->where(function ($q) {
                    $q->whereNull('expires_at')
                      ->orWhere('expires_at', '>', now());
                })
                ->first();
        });

        if (!$apiKeyRecord) {
            return response()->json([
                'error'   => 'Invalid or expired API key',
                'message' => 'The provided API key is not valid',
            ], 401);
        }

        // Scope check
        $requiredScope = $this->getScopeForRoute($request->route()?->getName());
        if ($requiredScope && !$this->hasScope($apiKeyRecord, $requiredScope)) {
            return response()->json([
                'error'   => 'Insufficient permissions',
                'message' => "This API key does not have the '{$requiredScope}' scope",
            ], 403);
        }

        // Track last used (async, non-blocking)
        ApiKey::where('id', $apiKeyRecord->id)->update([
            'last_used_at'  => now(),
            'request_count' => \DB::raw('request_count + 1'),
        ]);

        // Inject user context
        $request->merge(['_api_key_id' => $apiKeyRecord->id]);
        if ($apiKeyRecord->user_id) {
            $request->setUserResolver(fn() => $apiKeyRecord->user);
        }

        return $next($request);
    }

    private function getScopeForRoute(?string $routeName): ?string
    {
        return match (true) {
            str_contains($routeName ?? '', 'images.upload')  => 'images:write',
            str_contains($routeName ?? '', 'images.delete')  => 'images:delete',
            str_contains($routeName ?? '', 'reports')        => 'reports:read',
            default                                          => null, // no scope required
        };
    }

    private function hasScope(ApiKey $key, string $required): bool
    {
        $scopes = $key->scopes ?? ['*'];
        return in_array('*', $scopes) || in_array($required, $scopes);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SecurityHeadersMiddleware  –  adds security headers to all responses
// ─────────────────────────────────────────────────────────────────────────────
class SecurityHeadersMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options',  'nosniff');
        $response->headers->set('X-Frame-Options',         'DENY');
        $response->headers->set('X-XSS-Protection',        '1; mode=block');
        $response->headers->set('Referrer-Policy',         'strict-origin-when-cross-origin');
        $response->headers->set('X-MetaIntel-Version',     config('app.version', '1.0.0'));

        // Only add HSTS on HTTPS
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RateLimitMiddleware  –  per-user / per-IP rate limiting on upload endpoints
// ─────────────────────────────────────────────────────────────────────────────
class UploadRateLimitMiddleware
{
    /**
     * @param int $maxUploads  max uploads per window
     * @param int $window      window in seconds
     */
    public function handle(Request $request, Closure $next, int $maxUploads = 100, int $window = 3600): mixed
    {
        $identifier = $request->user()?->id
            ? 'upload_limit:user:' . $request->user()->id
            : 'upload_limit:ip:' . $request->ip();

        $count = Cache::get($identifier, 0);

        if ($count >= $maxUploads) {
            return response()->json([
                'error'       => 'Upload rate limit exceeded',
                'retry_after' => Cache::getExpiry($identifier),
                'limit'       => $maxUploads,
                'window_seconds' => $window,
            ], 429);
        }

        Cache::add($identifier, 0, $window);
        Cache::increment($identifier);

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit',     $maxUploads);
        $response->headers->set('X-RateLimit-Remaining', max(0, $maxUploads - $count - 1));

        return $response;
    }
}
