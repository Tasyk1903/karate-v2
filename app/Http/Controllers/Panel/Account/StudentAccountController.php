<?php

namespace App\Http\Controllers\Panel\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Students\StudentProfileAccess;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class StudentAccountController extends Controller
{
    public function destroy(Request $request, StudentProfileAccess $access): JsonResponse
    {
        abort_unless($access->isSelf($request->user(), $request->user()), 403);
        $data = $request->validate(['password' => ['required', 'string', 'max:255'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $access, $data): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($access->isSelf($user, $user) && $access->capabilities($user, $user, lock: true)['delete_account'], 403);
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => __('mobile_profile.password_incorrect')]);
            }
            $user->delete();
            foreach (['mobile_access_tokens', 'sessions', 'push_subscriptions'] as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            $user->forceFill(['remember_token' => null, 'push_enabled' => false])->save();
            TeamActivity::record($user, 'student.account.deleted', User::class, $user->id,
                ['self' => true, 'old' => ['deleted_at' => null], 'new' => ['deleted_at' => $user->deleted_at->toISOString()]]);
        }, 3);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['deleted' => true]);
    }
}
