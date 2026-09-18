<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\ExternalForm;
use App\Services\Tournaments\ExternalFormStudentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ExternalFormStudentLinkController extends BaseTournamentController
{
    private function authorizeLink(Request $request, Championship $championship, ExternalForm $form): void
    {
        abort_unless((int) $form->championship_id === (int) $championship->id, 404);
        $this->authorizeChampionshipOwner($request->user(), $championship);
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
    }

    public function index(Request $request, Championship $championship, ExternalForm $form, ExternalFormStudentLink $links): JsonResponse
    {
        $this->authorizeLink($request, $championship, $form);
        $search = trim((string) $request->query('search', ''));
        $students = $links->candidates($form)->with('coach:id,first_name,last_name,club')
            ->when($search !== '', fn ($query) => $query->where(fn ($names) => $names->where('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')))
            ->orderBy('last_name')->orderBy('id')->paginate(20, ['id', 'first_name', 'last_name', 'birthday', 'coach_id']);

        return response()->json(['rows' => $students->getCollection()->map(fn ($user) => ['id' => $user->id, 'name' => $user->full_name, 'birthday' => $user->birthday,
            'club' => $user->coach?->club, 'coach' => $user->coach?->full_name]), 'meta' => ['current_page' => $students->currentPage(), 'last_page' => $students->lastPage(), 'total' => $students->total()]]);
    }

    public function store(Request $request, Championship $championship, ExternalForm $form, ExternalFormStudentLink $links): JsonResponse
    {
        $this->authorizeLink($request, $championship, $form);
        $data = $request->validate(['row_id' => ['required', 'uuid'], 'student_id' => ['required', 'integer'], 'revision' => ['required', 'string', 'size:64']]);
        $links->link($form, $request->user(), $data['row_id'], $data['student_id'], $data['revision']);

        return response()->json(['ok' => true]);
    }
}
