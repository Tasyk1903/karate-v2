<?php

namespace App\Services\Tournaments;

use App\Models\OrganizationTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Models\UserAlert;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;

final class OrganizationTournamentApplications
{
    public function apply(User $actor, Tournament $tournament): OrganizationTournament
    {
        abort_unless($actor->hasProjectRole('Organization'), 403);

        return DB::transaction(function () use ($actor, $tournament) {
            $tournament = Tournament::lockForUpdate()->findOrFail($tournament->id);
            abort_unless($tournament->championship && $tournament->accepts_organization_applications && TournamentLifecycle::commissionOpen($tournament), 403);
            abort_if((int) $tournament->organization_id === (int) $actor->id, 403);
            $existing = OrganizationTournament::where('tournament_id', $tournament->id)->where('applicant_organizer_id', $actor->id)->latest('id')->first();
            if ($existing) {
                return $existing;
            }
            $application = OrganizationTournament::create(['tournament_id' => $tournament->id, 'applicant_organizer_id' => $actor->id, 'is_success' => 'pending']);
            $this->notify($tournament->organization_id, __('tour.application_received', ['name' => $tournament->name]));
            TeamActivity::record($actor, 'tournament.application.submitted', OrganizationTournament::class, $application->id, ['old' => null, 'new' => $application->toArray()]);

            return $application;
        });
    }

    public function decide(User $actor, OrganizationTournament $application, string $status): void
    {
        DB::transaction(function () use ($actor, $application, $status): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($application->tournament_id);
            abort_unless(TournamentLifecycle::canManage($actor, $tournament), 403);
            $application->refresh();
            if ($application->is_success === $status) {
                return;
            }
            $old = $application->is_success;
            $related = OrganizationTournament::where('tournament_id', $tournament->id)->where('applicant_organizer_id', $application->applicant_organizer_id);
            $ids = (clone $related)->pluck('id');
            $related->update(['is_success' => $status]);
            $detached = [];
            if ($status === 'canceled') {
                $coaches = DB::table('tournament_treners')->whereIn('organization_application_id', $ids);
                $detached = (clone $coaches)->pluck('trener_id')->all();
                $coaches->delete();
            }
            $this->notify($application->applicant_organizer_id, __('tour.application_'.$status, ['name' => $tournament->name]));
            TeamActivity::record($actor, 'tournament.application.decided', OrganizationTournament::class, $application->id,
                ['tournament_id' => $tournament->id, 'old' => ['is_success' => $old], 'new' => ['is_success' => $status], 'detached_coach_ids' => $detached, 'participants_preserved' => true]);
        });
    }

    public function coaches(User $actor, OrganizationTournament $application, array $ids, bool $attach): void
    {
        DB::transaction(function () use ($actor, $application, $ids, $attach): void {
            $tournament = Tournament::lockForUpdate()->findOrFail($application->tournament_id);
            $application->refresh();
            abort_unless($actor->hasAnyProjectRole(['Organization', 'Secretary']) && (int) $application->applicant_organizer_id === (int) TournamentLifecycle::organizationId($actor)
                && $application->is_success === 'accepted' && $tournament->championship && TournamentLifecycle::commissionOpen($tournament), 403);
            $coaches = User::role('Coach')->where('organization_id', $application->applicant_organizer_id)->whereIn('id', $ids)->get(['id']);
            abort_unless($coaches->count() === count($ids), 422, __('tour.selection'));
            $changed = [];
            foreach ($ids as $id) {
                $query = DB::table('tournament_treners')->where('tournament_id', $tournament->id)->where('trener_id', $id);
                if ($attach && ! $query->exists()) {
                    DB::table('tournament_treners')->insert(['tournament_id' => $tournament->id, 'trener_id' => $id, 'organization_application_id' => $application->id, 'created_at' => now(), 'updated_at' => now()]);
                    $changed[] = $id;
                } elseif (! $attach) {
                    if ($query->where('organization_application_id', $application->id)->delete()) {
                        $changed[] = $id;
                    }
                }
            }
            TeamActivity::record($actor, 'tournament.application.coaches_updated', OrganizationTournament::class, $application->id,
                ['tournament_id' => $tournament->id, 'ids' => $ids, 'changed_ids' => $changed, 'old' => ['attached' => ! $attach], 'new' => ['attached' => $attach]]);
        });
    }

    private function notify(int $recipient, string $message): void
    {
        $alert = UserAlert::create(['message' => '<p>'.e($message).'</p><p><a href="/panel/tournaments/applications">'.e(__('tour.applications')).'</a></p>']);
        $alert->users()->attach($recipient);
    }
}
