<?php

namespace App\Services\Exports;

use App\Jobs\RunPanelTask;
use App\Models\Championship;
use App\Models\PanelTask;
use App\Models\Tournament;
use App\Models\User;
use App\Services\PanelAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class PanelTasks
{
    public static function background(Request $request): bool
    {
        return $request->attributes->get('panel_task') === true;
    }

    public static function shouldDefer(Request $request): bool
    {
        return ! self::background($request) && $request->user()->hasAnyProjectRole(['Organization', 'Secretary']);
    }

    public function defer(Request $request, string $kind, array $context)
    {
        $user = $request->user();
        $organization = $user->hasProjectRole('Organization') ? $user->id : $user->organization_id;
        if ($kind === 'mobile_tournament') {
            $tournament = Tournament::findOrFail($context['tournament']);
            app(MobileTournamentExports::class)->authorize($user, $tournament, $context['type']);
            $organization = $tournament->organization_id;
        } else {
            abort_unless($organization && $user->hasAnyProjectRole(['Organization', 'Secretary']), 403);
        }
        $locale = app()->getLocale() === 'en' ? 'en' : 'ru';
        $key = hash('sha256', json_encode([$user->id, $organization, $kind, $context, $locale]));
        $task = DB::transaction(function () use ($user, $organization, $kind, $context, $locale, $key) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($existing = PanelTask::where('fingerprint', $key)->whereIn('status', ['queued', 'processing'])->where('expires_at', '>', now())->first()) {
                return $existing;
            }
            $task = PanelTask::create(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'organization_id' => $organization,
                'status' => 'queued', 'kind' => $kind, 'context' => $context, 'locale' => $locale, 'fingerprint' => $key, 'expires_at' => now()->addDay()]);
            $this->log($task, 'queued', null);
            RunPanelTask::dispatch($task->id)->onConnection('panel_tasks')->onQueue('panel_tasks')->afterCommit();

            return $task;
        });

        return $request->expectsJson()
            ? response()->json(['task' => $this->format($task)], 202)
            : redirect('/panel/tasks/'.$task->id);
    }

    public function authorize(User $user, PanelTask $task): void
    {
        if ($task->kind === 'mobile_tournament') {
            abort_unless((int) $task->user_id === (int) $user->id, 403);
            $tournament = Tournament::whereHas('championship')->findOrFail($task->context['tournament']);
            app(MobileTournamentExports::class)->authorize($user, $tournament, $task->context['type']);

            return;
        }
        $org = $user->hasProjectRole('Organization') ? $user->id : $user->organization_id;
        abort_unless((int) $task->user_id === (int) $user->id && (int) $task->organization_id === (int) $org
            && $user->hasAnyProjectRole(['Organization', 'Secretary']), 403);
        $c = $task->context;
        if (isset($c['championship'])) {
            $championship = Championship::findOrFail($c['championship']);
            abort_unless((int) $championship->organization_id === (int) $org, 403);
            if (isset($c['tournament'])) {
                abort_unless(Tournament::whereKey($c['tournament'])->where('championship_id', $championship->id)->where('organization_id', $org)->exists(), 403);
            }
        }
        if ($task->kind === 'team') {
            abort_unless(in_array($c['filters']['section'] ?? 'students', PanelAccess::teamSections($user), true), 403);
        }
        if ($task->kind === 'trainer') {
            abort_unless(User::role('Coach')->whereKey($c['trainer'])->where('organization_id', $org)->exists(), 403);
        }
    }

    public function format(PanelTask $task): array
    {
        $expired = $task->expires_at->isPast();
        $c = $task->context;

        return ['id' => $task->id, 'status' => $expired ? 'expired' : $task->status, 'kind' => $task->kind,
            'download_url' => ! $expired && $task->status === 'ready' && $task->path ? url('/api/'.($task->kind === 'mobile_tournament' ? 'mobile' : 'panel').'/tasks/'.$task->id.'/file') : null,
            'return_url' => isset($c['tournament']) ? "/panel/tournaments/{$c['championship']}/items/{$c['tournament']}/edit?tab=brackets"
                : (isset($c['championship']) ? "/panel/tournaments/{$c['championship']}" : (isset($c['trainer']) ? "/panel/team/trainers/{$c['trainer']}" : '/panel/team')),
        ];
    }

    public function log(PanelTask $task, string $status, ?string $old): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'panel', 'event' => 'panel.task.'.$status, 'description' => 'panel.task.'.$status,
            'subject_type' => PanelTask::class, 'subject_id' => null, 'causer_type' => User::class, 'causer_id' => $task->user_id,
            'properties' => json_encode(['task_id' => $task->id, 'kind' => $task->kind, 'context' => $task->context,
                'organization_id' => $task->organization_id, 'old' => $old, 'new' => $status, 'locale' => $task->locale]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
