<?php

namespace App\Http\Requests;

// ─────────────────────────────────────────────────────────────────────────────
// UploadMediaRequest  –  replaces UploadImageRequest; handles images + videos
// ─────────────────────────────────────────────────────────────────────────────
class UploadMediaRequest extends BaseApiRequest
{
    /** MIME types accepted for upload */
    const IMAGE_MIMES = [
        'image/jpeg','image/jpg','image/png','image/gif','image/webp',
        'image/tiff','image/bmp','image/heic','image/heif','image/svg+xml',
        'image/x-adobe-dng','image/x-canon-cr2','image/x-canon-cr3',
        'image/x-nikon-nef','image/x-sony-arw','image/x-fuji-raf',
    ];

    const VIDEO_MIMES = [
        'video/mp4','video/quicktime','video/x-msvideo','video/x-matroska',
        'video/webm','video/mpeg','video/3gpp','video/3gpp2',
        'video/x-flv','video/x-ms-wmv','video/x-m4v',
        'video/MP2T',              // MPEG-TS
        'application/mxf',         // MXF broadcast format
        'video/mxf',
    ];

    public function rules(): array
    {
        $maxMb = config('metaintel.max_upload_size_mb', 500);

        return [
            'file'            => [
                'required_without:image',
                'nullable',
                'file',
                "max:{$maxMb}",
            ],
            'image'           => [       // kept for backwards compat
                'required_without:file',
                'nullable',
                'file',
                "max:{$maxMb}",
            ],
            'run_forensics'   => 'boolean',
            'run_ai'          => 'boolean',
            'run_geospatial'  => 'boolean',
            'deduplicate'     => 'boolean',
            'max_frames'      => 'integer|min:5|max:120',   // video: max frames to extract
            'ela_quality'     => 'integer|min:50|max:95',
        ];
    }

    /** Return the uploaded file regardless of field name */
    public function getUploadedFile(): \Illuminate\Http\UploadedFile
    {
        return $this->file('file') ?? $this->file('image');
    }

    /** Detect media type from MIME */
    public function detectMediaType(): string
    {
        $mime = $this->getUploadedFile()->getMimeType();
        return in_array($mime, self::VIDEO_MIMES) ? 'video' : 'image';
    }
}
