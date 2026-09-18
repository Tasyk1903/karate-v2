<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\PanelTask;
use App\Services\Exports\PanelTasks;
use App\Services\ProtectedMedia;
use Illuminate\Http\Request;

class PanelTaskController extends Controller
{
    public function show(Request $request, PanelTask $task, PanelTasks $tasks)
    {
        $tasks->authorize($request->user(), $task);

        return response()->json(['task' => $tasks->format($task)]);
    }

    public function file(Request $request, PanelTask $task, PanelTasks $tasks, ProtectedMedia $media)
    {
        $tasks->authorize($request->user(), $task);
        abort_unless($task->status === 'ready' && $task->path && $task->expires_at->isFuture(), 404);
        $response = $media->response($task->path, $task->filename);
        $tasks->log($task, 'downloaded', 'ready');

        return $response;
    }
}
