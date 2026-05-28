<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\{MediaController,ForensicsController,BatchController,ReportController,AnalyticsController,WebhookController,ApiKeyController,SearchController};

Route::get('/health', fn() => response()->json(['status'=>'ok','service'=>'metaintel-api','version'=>config('app.version','1.0.0'),'time'=>now()->toIso8601String()]));

Route::prefix('v1')->group(function () {
    Route::post('/auth/register', [\App\Http\Controllers\Auth\RegisterController::class,'register']);
    Route::post('/auth/login',    [\App\Http\Controllers\Auth\LoginController::class,'login']);

    Route::middleware(['auth:sanctum'])->group(function () {
        Route::post('/auth/logout', [\App\Http\Controllers\Auth\LoginController::class,'logout']);
        Route::get('/auth/me', fn(\Illuminate\Http\Request $r) => response()->json($r->user()));

        // Unified media (images + videos)
        Route::prefix('media')->group(function () {
            Route::get('/',                 [MediaController::class,'index']);
            Route::post('/upload',          [MediaController::class,'upload']);
            Route::post('/batch-upload',    [MediaController::class,'batchUpload']);
            Route::get('/{id}',             [MediaController::class,'show']);
            Route::get('/{id}/status',      [MediaController::class,'status']);
            Route::post('/{id}/reprocess',  [MediaController::class,'reprocess']);
            Route::post('/{id}/sanitize',   [MediaController::class,'sanitize']);
            Route::delete('/{id}',          [MediaController::class,'destroy']);
            Route::get('/{id}/frames',      [MediaController::class,'videoFrames']);
            Route::get('/{id}/gps-track',   [MediaController::class,'videoGpsTrack']);
        });

        // Legacy /images alias
        Route::prefix('images')->group(function () {
            Route::get('/',         [MediaController::class,'index']);
            Route::post('/upload',  [MediaController::class,'upload']);
            Route::get('/{id}',     [MediaController::class,'show']);
            Route::get('/{id}/status',[MediaController::class,'status']);
            Route::delete('/{id}',  [MediaController::class,'destroy']);
        });

        Route::prefix('forensics')->group(function () {
            Route::get('/{mediaId}',            [ForensicsController::class,'show']);
            Route::post('/{mediaId}/reanalyze', [ForensicsController::class,'reanalyze']);
            Route::get('/summary/anomalies',    [ForensicsController::class,'anomalySummary']);
            Route::get('/{mediaId}/frames',     [ForensicsController::class,'videoFrames']);
            Route::get('/{mediaId}/splice-map', [ForensicsController::class,'spliceMap']);
        });

        Route::prefix('batches')->group(function () {
            Route::get('/',              [BatchController::class,'index']);
            Route::get('/{id}',          [BatchController::class,'show']);
            Route::get('/{id}/timeline', [BatchController::class,'timeline']);
            Route::post('/{id}/cancel',  [BatchController::class,'cancel']);
        });

        Route::prefix('reports')->group(function () {
            Route::post('/generate',         [ReportController::class,'generate']);
            Route::get('/{uuid}',            [ReportController::class,'show']);
            Route::get('/export-json/{id}',  [ReportController::class,'exportJson']);
            Route::post('/export-csv',       [ReportController::class,'exportCsv']);
        });

        Route::get('/analytics/dashboard',    [AnalyticsController::class,'dashboard']);
        Route::get('/search',                  [SearchController::class,'search']);
        Route::get('/search/suggestions',      [SearchController::class,'suggestions']);

        Route::prefix('api-keys')->group(function () {
            Route::get('/',     [ApiKeyController::class,'index']);
            Route::post('/',    [ApiKeyController::class,'create']);
            Route::delete('/{id}',[ApiKeyController::class,'revoke']);
        });

        Route::prefix('webhooks')->group(function () {
            Route::get('/',          [WebhookController::class,'index']);
            Route::post('/',         [WebhookController::class,'create']);
            Route::put('/{id}',      [WebhookController::class,'update']);
            Route::delete('/{id}',   [WebhookController::class,'destroy']);
            Route::post('/{id}/test',[WebhookController::class,'test']);
        });
    });

    Route::middleware(['api.key'])->prefix('public')->group(function () {
        Route::post('/media/upload',       [MediaController::class,'upload']);
        Route::get('/media/{id}',          [MediaController::class,'show']);
        Route::get('/media/{id}/status',   [MediaController::class,'status']);
        Route::get('/forensics/{mediaId}', [ForensicsController::class,'show']);
        Route::post('/reports/generate',   [ReportController::class,'generate']);
        Route::get('/reports/{uuid}',      [ReportController::class,'show']);
    });
});
