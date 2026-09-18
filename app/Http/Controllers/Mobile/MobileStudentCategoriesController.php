<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Students\StudentProfileAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class MobileStudentCategoriesController extends Controller
{
    public function index(Request $request, User $student)
    {
        abort_unless(app(StudentProfileAccess::class)->owns($request->user(), $student), 403);
        $rows = Tournament::query()->whereHas('championship')->whereDate('date_finish', '>=', today())
            ->whereHas('students', fn ($q) => $q->where('users.id', $student->id))->orderBy('date')->orderBy('id')
            ->paginate(20, ['id', 'name', 'tournament_type']);
        $lists = DB::table('tournament_student_lists as members')->join('list_tournaments as lists', 'lists.id', '=', 'members.list_tournament_id')
            ->join('template_student_lists as templates', 'templates.id', '=', 'lists.template_student_list_id')
            ->where('members.student_id', $student->id)->whereIn('lists.tournament_id', $rows->getCollection()->pluck('id'))
            ->select('lists.tournament_id', 'templates.name')->distinct()->get()->groupBy('tournament_id');
        $rows->through(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'type' => (int) $t->tournament_type === Tournament::KATA ? 'kata' : 'kumite',
            'categories' => ($lists->get($t->id) ?? collect())->pluck('name')->values()]);

        return response()->json(['data' => $rows->items(), 'meta' => ['current_page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]]);
    }
}
