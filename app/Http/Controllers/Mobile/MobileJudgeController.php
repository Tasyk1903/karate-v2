<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\KataPool;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Tournaments\Kata\JudgeKataAccess;
use App\Services\Tournaments\Kata\JudgeTables;
use App\Services\Tournaments\Kata\KataAccess;
use App\Services\Tournaments\Kata\KataScoreService;
use Illuminate\Http\Request;

final class MobileJudgeController extends Controller
{
    public function tournaments(Request $request, JudgeKataAccess $access)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100']);
        $query = Tournament::whereIn('id', $access->lists($request->user())->select('tournament_id'));
        if ($search = trim($data['search'] ?? '')) {
            $query->where('name', 'like', '%'.$search.'%');
        }
        $page = $query->orderBy('name')->orderBy('id')->paginate(20, ['id', 'name']);

        return response()->json(['data' => $page->getCollection()->map(fn ($t) => ['id' => $t->id, 'title' => $t->name]),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function currentScore(Request $request, int $list, int $pool, JudgeTables $tables)
    {
        $table = $tables->list($request->user(), $list);
        $target = KataPool::where('tournament_id', $table->tournament_id)->where('list_id', $list)->findOrFail($pool);
        $field = KataAccess::fields($request->user(), $table->tournament)[0] ?? null;

        return response()->json(['score' => $field ? $target->getRawOriginal($field) : null])->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, JudgeKataAccess $access, JudgeTables $tables)
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'search' => 'nullable|string|max:100',
            'tournament_id' => 'nullable|integer|min:1', 'sort' => 'sometimes|in:id,tatami']);
        $query = $access->lists($request->user())->with(['tournament.championship', 'templateStudentList']);
        if ($search = trim($data['search'] ?? '')) {
            $query->where(fn ($q) => $q->whereHas('tournament', fn ($t) => $t->where('name', 'like', '%'.$search.'%'))
                ->orWhereHas('templateStudentList', fn ($t) => $t->where('name', 'like', '%'.$search.'%')));
        }
        if ($id = $data['tournament_id'] ?? null) {
            $query->where('tournament_id', $id);
        }
        $page = $query->orderBy($data['sort'] ?? 'tatami')->orderBy('id')->paginate(20);

        return response()->json(['data' => $page->getCollection()->map($tables->heading(...)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()]]);
    }

    public function show(Request $request, int $list, JudgeTables $tables)
    {
        return response()->json($tables->table($request, $tables->list($request->user(), $list)))->header('Cache-Control', 'private, no-store');
    }

    public function score(Request $request, int $list, int $pool, JudgeTables $tables, KataScoreService $scores)
    {
        $table = $tables->list($request->user(), $list);
        $target = KataPool::where('tournament_id', $table->tournament_id)->where('list_id', $list)->findOrFail($pool);
        $data = $request->validate(['field' => 'required|string', 'value' => 'present|nullable', 'original_value' => 'present|nullable|string|max:255']);
        $scores->update($request->user(), $target, $data['field'], $data['value'], $data['original_value'], false, null);

        return response()->json(['saved' => true]);
    }

    public function video(Request $request, int $pool, int $student, JudgeTables $tables, ProtectedMedia $media)
    {
        $target = KataPool::findOrFail($pool);
        $table = $tables->list($request->user(), $target->list_id);
        abort_unless($target->tournament_id == $table->tournament_id && $table->tournament->is_online_kata
            && in_array($student, array_map('intval', $target->students ?: [$target->student_id]), true), 404);
        User::findOrFail($student);
        $application = StudentTournament::where('tournament_id', $target->tournament_id)->where('list_tournament_id', $table->id)
            ->where('student_id', $student)->orderBy('id')->first();

        return $media->response($tables->roundVideo($application, $target->round)['path']);
    }
}
