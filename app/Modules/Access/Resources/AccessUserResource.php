<?php

namespace App\Modules\Access\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class AccessUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'status' => $this->status,
            'is_current_user' => $request->user()?->id === $this->id,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles
                ->sortBy('name')
                ->values()
                ->map(fn ($role) => [
                    'id' => $role->id,
                    'name' => $role->name,
                    'label' => (string) Str::of($role->name)->replace('_', ' ')->headline(),
                ])),
            'employee' => $this->when(
                $this->relationLoaded('employee') && $this->employee !== null,
                fn () => [
                    'id' => $this->employee->id,
                    'employee_number' => $this->employee->employee_number,
                    'name' => $this->employee->full_name,
                ],
            ),
        ];
    }
}
