<?php

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\Shift;
use App\Modules\Attendance\Requests\ShiftRequest;
use App\Modules\Attendance\Resources\ShiftResource;
use App\Support\Authorization\Permissions;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ShiftController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_VIEW), 403);

        return ShiftResource::collection(
            Shift::query()->orderBy('name')->get()
        );
    }

    public function store(ShiftRequest $request)
    {
        $shift = Shift::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return (new ShiftResource($shift))->response()->setStatusCode(201);
    }

    public function update(ShiftRequest $request, Shift $shift)
    {
        $shift->update($request->validated());

        return new ShiftResource($shift->refresh());
    }

    public function destroy(Request $request, Shift $shift)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);
        abort_if(
            $shift->assignments()->where('is_current', true)->exists(),
            409,
            'Reassign employees before deleting this shift.',
        );

        $shift->delete();

        return response()->json(null, 204);
    }
}
