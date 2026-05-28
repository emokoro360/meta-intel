<?php

namespace App\Jobs;

use App\Models\{Image, ForensicReport};
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\{InteractsWithQueue, SerializesModels};
use Illuminate\Support\Facades\{Log, Storage};
use Throwable;

// ─────────────────────────────────────────────────────────────────────────────
// GenerateReportJob  –  produces PDF/JSON/CSV forensic reports
// ─────────────────────────────────────────────────────────────────────────────
class GenerateReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 180;
    public int $tries   = 2;

    public function __construct(private readonly int $reportId) {}

    public function handle(): void
    {
        $report = ForensicReport::findOrFail($this->reportId);
        $report->update(['status' => 'generating']);

        try {
            $images = Image::with(['metadata', 'gpsData', 'forensicAnalysis', 'timelineEvents'])
                ->whereIn('id', $report->image_ids)
                ->get();

            $filePath = match ($report->format) {
                'pdf'  => $this->generatePdf($report, $images),
                'json' => $this->generateJson($report, $images),
                'csv'  => $this->generateCsv($report, $images),
                'html' => $this->generateHtml($report, $images),
                default => $this->generateJson($report, $images),
            };

            $report->update([
                'status'    => 'completed',
                'file_path' => $filePath,
            ]);

            Log::info("Report {$report->uuid} generated: {$filePath}");

        } catch (Throwable $e) {
            Log::error("Report generation failed for {$report->uuid}: " . $e->getMessage());
            $report->update(['status' => 'failed']);
            throw $e;
        }
    }

    // ── PDF Report ────────────────────────────────────────────────────────────
    private function generatePdf(ForensicReport $report, $images): string
    {
        $html     = $this->buildReportHtml($report, $images);
        $filename = "reports/{$report->uuid}/report.pdf";

        // Use wkhtmltopdf or Puppeteer via shell
        $tmpHtml = tempnam(sys_get_temp_dir(), 'metaintel_report_') . '.html';
        $tmpPdf  = tempnam(sys_get_temp_dir(), 'metaintel_report_') . '.pdf';

        file_put_contents($tmpHtml, $html);

        // Try puppeteer first (better rendering), fallback to wkhtmltopdf
        $puppeteerCmd = "node -e \"
            const puppeteer = require('puppeteer');
            (async () => {
                const browser = await puppeteer.launch({args:['--no-sandbox']});
                const page    = await browser.newPage();
                await page.goto('file://{$tmpHtml}', {waitUntil:'networkidle0'});
                await page.pdf({path:'{$tmpPdf}', format:'A4', printBackground:true, margin:{top:'20mm',bottom:'20mm',left:'15mm',right:'15mm'}});
                await browser.close();
            })();
        \"";

        $wkCmd = "wkhtmltopdf --quiet --enable-local-file-access '{$tmpHtml}' '{$tmpPdf}'";

        $cmdResult = shell_exec($puppeteerCmd . ' 2>/dev/null || ' . $wkCmd . ' 2>/dev/null');

        if (file_exists($tmpPdf)) {
            Storage::put($filename, file_get_contents($tmpPdf));
            @unlink($tmpHtml);
            @unlink($tmpPdf);
        } else {
            // Last resort: save as HTML
            $filename = "reports/{$report->uuid}/report.html";
            Storage::put($filename, $html);
        }

        return $filename;
    }

    // ── JSON Export ────────────────────────────────────────────────────────────
    private function generateJson(ForensicReport $report, $images): string
    {
        $data = [
            'report' => [
                'title'        => $report->title,
                'uuid'         => $report->uuid,
                'type'         => $report->report_type,
                'generated_at' => now()->toIso8601String(),
                'image_count'  => $images->count(),
            ],
            'images' => $images->map(fn($img) => [
                'id'        => $img->id,
                'filename'  => $img->original_filename,
                'sha256'    => $img->sha256_hash,
                'size'      => $img->file_size,
                'mime_type' => $img->mime_type,
                'metadata'  => $img->metadata?->toMetadataTree(),
                'gps'       => $img->gpsData?->toGeoJSON(),
                'forensics' => $img->forensicAnalysis,
                'timeline'  => $img->timelineEvents,
                'summary'   => $img->getAnalysisSummary(),
            ])->values(),
        ];

        $filename = "reports/{$report->uuid}/export.json";
        Storage::put($filename, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $filename;
    }

    // ── CSV Export ─────────────────────────────────────────────────────────────
    private function generateCsv(ForensicReport $report, $images): string
    {
        $lines    = [];
        $headers  = [
            'ID','Filename','SHA-256','Size (bytes)','MIME Type',
            'Camera Make','Camera Model','Lens','Software',
            'Date Taken','Latitude','Longitude','City','Country',
            'Authenticity','AI Generated','Anomaly Count',
            'ELA Score','Noise Score','Faces Found',
        ];
        $lines[]  = implode(',', $headers);

        foreach ($images as $img) {
            $fa = $img->forensicAnalysis;
            $m  = $img->metadata;
            $g  = $img->gpsData;

            $lines[] = implode(',', array_map(
                fn($v) => '"' . str_replace('"', '""', (string)($v ?? '')) . '"',
                [
                    $img->id,
                    $img->original_filename,
                    $img->sha256_hash,
                    $img->file_size,
                    $img->mime_type,
                    $m?->camera_make,
                    $m?->camera_model,
                    $m?->lens_model,
                    $m?->software,
                    $m?->date_time_original,
                    $g?->latitude,
                    $g?->longitude,
                    $g?->city,
                    $g?->country,
                    $fa?->authenticity_verdict,
                    $fa?->is_ai_generated ? 'Yes' : 'No',
                    $m?->anomaly_count ?? 0,
                    $fa?->ela_score,
                    $fa?->noise_score,
                    $fa?->face_count ?? 0,
                ]
            ));
        }

        $filename = "reports/{$report->uuid}/export.csv";
        Storage::put($filename, implode("\n", $lines));
        return $filename;
    }

    // ── HTML Report ────────────────────────────────────────────────────────────
    private function generateHtml(ForensicReport $report, $images): string
    {
        $html     = $this->buildReportHtml($report, $images);
        $filename = "reports/{$report->uuid}/report.html";
        Storage::put($filename, $html);
        return $filename;
    }

    // ── Report HTML Template ──────────────────────────────────────────────────
    private function buildReportHtml(ForensicReport $report, $images): string
    {
        $rows = $images->map(function ($img) {
            $fa      = $img->forensicAnalysis;
            $verdict = $fa?->authenticity_verdict ?? 'unknown';
            $colors  = [
                'authentic' => '#22c55e', 'likely_authentic' => '#22c55e',
                'suspicious' => '#f59e0b', 'likely_tampered' => '#ef4444',
                'tampered' => '#ef4444', 'ai_generated' => '#8b5cf6',
                'unknown' => '#6b7280',
            ];
            $color = $colors[$verdict] ?? '#6b7280';

            return "
            <tr>
                <td style='padding:8px;border-bottom:1px solid #1c2a38;font-size:12px;color:#c9d8e8'>{$img->original_filename}</td>
                <td style='padding:8px;border-bottom:1px solid #1c2a38;font-size:11px;color:#4a6178;font-family:monospace'>{$img->sha256_hash}</td>
                <td style='padding:8px;border-bottom:1px solid #1c2a38;font-size:12px;color:#c9d8e8'>{$img->metadata?->camera_make} {$img->metadata?->camera_model}</td>
                <td style='padding:8px;border-bottom:1px solid #1c2a38;font-size:12px;color:#c9d8e8'>{$img->gpsData?->city}, {$img->gpsData?->country}</td>
                <td style='padding:8px;border-bottom:1px solid #1c2a38'>
                    <span style='background:{$color}22;color:{$color};border:1px solid {$color}44;border-radius:4px;padding:2px 8px;font-size:10px;font-family:monospace'>
                        " . strtoupper($verdict) . "
                    </span>
                </td>
                <td style='padding:8px;border-bottom:1px solid #1c2a38;font-size:12px;color:" . ($img->metadata?->anomaly_count > 0 ? '#f59e0b' : '#22c55e') . "'>{$img->metadata?->anomaly_count}</td>
            </tr>";
        })->implode('');

        $totalImages   = $images->count();
        $aiCount       = $images->filter(fn($i) => $i->forensicAnalysis?->is_ai_generated)->count();
        $tamperedCount = $images->filter(fn($i) => in_array($i->forensicAnalysis?->authenticity_verdict, ['tampered', 'likely_tampered']))->count();
        $gpsCount      = $images->filter(fn($i) => $i->gpsData)->count();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>MetaIntel Forensic Report – {$report->title}</title>
<style>
  body { background:#080c10; color:#c9d8e8; font-family:system-ui,sans-serif; margin:0; padding:32px; }
  h1   { font-size:28px; font-weight:800; background:linear-gradient(90deg,#eaf4ff,#00d4ff);
         -webkit-background-clip:text; -webkit-text-fill-color:transparent; margin-bottom:8px; }
  .meta { color:#4a6178; font-size:12px; margin-bottom:32px; }
  .stats { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:32px; }
  .stat  { background:#0f1923; border:1px solid #1c2a38; border-radius:10px; padding:16px; text-align:center; }
  .stat .val { font-size:32px; font-weight:700; font-family:monospace; }
  .stat .lbl { font-size:11px; color:#4a6178; margin-top:4px; text-transform:uppercase; letter-spacing:.08em; }
  table  { width:100%; border-collapse:collapse; background:#0f1923; border-radius:10px;
           overflow:hidden; border:1px solid #1c2a38; }
  thead  { background:#0d1117; }
  th     { padding:10px 8px; font-size:10px; color:#4a6178; text-align:left;
           text-transform:uppercase; letter-spacing:.1em; }
  .footer{ color:#4a6178; font-size:11px; text-align:center; margin-top:32px; }
</style>
</head>
<body>
  <h1>🛡 MetaIntel Forensic Report</h1>
  <p class="meta">
    {$report->title} &nbsp;·&nbsp; Generated {$report->created_at} &nbsp;·&nbsp;
    UUID: {$report->uuid}
  </p>

  <div class="stats">
    <div class="stat"><div class="val" style="color:#00d4ff">{$totalImages}</div><div class="lbl">Total Images</div></div>
    <div class="stat"><div class="val" style="color:#ef4444">{$tamperedCount}</div><div class="lbl">Tampered</div></div>
    <div class="stat"><div class="val" style="color:#8b5cf6">{$aiCount}</div><div class="lbl">AI Generated</div></div>
    <div class="stat"><div class="val" style="color:#22c55e">{$gpsCount}</div><div class="lbl">With GPS</div></div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Filename</th><th>SHA-256</th><th>Camera</th>
        <th>Location</th><th>Verdict</th><th>Anomalies</th>
      </tr>
    </thead>
    <tbody>{$rows}</tbody>
  </table>

  <p class="footer">MetaIntel Platform &nbsp;·&nbsp; Confidential Forensic Report &nbsp;·&nbsp; {$report->uuid}</p>
</body>
</html>
HTML;
    }

    public function failed(Throwable $exception): void
    {
        ForensicReport::where('id', $this->reportId)->update(['status' => 'failed']);
        Log::error("GenerateReportJob failed for report #{$this->reportId}: " . $exception->getMessage());
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// SanitizeImageJob  –  strips metadata from image
// ─────────────────────────────────────────────────────────────────────────────
class SanitizeImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;
    public int $tries   = 2;

    public function __construct(private readonly string $imageId) {}

    public function handle(): void
    {
        $image       = Image::findOrFail($this->imageId);
        $serviceUrl  = env('METADATA_SERVICE_URL', 'http://metadata-service:8001');

        try {
            $imagePath   = Storage::path($image->storage_path);
            $outputPath  = str_replace('originals/', 'sanitized/', $image->storage_path);
            $outputLocal = sys_get_temp_dir() . '/sanitized_' . $image->id . '.jpg';

            $response = \Illuminate\Support\Facades\Http::timeout(30)
                ->post("{$serviceUrl}/sanitize", [
                    'image_path'  => $imagePath,
                    'image_id'    => $image->id,
                    'output_path' => $outputLocal,
                    'keep_fields' => ['Orientation', 'ColorSpace'],
                ]);

            if ($response->successful() && file_exists($outputLocal)) {
                Storage::put($outputPath, file_get_contents($outputLocal));
                @unlink($outputLocal);

                $image->update(['status' => 'sanitized']);
                Log::info("Image {$image->id} sanitized → {$outputPath}");
            } else {
                throw new \RuntimeException("Sanitize service returned: " . $response->status());
            }

        } catch (Throwable $e) {
            Log::error("SanitizeImageJob failed for {$this->imageId}: " . $e->getMessage());
            throw $e;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// IndexImageJob  –  indexes image in Elasticsearch
// ─────────────────────────────────────────────────────────────────────────────
class IndexImageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly string $imageId) {}

    public function handle(\App\Services\MetadataSearchService $searchService): void
    {
        $image = Image::with(['metadata', 'gpsData'])->findOrFail($this->imageId);
        $searchService->index($image);
    }
}
