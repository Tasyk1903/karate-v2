<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Account\MasterProfile;
use Illuminate\Http\Request;

final class MobileStaffProfileController extends Controller
{
    public function show(Request $request, MasterProfile $profiles)
    {
        return response()->json(['data' => $profiles->format($request->user())]);
    }

    public function update(Request $request, MasterProfile $profiles)
    {
        return response()->json(['data' => $profiles->format($profiles->update($request->user(), $request))]);
    }
}
