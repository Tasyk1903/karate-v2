<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Account\InvitationRegistrationData;
use App\Services\Account\RegistrationChallenge;
use App\Services\Team\AcceptStudentInvitation;
use Illuminate\Http\Request;

final class StudentRegistrationController extends Controller
{
    public function store(Request $request, AcceptStudentInvitation $service, RegistrationChallenge $challenge)
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
        $data = InvitationRegistrationData::validate($request, 'student');
        $challenge->start($request, 'student', $service->prepare($data));

        return response()->json(['verification_required' => true]);
    }

    public function confirm(Request $request, AcceptStudentInvitation $service, RegistrationChallenge $challenge)
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
        $service->accept($challenge->verify($request, 'student'));
        $challenge->clear($request, 'student');
        $request->session()->regenerate();

        return response()->json(['registered' => true]);
    }
}
