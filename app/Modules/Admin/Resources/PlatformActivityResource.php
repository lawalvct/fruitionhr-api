<?php

namespace App\Modules\Admin\Resources;

use App\Modules\Admin\Models\PlatformActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlatformActivity */
class PlatformActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'actor' => $this->actor === null ? null : [
                'id' => $this->actor->id,
                'name' => $this->actor->name,
                'email' => $this->actor->email,
            ],
            'subject' => [
                'type' => $this->subject_type,
                'id' => $this->subject_id,
                'label' => $this->subject_label,
            ],
            'changes' => [
                'before' => $this->before_values,
                'after' => $this->after_values,
            ],
            'reason' => $this->reason,
            'ip_address' => $this->ip_address,
            'user_agent' => $this->user_agent,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
