<?php

namespace App\Jobs;

use App\Models\PanelTask;
use App\Models\User;
use App\Services\Exports\PanelTaskRunner;
use App\Services\Exports\PanelTasks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

class RunPanelTask implements ShouldQueue
{
    use Queueable;

    public int $timeout = 600;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(public string $taskId) {}

    public function handle(PanelTasks $tasks, PanelTaskRunner $runner): void
    {
        $task = PanelTask::find($this->taskId);
        if (! $task || $task->status !== 'queued' || $task->expires_at->isPast()) {
            return;
        }
        if (! PanelTask::whereKey($task->id)->where('status', 'queued')->update(['status' => 'processing', 'updated_at' => now()])) {
            return;
        }
        $previousRequest = app('request');
        $locale = app()->getLocale();
        $previousUser = auth()->user();
        $path = null;
        try {
            $actor = User::findOrFail($task->user_id);
            $tasks->authorize($actor, $task);
            app()->setLocale($task->locale);
            $tasks->log($task, 'processing', 'queued');
            $execute = function () use ($task, $actor, $runner, $tasks, &$path): void {
                $response = $runner->run($task, $actor);
                abort_unless($response->getStatusCode() === 200, 500);
                $changes = ['status' => 'ready'];
                if ($task->kind !== 'generate') {
                    $mime = $response->headers->get('Content-Type', '');
                    $extension = str_contains($mime, 'pdf') ? 'pdf' : 'xlsx';
                    $path = 'panel-tasks/'.$task->id.'.'.$extension;
                    if ($response instanceof BinaryFileResponse) {
                        $stream = fopen($response->getFile()->getPathname(), 'rb');
                        try {
                            Storage::disk('protected')->put($path, $stream);
                        } finally {
                            fclose($stream);
                        }
                        // Excel creates an expendable temporary file.
                        if (str_starts_with($response->getFile()->getPathname(), storage_path('framework/'))) {
                            @unlink($response->getFile()->getPathname());
                        }
                    } else {
                        Storage::disk('protected')->put($path, $response->getContent());
                    }
                    abort_unless(Storage::disk('protected')->exists($path), 500);
                    $disposition = HeaderUtils::combine(HeaderUtils::split($response->headers->get('Content-Disposition', ''), ';='));
                    $filename = basename($disposition['filename'] ?? $task->kind.'-'.$task->id.'.'.$extension);
                    $changes += ['path' => $path, 'filename' => $filename, 'content_type' => $mime];
                }
                $task->update($changes);
                $tasks->log($task, 'ready', 'processing');
            };
            // Generation and ready state commit together, so a lost worker cannot duplicate a completed generation.
            DB::transaction($execute);
        } catch (Throwable $e) {
            if ($path) {
                Storage::disk('protected')->delete($path);
            }
            $this->failed($e);
            report($e);
        } finally {
            app()->instance('request', $previousRequest);
            app()->setLocale($locale);
            if ($previousUser) {
                auth()->setUser($previousUser);
            } else {
                auth()->forgetUser();
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $task = PanelTask::find($this->taskId);
        if ($task && in_array($task->status, ['queued', 'processing'], true)) {
            if ($task->path) {
                Storage::disk('protected')->delete($task->path);
            }
            $old = $task->status;
            $task->update(['status' => 'failed', 'path' => null]);
            app(PanelTasks::class)->log($task, 'failed', $old);
        }
    }
}
