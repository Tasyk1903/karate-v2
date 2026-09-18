<?php

namespace App\Http\Controllers\Panel\Tournaments;

use App\Models\Championship;
use App\Models\ExternalForm;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TournamentFormController extends BaseTournamentController
{
    public function storeForm(Request $request, Championship $championship): JsonResponse
    {
        $this->authorizeChampionshipOwner($request->user(), $championship);

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
        ]);

        $form = $championship->externalForms()->create([
            'organization_name' => $data['organization_name'],
            'token' => (string) Str::uuid(),
            'status' => 'open',
        ]);

        $this->writeTournamentActivity(
            $request->user(),
            'Создана анкета команды',
            'championship.external_form.created',
            ExternalForm::class,
            $form->id,
            [
                'championship' => $this->championshipSnapshot($championship),
                'new' => [
                    'id' => $form->id,
                    'organization_name' => $form->organization_name,
                    'status' => $form->status,
                ],
            ]
        );

        return response()->json(['item' => $this->formatExternalForm($form)], 201);
    }

    public function updateForm(Request $request, Championship $championship, ExternalForm $form): JsonResponse
    {
        $this->authorizeFormManage($request, $championship, $form);

        $data = $request->validate([
            'organization_name' => ['required', 'string', 'max:255'],
        ]);

        $old = $form->only(['organization_name']);
        $form->update(['organization_name' => $data['organization_name']]);

        $this->writeTournamentActivity(
            $request->user(),
            'Изменена анкета команды',
            'championship.external_form.updated',
            ExternalForm::class,
            $form->id,
            ['championship' => $this->championshipSnapshot($championship), 'old' => $old, 'new' => $form->only(['organization_name'])]
        );

        return response()->json(['item' => $this->formatExternalForm($form)]);
    }

    public function updateStatus(Request $request, Championship $championship, ExternalForm $form): JsonResponse
    {
        $this->authorizeFormManage($request, $championship, $form);

        $data = $request->validate([
            'status' => ['required', 'in:open,closed'],
        ]);

        $old = $form->status;
        $form->update(['status' => $data['status']]);

        $this->writeTournamentActivity(
            $request->user(),
            $data['status'] === 'closed' ? 'Закрыта анкета команды' : 'Открыта анкета команды',
            'championship.external_form.status_updated',
            ExternalForm::class,
            $form->id,
            ['championship' => $this->championshipSnapshot($championship), 'old' => ['status' => $old], 'new' => ['status' => $form->status]]
        );

        return response()->json(['item' => $this->formatExternalForm($form)]);
    }

    public function importParticipants(
        Request $request,
        Championship $championship,
        ExternalForm $form,
    ): JsonResponse {
        $this->authorizeFormManage($request, $championship, $form);
        app()->setLocale($request->input('locale', app()->getLocale()) === 'en' ? 'en' : 'ru');
        abort_unless($form->status === 'closed', 422, __('forms.must_close'));

        $data = $request->validate(['sync_profiles' => ['sometimes', 'boolean'], 'revision' => ['sometimes', 'string', 'size:64']]);
        $organization = $championship->organization_id;
        abort_if(\App\Models\User::withTrashed()->whereIn('id', \Illuminate\Support\Facades\DB::table('external_form_students')->where('external_form_id', $form->id)->select('user_id'))
            ->where(fn ($query) => $query->where('organization_id', '!=', $organization)->orWhereNull('organization_id')->orWhereNotNull('deleted_at'))->exists(), 403);
        $runId = \Illuminate\Support\Facades\DB::transaction(function () use ($form, $request, $data): int {
            $locked = ExternalForm::query()->lockForUpdate()->findOrFail($form->id);
            abort_unless($locked->status === 'closed', 422, __('forms.must_close'));
            $existing = \Illuminate\Support\Facades\DB::table('external_form_import_runs')->where('external_form_id', $form->id)->whereIn('status', ['queued', 'running'])->value('id');
            if ($existing) return (int) $existing;
            $revision = app(\App\Services\Tournaments\ExternalFormRows::class)->revision($locked);
            abort_if(isset($data['revision']) && !hash_equals($revision, $data['revision']), 409, __('forms.stale'));
            $id = \Illuminate\Support\Facades\DB::table('external_form_import_runs')->insertGetId([
                'external_form_id' => $form->id, 'actor_id' => $request->user()->id, 'revision' => $revision,
                'sync_profiles' => (bool) ($data['sync_profiles'] ?? false), 'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            \App\Services\Team\TeamActivity::record($request->user(), 'external_form.import.queued', ExternalForm::class, $form->id,
                ['run_id' => $id, 'sync_profiles' => (bool) ($data['sync_profiles'] ?? false), 'revision' => $revision]);
            \App\Jobs\ImportExternalForm::dispatch($id)->onConnection('deferred')->afterCommit();
            return $id;
        });

        return response()->json(['run_id' => $runId, 'item' => $this->formatExternalForm($form->fresh())], 202);
    }

    public function destroyForm(Request $request, Championship $championship, ExternalForm $form): JsonResponse
    {
        $this->authorizeFormManage($request, $championship, $form);

        app(\App\Services\Tournaments\TournamentBulkActions::class)->deleteForms($request->user(), $championship, [$form->id]);

        return response()->json(['ok' => true]);
    }

    private function authorizeFormManage(Request $request, Championship $championship, ExternalForm $form): void
    {
        abort_unless((int) $form->championship_id === (int) $championship->id, 404);
        $this->authorizeChampionshipOwner($request->user(), $championship);
    }
}
