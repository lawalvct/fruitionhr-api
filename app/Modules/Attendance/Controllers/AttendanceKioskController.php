<?php

namespace App\Modules\Attendance\Controllers;

use App\Modules\Attendance\Models\AttendanceKiosk;
use App\Modules\Attendance\Requests\AttendanceKioskRequest;
use App\Modules\Attendance\Resources\AttendanceKioskResource;
use App\Modules\Attendance\Support\KioskToken;
use App\Support\Authorization\Permissions;
use App\Support\Tenancy\CurrentTenant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class AttendanceKioskController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        return AttendanceKioskResource::collection(
            AttendanceKiosk::query()->orderBy('name')->get()
        );
    }

    public function store(AttendanceKioskRequest $request)
    {
        $kiosk = AttendanceKiosk::query()->create([
            ...$request->validated(),
            'created_by' => $request->user()->id,
        ]);

        return (new AttendanceKioskResource($kiosk))->response()->setStatusCode(201);
    }

    public function update(AttendanceKioskRequest $request, AttendanceKiosk $kiosk)
    {
        $kiosk->update($request->validated());

        return new AttendanceKioskResource($kiosk->refresh());
    }

    public function destroy(Request $request, AttendanceKiosk $kiosk)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);

        $kiosk->delete();

        return response()->json(null, 204);
    }

    /**
     * Mints a short-lived rotating token for the kiosk display page to
     * render as a QR code. Convenience/UX deterrent against a photographed
     * QR being reused, not access control — real identity always comes from
     * the scanning phone's own logged-in session (see KioskToken docblock).
     */
    public function token(Request $request, AttendanceKiosk $kiosk, CurrentTenant $tenant)
    {
        abort_unless($request->user()->can(Permissions::ATTENDANCE_MANAGE), 403);
        abort_unless($tenant->get()->attendanceKioskEnabled(), 403, 'Kiosk scanning is disabled for this organisation.');

        $token = KioskToken::mint($tenant->id(), $kiosk->id);

        return response()->json(['data' => [
            'token' => $token,
            'expires_in' => KioskToken::TTL_SECONDS,
        ]]);
    }
}
