<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Account\StudentAccountRestore;
use Illuminate\Http\Request;

final class MobileStudentRestoreController extends Controller
{
    public function request(Request $request, StudentAccountRestore $restore)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $restore->request($data['email']);

        return response()->json(['sent' => true]);
    }

    public function confirm(Request $request, StudentAccountRestore $restore)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'code' => ['required', 'digits:6'], 'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);
        $restore->confirm($data['email'], $data['code'], $data['password']);

        return response()->json(['restored' => true]);
    }
}
