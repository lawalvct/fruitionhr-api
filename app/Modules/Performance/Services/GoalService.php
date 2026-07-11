<?php

namespace App\Modules\Performance\Services;

use App\Models\User;
use App\Modules\Performance\Models\Goal;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class GoalService
{
    public function checkin(Goal $goal, array $data, User $user): Goal
    {
        if (in_array($goal->status, ['completed', 'cancelled'], true)) {
            throw new ConflictHttpException('Completed or cancelled goals cannot receive check-ins.');
        }

        return DB::transaction(function () use ($goal, $data, $user): Goal {
            $goal->checkins()->create([...$data, 'created_by' => $user->id]);
            $goal->update([
                'progress' => $data['progress'],
                'current_value' => $data['current_value'] ?? $goal->current_value,
                'status' => $data['progress'] === 100 ? 'completed' : 'active',
            ]);

            return $goal->refresh()->load(['department', 'employee', 'owner', 'checkins']);
        });
    }
}
