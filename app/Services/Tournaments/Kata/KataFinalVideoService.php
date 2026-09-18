<?php

namespace App\Services\Tournaments\Kata;

use App\Jobs\DeleteUnusedKataVideo;
use App\Models\KataPool;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

final class KataFinalVideoService
{
    public function replace(User $actor, KataPool $pool, int $studentId, int $categoryId, UploadedFile $file): StudentTournament
    {
        Validator::make(['category_id' => $categoryId, 'video' => $file], ['category_id' => ['required', 'integer', 'exists:education_klass_categories,id'], 'video' => KataVideoUpload::rules()])->validate();
        $path = $file->store('online-kata-videos', 'protected');
        try {
            return DB::transaction(function () use ($actor, $pool, $studentId, $categoryId, $path) {
                $tournament = Tournament::lockForUpdate()->findOrFail($pool->tournament_id);
                $pool = KataPool::where('tournament_id', $tournament->id)->lockForUpdate()->findOrFail($pool->id);
                abort_unless((int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM && $tournament->is_online_kata && $pool->round === 'FINAL', 404);
                abort_unless(TournamentLifecycle::active($tournament), 403);
                $members = array_map('intval', $pool->students ?: [$pool->student_id]);
                abort_unless(in_array($studentId, $members, true), 403);
                $access = app(KataVideoAccess::class);
                $application = $access->application($pool, $studentId);
                $ownCoach = $access->updateReason($actor, $tournament, $pool, User::find($studentId), $application) === null;
                abort_unless(TournamentLifecycle::canManage($actor, $tournament) || $ownCoach, 403);
                abort_unless($application, 404);
                $application = StudentTournament::lockForUpdate()->findOrFail($application->id);
                $fields = ['online_kata_second_round_category_id', 'online_kata_second_round_video_path'];
                $old = $application->only($fields);
                $application->update(['online_kata_second_round_category_id' => $categoryId, 'online_kata_second_round_video_path' => $path]);
                TeamActivity::record($actor, 'tournament.kata.final_video_uploaded', Tournament::class, $tournament->id, ['kata_pool_id' => $pool->id, 'list_id' => $pool->list_id, 'student_id' => $studentId, 'old' => $old, 'new' => $application->only($fields)]);
                if ($old['online_kata_second_round_video_path']) {
                    $job = new DeleteUnusedKataVideo($old['online_kata_second_round_video_path']);
                    // Durable cleanup is committed with the application, on the same database.
                    Queue::connection('protected_media_cleanup')->push($job);
                    DB::afterCommit(function () use ($job) {
                        try {
                            $job->handle(app(ProtectedMedia::class));
                        } catch (\Throwable $error) {
                            report($error);
                        }
                    });
                }

                return $application;
            }, 3);
        } catch (\Throwable $error) {
            try {
                if (! Storage::disk('protected')->delete($path)) {
                    throw new \RuntimeException('Could not remove uncommitted kata video.');
                }
            } catch (\Throwable $cleanup) {
                report($cleanup);
                try {
                    Queue::connection('protected_media_cleanup')->push(new DeleteUnusedKataVideo($path));
                } catch (\Throwable $queueError) {
                    report($queueError);
                }
            }
            throw $error;
        }
    }
}
