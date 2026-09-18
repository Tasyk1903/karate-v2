<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\KataPool;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Tournaments\CoachTournamentAccess;
use App\Services\Tournaments\Kata\KataVideoAccess;
use Illuminate\Http\Request;

final class MobileKataVideoController extends Controller
{
    public function show(Request $request, KataPool $pool, User $student, KataVideoAccess $access)
    {
        $tournament = $pool->tournament;
        abort_unless($tournament && app(CoachTournamentAccess::class)->online($tournament), 404);
        abort_unless($access->canView($request->user(), $tournament, $student), 403);
        $application = $access->application($pool, $student->id);
        abort_unless($application, 404);
        $path = $pool->round === 'FINAL' ? $application->online_kata_second_round_video_path
            : ($application->online_kata_first_round_video_path ?: $application->online_kata_video_path);

        return app(ProtectedMedia::class)->response($path);
    }
}
