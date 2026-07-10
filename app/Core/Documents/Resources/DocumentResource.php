<?php

namespace App\Core\Documents\Resources;

use App\Core\Documents\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'document_type' => $this->document_type,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'mime_type' => $this->mime_type,
            'expires_at' => $this->expires_at?->toDateString(),
            'uploaded_by' => $this->whenLoaded('uploader', fn () => $this->uploader->name),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
