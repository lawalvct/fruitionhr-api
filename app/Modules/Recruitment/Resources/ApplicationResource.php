<?php

namespace App\Modules\Recruitment\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'stage' => $this->stage,
            'source' => $this->source,
            'cover_letter' => $this->cover_letter,
            'applied_at' => $this->applied_at?->toISOString(),
            'hired_at' => $this->hired_at?->toISOString(),
            'applicant' => $this->whenLoaded('applicant', fn () => [
                'id' => $this->applicant->id,
                'name' => $this->applicant->full_name,
                'first_name' => $this->applicant->first_name,
                'last_name' => $this->applicant->last_name,
                'email' => $this->applicant->email,
                'phone' => $this->applicant->phone,
                'city' => $this->applicant->city,
                'state' => $this->applicant->state,
            ]),
            'vacancy' => $this->whenLoaded('vacancy', fn () => ['id' => $this->vacancy->id, 'title' => $this->vacancy->title]),
            'stage_history' => $this->whenLoaded('stageHistory', fn () => $this->stageHistory->map(fn ($item) => [
                'id' => $item->id, 'from_stage' => $item->from_stage, 'to_stage' => $item->to_stage,
                'notes' => $item->notes, 'created_at' => $item->created_at?->toISOString(),
            ])),
            'interviews' => $this->whenLoaded('interviews', fn () => $this->interviews->map(fn ($item) => [
                'id' => $item->id, 'type' => $item->type, 'scheduled_at' => $item->scheduled_at?->toISOString(),
                'location' => $item->location, 'meeting_url' => $item->meeting_url, 'status' => $item->status,
            ])),
            'offers' => $this->whenLoaded('offers', fn () => $this->offers->map(fn ($item) => [
                'id' => $item->id, 'annual_salary' => $item->annual_salary, 'start_date' => $item->start_date?->toDateString(),
                'expires_at' => $item->expires_at?->toDateString(), 'terms' => $item->terms, 'status' => $item->status,
            ])),
            'onboarding_tasks' => $this->whenLoaded('onboardingTasks', fn () => $this->onboardingTasks->map(fn ($item) => [
                'id' => $item->id, 'title' => $item->title, 'description' => $item->description,
                'due_date' => $item->due_date?->toDateString(), 'status' => $item->status,
            ])),
        ];
    }
}
