<?php

namespace App\Modules\Attendance\Controllers;

use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Tenant-level toggles for how employees are allowed to mark attendance
 * themselves. Stored in the existing `tenants.settings` JSON bag (see
 * Tenant::attendanceSelfClockEnabled()/attendanceKioskEnabled()) rather than
 * dedicated columns — no other feature reads that column yet.
 */
class AttendanceSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $tenant = app(CurrentTenant::class)->get();

        return response()->json(['data' => [
            'self_clock_enabled' => $tenant->attendanceSelfClockEnabled(),
            'kiosk_enabled' => $tenant->attendanceKioskEnabled(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $validated = $request->validate([
            'self_clock_enabled' => ['required', 'boolean'],
            'kiosk_enabled' => ['required', 'boolean'],
        ]);

        $tenant = app(CurrentTenant::class)->get();
        $tenant->update([
            'settings' => [
                ...($tenant->settings ?? []),
                'attendance' => $validated,
            ],
        ]);

        return response()->json(['data' => $validated]);
    }
}
