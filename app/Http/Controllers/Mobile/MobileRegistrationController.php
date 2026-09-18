<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Account\InvitationRegistrationData;
use App\Services\Account\MobileRegistrationChallenge;
use App\Services\Account\MobileSession;
use App\Services\Team\AcceptStudentInvitation;
use App\Services\Team\AcceptTrainerInvitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileRegistrationController extends Controller
{
    public function store(Request $request, string $kind, MobileRegistrationChallenge $challenge): JsonResponse
    {
        $service = $this->service($kind);
        $token = $challenge->start($kind, $service->prepare(InvitationRegistrationData::validate($request, $kind)));

        return response()->json(['verification_required' => true, 'challenge_token' => $token])->header('Cache-Control', 'no-store');
    }

    public function confirm(Request $request, string $kind, MobileRegistrationChallenge $challenge): JsonResponse
    {
        $data = $request->validate(['challenge_token' => ['required', 'string', 'size:64'], 'code' => ['required', 'digits:6']]);
        $session = $challenge->consume($kind, $data['challenge_token'], $data['code'], function (array $prepared) use ($kind) {
            return DB::transaction(function () use ($prepared, $kind) {
                $user = $this->service($kind)->accept($prepared);

                return $kind === 'student' ? app(MobileSession::class)->issue($user, source: 'student.registration') : [];
            });
        });

        return response()->json(['registered' => true] + $session)->header('Cache-Control', 'no-store');
    }

    private function service(string $kind): AcceptTrainerInvitation|AcceptStudentInvitation
    {
        return app($kind === 'trainer' ? AcceptTrainerInvitation::class : AcceptStudentInvitation::class);
    }
}
