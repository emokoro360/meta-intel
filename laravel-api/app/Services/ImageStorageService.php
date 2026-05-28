<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\{Storage, Log};

class ImageStorageService
{
    /**
     * Store any media file (image or video) and return the storage path.
     */
    public function storeMedia(UploadedFile $file, string $sha256, string $mediaType = 'image'): string
    {
        $ext      = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $datePath = now()->format('Y/m/d');
        $filename = $sha256 . '.' . $ext;
        $path     = "originals/{$datePath}/{$filename}";

        Storage::put($path, file_get_contents($file->getRealPath()), ['visibility' => 'private']);

        if ($mediaType === 'image') {
            $this->generateImageThumbnail($file->getRealPath(), $path, $ext);
        } else {
            $this->generateVideoThumbnail($file->getRealPath(), $path, $ext);
        }

        return $path;
    }

    /**
     * Legacy method kept for backwards compatibility.
     */
    public function store(UploadedFile $file, string $sha256): string
    {
        return $this->storeMedia($file, $sha256, 'image');
    }

    /**
     * Delete a file and its derivatives from storage.
     */
    public function delete(string $storagePath, string $disk = 'default'): void
    {
        $diskInstance = Storage::disk($disk === 'default' ? config('filesystems.default') : $disk);

        // Delete original
        if ($diskInstance->exists($storagePath)) {
            $diskInstance->delete($storagePath);
        }

        // Delete image thumbnail
        $thumbPath = str_replace('originals/', 'thumbnails/', $storagePath);
        if ($diskInstance->exists($thumbPath)) {
            $diskInstance->delete($thumbPath);
        }

        // Delete video thumbnail
        $videoThumb = str_replace('originals/', 'video-thumbs/', $storagePath);
        $videoThumb = preg_replace('/\.\w+$/', '_thumb.jpg', $videoThumb);
        if ($diskInstance->exists($videoThumb)) {
            $diskInstance->delete($videoThumb);
        }

        // Delete extracted video frames directory
        preg_match('/originals\/\d+\/\d+\/\d+\/([a-f0-9]+)\./', $storagePath, $m);
        if (!empty($m[1])) {
            $framesDir = "frames/{$m[1]}";
            if ($diskInstance->exists($framesDir)) {
                $diskInstance->deleteDirectory($framesDir);
            }
            $elaDir = "ela/{$m[1]}";
            if ($diskInstance->exists($elaDir)) {
                $diskInstance->deleteDirectory($elaDir);
            }
        }
    }

    // ─── Private helpers ──────────────────────────────────────────────────────

    private function generateImageThumbnail(string $sourcePath, string $originalStoragePath, string $ext): void
    {
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif'])) return;

        try {
            $thumbPath = str_replace('originals/', 'thumbnails/', $originalStoragePath);

            $img = \Intervention\Image\Facades\Image::make($sourcePath)
                ->fit(320, 240, function ($c) { $c->upsize(); })
                ->encode($ext === 'jpg' ? 'jpeg' : $ext, 80);

            Storage::put($thumbPath, $img->getEncoded());
        } catch (\Throwable $e) {
            Log::warning("Image thumbnail generation failed: " . $e->getMessage());
        }
    }

    private function generateVideoThumbnail(string $sourcePath, string $originalStoragePath, string $ext): void
    {
        try {
            $thumbStoragePath = str_replace('originals/', 'video-thumbs/', $originalStoragePath);
            $thumbStoragePath = preg_replace('/\.\w+$/', '_thumb.jpg', $thumbStoragePath);

            $tmpThumb    = tempnam(sys_get_temp_dir(), 'metaintel_vthumb_') . '.jpg';
            $ffmpegPath  = config('metaintel.ffmpeg_path', '/usr/bin/ffmpeg');

            // Extract frame at 10% of video duration for a representative thumbnail
            $cmd = escapeshellcmd(
                "{$ffmpegPath} -ss 0 -i " . escapeshellarg($sourcePath) .
                " -vframes 1 -vf scale=320:-1 -q:v 5 " . escapeshellarg($tmpThumb) .
                " 2>/dev/null"
            );
            shell_exec($cmd);

            if (file_exists($tmpThumb) && filesize($tmpThumb) > 0) {
                Storage::put($thumbStoragePath, file_get_contents($tmpThumb));
                @unlink($tmpThumb);
                Log::debug("Video thumbnail generated: {$thumbStoragePath}");
            }
        } catch (\Throwable $e) {
            Log::warning("Video thumbnail generation failed: " . $e->getMessage());
        }
    }
}
