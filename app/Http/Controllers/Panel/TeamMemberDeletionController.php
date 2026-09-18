<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TeamMemberDeletionController extends Controller
{
    public function __invoke(Request $request, string $section): JsonResponse
    {
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        abort_unless($request->user()->hasProjectRole('Organization'), 403);
        $role = match ($section) {
            'judges' => 'Judge', 'secretaries' => 'Secretary', default => abort(404)
        };
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:100'], 'ids.*' => ['required', 'integer', 'distinct']]);
        DB::transaction(function () use ($data, $request, $role): void {
            $users = User::query()->whereIn('id', $data['ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($users->count() === count($data['ids']), 404);
            foreach ($users as $user) {
                abort_unless((int) $user->organization_id === $request->user()->id && $user->hasProjectRole($role), 403);
                if (array_diff($user->projectRoleNames(), [$role]) !== [] || User::withTrashed()->where('coach_id', $user->id)->exists()) {
                    throw ValidationException::withMessages(['ids' => __('team.member_linked')]);
                }
            }
            foreach ($users as $user) {
                $before = $user->only(['first_name', 'last_name', 'email', 'organization_id', 'deleted_at']);
                // Soft deletion preserves judging history, invitations and all foreign-key references.
                $user->delete();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('mobile_access_tokens')->where('user_id', $user->id)->delete();
                TeamActivity::record($request->user(), 'team.member.deleted', User::class, $user->id,
                    ['role' => $role, 'old' => $before, 'new' => ['deleted_at' => $user->deleted_at?->toDateTimeString()]]);
            }
        });

        return response()->json(['deleted_ids' => $data['ids']]);
    }
}
