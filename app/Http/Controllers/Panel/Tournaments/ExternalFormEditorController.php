<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\ExternalForm;
use App\Services\Tournaments\ExternalFormRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExternalFormEditorController extends BaseTournamentController
{
    private function authorizeEditor(Request $request, Championship $championship, ExternalForm $form): void
    {
        abort_unless((int) $form->championship_id === (int) $championship->id, 404);
        $this->authorizeChampionshipOwner($request->user(), $championship);
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
    }

    public function show(Request $request, Championship $championship, ExternalForm $form, ExternalFormRows $service): JsonResponse
    {
        $this->authorizeEditor($request, $championship, $form);
        $rows = collect($service->rows($form))->map(fn ($row, $index) => $row + ['row_number' => $index + 1]);
        $search = mb_strtolower(trim((string) $request->input('search', '')));
        if ($search !== '') {
            $rows = $rows->filter(fn ($row) => str_contains(mb_strtolower(implode(' ', array_intersect_key($row, array_flip(['first_name', 'last_name', 'club', 'coach_first_name', 'coach_last_name'])))), $search));
        }
        $last = max(1, (int) ceil($rows->count() / 20));
        $page = max(1, min($last, $request->integer('page', 1)));
        $pageRows = $rows->values()->slice(($page - 1) * 20, 20)->values();
        $links = DB::table('external_form_students')->where('external_form_id', $form->id)->whereIn('row_id', $pageRows->pluck('row_id'))->pluck('user_id', 'row_id');

        return response()->json(['form' => $this->formatExternalForm($form), 'championship' => ['id' => $championship->id, 'name' => $championship->name],
            'revision' => $service->revision($form), 'category_options' => $service->categoryOptions($form),
            'rows' => $pageRows->map(fn ($row) => $row + ['student_id' => $links[$row['row_id']] ?? null]),
            'meta' => ['current_page' => $page, 'last_page' => $last, 'total' => $rows->count()],
            'latest_run_id' => DB::table('external_form_import_runs')->where('external_form_id', $form->id)->max('id')]);
    }

    public function update(Request $request, Championship $championship, ExternalForm $form, ExternalFormRows $service): JsonResponse
    {
        $this->authorizeEditor($request, $championship, $form);
        $data = $request->validate(['revision' => ['required', 'string', 'size:64'], 'upserts' => ['sometimes', 'array', 'max:100'], 'upserts.*' => ['array'],
            'deletes' => ['sometimes', 'array', 'max:100'], 'deletes.*' => ['uuid', 'distinct']]);
        DB::transaction(function () use ($form, $data, $request, $service): void {
            $locked = ExternalForm::query()->lockForUpdate()->findOrFail($form->id);
            $rows = collect($service->rows($locked))->keyBy('row_id');
            foreach ($data['deletes'] ?? [] as $id) {
                abort_unless($rows->has($id), 404);
                $rows->forget($id);
            }
            $seen = [];
            foreach ($data['upserts'] ?? [] as $row) {
                $id = $row['row_id'] ?? null;
                if ($id) {
                    abort_unless($rows->has($id) && ! isset($seen[$id]), 422);
                    $rows->put($id, $row);
                    $seen[$id] = true;
                } else {
                    $rows->push($row);
                }
            }
            $service->saveRows($locked, $rows->values()->all(), $request->user(), $data['revision']);
        });

        return $this->show($request, $championship, $form->fresh(), $service);
    }

    public function report(Request $request, Championship $championship, ExternalForm $form, int $run): JsonResponse
    {
        $this->authorizeEditor($request, $championship, $form);
        $record = DB::table('external_form_import_runs')->where('external_form_id', $form->id)->where('id', $run)->first();
        abort_unless($record, 404);
        $result = json_decode($record->result ?? '{}', true);
        $entries = collect($result['entries'] ?? []);
        $last = max(1, (int) ceil($entries->count() / 20));
        $page = max(1, min($last, $request->integer('page', 1)));
        unset($result['entries']);

        return response()->json(['id' => $record->id, 'status' => $record->status, 'error_code' => $record->error_code,
            'result' => $result, 'entries' => $entries->slice(($page - 1) * 20, 20)->values(),
            'meta' => ['current_page' => $page, 'last_page' => $last, 'total' => $entries->count()]]);
    }
}
