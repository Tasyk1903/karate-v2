<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\WaitConfirmationInvitation;
use App\Services\Account\MobileAppLinks;
use App\Services\Team\CoachInvitations;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileStudentInvitationController extends Controller
{
    public function index(Request $request, CoachInvitations $invitations)
    {
        $rows = WaitConfirmationInvitation::query()->where('inviting_id', $request->user()->id)
            ->where('target_role', 'Student')->where('confirmed', false)->latest('id')->paginate(20, ['id', 'email', 'created_at']);

        return response()->json(['code' => $invitations->code($request->user()), 'app_downloads' => MobileAppLinks::downloads(),
            'data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }

    public function store(Request $request, CoachInvitations $invitations)
    {
        $data = $request->validate(['emails' => ['required', 'array', 'min:1', 'max:30'], 'emails.*' => ['required', 'string', 'max:255'], 'locale' => ['nullable', 'in:ru,en']]);

        return response()->json(['results' => $invitations->send($request->user(), $data['emails'], $data['locale'] ?? 'ru')]);
    }

    public function destroy(Request $request, WaitConfirmationInvitation $invitation)
    {
        DB::transaction(function () use ($request, $invitation) {
            $row = WaitConfirmationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($row->target_role === 'Student' && ! $row->confirmed && (int) $row->inviting_id === (int) $request->user()->id, 403);
            $old = $row->only(['email', 'inviting_id', 'confirmed']);
            $row->delete();
            TeamActivity::record($request->user(), 'student.invitation.deleted', WaitConfirmationInvitation::class, $row->id, ['old' => $old, 'new' => null]);
        });

        return response()->json(['deleted' => true]);
    }
}
