<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Account\MobileSession;
use App\Services\Team\JoinCoach;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MobileJoinCoachController extends Controller
{
    public function preview(Request $request, JoinCoach $join): JsonResponse
    {
        $data = $request->validate(['coach_code' => 'required|string|max:64']);

        return response()->json(['coach' => $join->preview($request->user(), $data['coach_code'])])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, JoinCoach $join, MobileSession $session): JsonResponse
    {
        $data = $request->validate(['coach_code' => 'required|string|max:64', 'coach_id' => 'required|integer|min:1', 'confirmed' => 'required|accepted']);
        $student = $join->join($request->user(), $data['coach_code'], (int) $data['coach_id']);

        return response()->json(['joined' => true, 'user' => $session->user($student)])->header('Cache-Control', 'no-store');
    }
}
