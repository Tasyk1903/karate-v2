<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\Tournament;
use App\Services\Tournaments\StudentTournamentEnrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StudentEnrollmentController extends Controller
{
    public function store(Request $request, Championship $championship, Tournament $tournament, StudentTournamentEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->admitted($request->user(), $tournament), 403);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $enrollment->attach($request->user(), $tournament);

        return response()->json(['self_enrollment' => $enrollment->capabilities($request->user(), $tournament)]);
    }

    public function destroy(Request $request, Championship $championship, Tournament $tournament, int $membership, StudentTournamentEnrollment $enrollment): JsonResponse
    {
        abort_unless($enrollment->admitted($request->user(), $tournament), 403);
        abort_unless((int) $tournament->championship_id === (int) $championship->id, 404);
        $request->validate(['confirmed' => ['required', 'accepted']]);
        $enrollment->detach($request->user(), $tournament, $membership);

        return response()->json(['self_enrollment' => $enrollment->capabilities($request->user(), $tournament)]);
    }
}
