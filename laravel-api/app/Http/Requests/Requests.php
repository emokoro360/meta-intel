<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

// ─────────────────────────────────────────────────────────────────────────────
// Base API Request  –  returns JSON errors
// ─────────────────────────────────────────────────────────────────────────────
abstract class BaseApiRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(
            response()->json([
                'error'   => 'Validation failed',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// UploadImageRequest
// ─────────────────────────────────────────────────────────────────────────────
class UploadImageRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $maxMb = config('metaintel.max_upload_size_mb', 100);

        return [
            'image'           => [
                'required',
                'file',
                "max:{$maxMb}",
                'mimes:jpeg,jpg,png,gif,webp,tiff,tif,bmp,heic,heif,svg,dng,cr2,cr3,nef,arw,raf,orf,rw2',
            ],
            'run_forensics'   => 'boolean',
            'run_ai'          => 'boolean',
            'run_geospatial'  => 'boolean',
            'deduplicate'     => 'boolean',
            'ela_quality'     => 'integer|min:50|max:95',
            'ela_scale'       => 'integer|min:1|max:20',
        ];
    }

    public function messages(): array
    {
        return [
            'image.required' => 'An image file is required',
            'image.max'      => 'Image file exceeds the maximum allowed size of ' . config('metaintel.max_upload_size_mb') . ' MB',
            'image.mimes'    => 'The file must be a valid image format (JPEG, PNG, TIFF, RAW, etc.)',
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// BatchUploadRequest
// ─────────────────────────────────────────────────────────────────────────────
class BatchUploadRequest extends BaseApiRequest
{
    public function rules(): array
    {
        $maxBatch = config('metaintel.max_batch_size', 500);
        $maxMb    = config('metaintel.max_upload_size_mb', 100);

        return [
            'images'          => "required|array|min:1|max:{$maxBatch}",
            'images.*'        => "file|max:{$maxMb}|mimes:jpeg,jpg,png,gif,webp,tiff,tif,bmp,heic,heif,dng,cr2,nef,arw",
            'batch_name'      => 'nullable|string|max:255',
            'run_forensics'   => 'boolean',
            'run_ai'          => 'boolean',
            'run_geospatial'  => 'boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'images.required' => 'At least one image file is required',
            'images.max'      => 'Batch cannot exceed ' . config('metaintel.max_batch_size') . ' images',
            'images.*.file'   => 'Each item must be a valid file',
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ApiKeyCreateRequest
// ─────────────────────────────────────────────────────────────────────────────
class ApiKeyCreateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:100',
            'scopes'      => 'nullable|array',
            'scopes.*'    => 'string|in:*,images:read,images:write,images:delete,forensics:read,reports:read,reports:write',
            'expires_at'  => 'nullable|date|after:today',
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// WebhookCreateRequest
// ─────────────────────────────────────────────────────────────────────────────
class WebhookCreateRequest extends BaseApiRequest
{
    public function rules(): array
    {
        return [
            'url'     => 'required|url|max:500',
            'events'  => 'required|array|min:1',
            'events.*'=> 'string|in:image.processed,batch.completed,tampering.detected,image.failed',
        ];
    }
}
