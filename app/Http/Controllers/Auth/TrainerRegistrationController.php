<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Account\InvitationRegistrationData;
use App\Services\Account\RegistrationChallenge;
use App\Services\Team\AcceptTrainerInvitation;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class TrainerRegistrationController extends Controller
{
    public function store(Request $request, AcceptTrainerInvitation $service): JsonResponse
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
        $data = InvitationRegistrationData::validate($request, 'trainer');
        $prepared = $service->prepare($data);
        app(RegistrationChallenge::class)->start($request, 'trainer', $prepared);

        return response()->json(['verification_required' => true]);
    }

    public function confirm(Request $request, AcceptTrainerInvitation $service): JsonResponse
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
        $challenge = app(RegistrationChallenge::class);
        $user = $service->accept($challenge->verify($request, 'trainer'));
        $challenge->clear($request, 'trainer');
        Auth::login($user);
        TeamActivity::record($user, 'user.logged_in', $user::class, $user->id, ['source' => 'trainer.invitation']);
        $request->session()->regenerate();

        return response()->json(['redirect' => '/panel/tournaments']);
    }
}
