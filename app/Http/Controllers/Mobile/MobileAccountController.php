<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\CoachProfileAccess;
use App\Services\Account\MasterProfile;
use App\Services\Account\MobileAppAccess;
use App\Services\Students\StudentProfileAccess;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class MobileAccountController extends Controller
{
    public function destroy(Request $request, CoachProfileAccess $access): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string', 'max:255'], 'confirmed' => ['required', 'accepted']]);
        DB::transaction(function () use ($request, $data, $access): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $role = app(MobileAppAccess::class)->role($user);
            abort_unless(in_array($role, ['Coach', 'Student', 'Master'], true), 403);
            $capabilities = $role === 'Master' ? app(MasterProfile::class)->capabilities($user) : ($user->projectRoleNames() === ['Student']
                ? app(StudentProfileAccess::class)->capabilities($user, $user, lock: true)
                : $access->capabilities($user, lock: true));
            abort_unless($capabilities['delete_account'], 403, $role === 'Master' ? __('staff.pending_reviews') : '');
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['password' => __('mobile_profile.password_incorrect')]);
            }
            $before = ['deleted_at' => null, 'organization_id' => $user->organization_id];
            $user->delete();
            foreach (['mobile_access_tokens', 'sessions', 'push_subscriptions'] as $table) {
                DB::table($table)->where('user_id', $user->id)->delete();
            }
            DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
            $user->forceFill(['remember_token' => null, 'push_enabled' => false])->save();
            TeamActivity::record($user, 'mobile.account.deleted', User::class, $user->id,
                ['old' => $before, 'new' => ['deleted_at' => $user->deleted_at->toISOString()], 'self' => true]);
        }, 3);

        return response()->json(['deleted' => true]);
    }
}
