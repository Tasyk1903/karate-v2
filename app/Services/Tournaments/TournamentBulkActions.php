<?php

namespace App\Services\Tournaments;

use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\ListTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class TournamentBulkActions
{
    public function detach(User $actor, Tournament $tournament, string $kind, array $ids): void
    {
        DB::transaction(function () use ($actor, $tournament, $kind, $ids): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
            abort_unless(TournamentLifecycle::canManage($actor, $tournament), 403);
            if ($kind === 'coaches') {
                $rows = DB::table('tournament_treners')->where('tournament_id', $tournament->id)->whereIn('trener_id', $ids)->lockForUpdate()->get();
                abort_unless($rows->pluck('trener_id')->unique()->count() === count($ids), 422, __('tour.selection'));
                DB::table('tournament_treners')->whereIn('id', $rows->pluck('id'))->delete();
            } else {
                $rows = ListTournament::where('tournament_id', $tournament->id)->whereIn('id', $ids)->lockForUpdate()->get();
                abort_unless($rows->count() === count($ids), 422, __('tour.selection'));
                foreach ($rows as $list) {
                    app(TournamentApplications::class)->assertMutable($list);
                    if (DB::table('tournament_student_lists')->where('list_tournament_id', $list->id)->exists()
                        || DB::table('student_tournaments')->where('list_tournament_id', $list->id)->exists()) {
                        throw ValidationException::withMessages(['ids' => __('lists.not_empty')]);
                    }
                }
                ListTournament::whereIn('id', $ids)->delete();
            }
            TeamActivity::record($actor, 'tournament.'.$kind.'.bulk_detached', Tournament::class, $tournament->id,
                ['ids' => $ids, 'old' => $rows->toArray(), 'new' => [], 'students_preserved' => true]);
        });
    }

    public function deleteForms(User $actor, Championship $championship, array $ids): void
    {
        DB::transaction(function () use ($actor, $championship, $ids): void {
            $championship = Championship::lockForUpdate()->findOrFail($championship->id);
            abort_unless($actor->hasAnyProjectRole(['Organization', 'Secretary']) && (int) $championship->organization_id === (int) TournamentLifecycle::organizationId($actor), 403);
            abort_if($championship->tournaments()->exists() && ! $championship->tournaments()->whereDate('date_finish', '>=', today())->exists(), 403);
            $forms = ExternalForm::where('championship_id', $championship->id)->whereIn('id', $ids)->lockForUpdate()->get();
            abort_unless($forms->count() === count($ids), 422, __('tour.selection'));
            abort_if(DB::table('external_form_import_runs')->whereIn('external_form_id', $ids)->whereIn('status', ['queued', 'running'])->exists(), 409, __('tour.import_running'));
            foreach ($forms as $form) {
                $before = $form->only(['id', 'organization_name', 'status']);
                $form->delete();
                TeamActivity::record($actor, 'championship.external_form.deleted', ExternalForm::class, $form->id,
                    ['championship_id' => $championship->id, 'old' => $before, 'new' => null, 'participants_preserved' => true]);
            }
            TeamActivity::record($actor, 'championship.forms.bulk_deleted', Championship::class, $championship->id, ['ids' => $ids, 'old' => $ids, 'new' => []]);
        });
    }
}
