<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Services\Tournaments\CoachQuickFights;
use Illuminate\Http\Request;

final class MobileQuickFightController extends Controller
{
    public function index(Request $request, CoachQuickFights $fights)
    {
        abort_unless($request->user()->hasProjectRole('Coach'), 403);

        return response()->json($fights->data($request->user(), $request));
    }
}
