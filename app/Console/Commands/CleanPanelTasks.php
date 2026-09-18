<?php

namespace App\Console\Commands;

use App\Models\PanelTask;
use App\Services\Exports\PanelTasks;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class CleanPanelTasks extends Command
{
    protected $signature = 'panel:clean-tasks';

    protected $description = 'Expire private export files and detect interrupted panel workers';

    public function handle(PanelTasks $tasks): int
    {
        PanelTask::whereIn('status', ['queued', 'processing', 'ready', 'failed'])
            ->where(fn ($q) => $q->where('expires_at', '<=', now())->orWhere(fn ($q) => $q->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(15))))
            ->chunkById(100, function ($rows) use ($tasks) {
                foreach ($rows as $task) {
                    Storage::disk('protected')->delete(array_filter([$task->path, 'panel-tasks/'.$task->id.'.pdf', 'panel-tasks/'.$task->id.'.xlsx']));
                    $old = $task->status;
                    $status = $task->expires_at->isPast() ? 'expired' : 'failed';
                    $task->update(['status' => $status, 'path' => null]);
                    $tasks->log($task, $status, $old);
                }
            });

        return self::SUCCESS;
    }
}
