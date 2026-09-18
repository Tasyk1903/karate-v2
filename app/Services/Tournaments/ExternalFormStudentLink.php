<?php

namespace App\Services\Tournaments;

use App\Models\ExternalForm;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ExternalFormStudentLink
{
    public function candidates(ExternalForm $form): Builder
    {
        $org = $form->championship->organization_id;

        return User::query()->role('Student')
            ->whereIn('id', DB::table('student_tournaments')->join('tournaments', 'tournaments.id', '=', 'student_tournaments.tournament_id')
                ->where('tournaments.championship_id', $form->championship_id)->select('student_id'))
            ->where(function (Builder $query) use ($org): void {
                $query->where(function (Builder $owned) use ($org): void {
                    $owned->where('organization_id', $org)->where(fn ($coach) => $coach->whereNull('coach_id')->orWhereHas('coach', fn ($coach) => $coach->role('Coach')->where('organization_id', $org)));
                })->orWhere(function (Builder $legacy) use ($org): void {
                    // A legacy synthetic account must participate exclusively in this organization's tournaments.
                    $legacy->whereNull('organization_id')->whereNull('coach_id')->whereNull('email_verified_at')
                        ->whereRaw('email = '.(DB::getDriverName() === 'sqlite' ? "'karaterating' || id || '@karaterating.ru'" : "CONCAT('karaterating', id, '@karaterating.ru')"))
                        ->whereNotIn('id', DB::table('student_tournaments')->join('tournaments', 'tournaments.id', '=', 'student_tournaments.tournament_id')
                            ->where(fn ($t) => $t->where('tournaments.organization_id', '!=', $org)->orWhereNull('tournaments.organization_id'))->select('student_id'));
                });
            });
    }

    public function link(ExternalForm $form, User $actor, string $rowId, int $studentId, string $revision): void
    {
        DB::transaction(function () use ($form, $actor, $rowId, $studentId, $revision): void {
            $form = ExternalForm::query()->lockForUpdate()->findOrFail($form->id);
            $rows = app(ExternalFormRows::class);
            $rows->authorize($form, $actor);
            abort_unless(hash_equals($rows->revision($form), $revision), 409, __('forms.stale'));
            $row = collect($rows->rows($form))->firstWhere('row_id', $rowId);
            abort_unless($row, 404);
            $rows->validateRows([$row]);
            $student = $this->candidates($form)->lockForUpdate()->find($studentId);
            abort_unless($student && array_diff($student->projectRoleNames(), ['Student']) === [], 403);
            $where = ['external_form_id' => $form->id, 'row_id' => $rowId];
            $old = DB::table('external_form_students')->where($where)->first();
            abort_if($old && (int) $old->user_id !== $studentId, 409);
            if ($old) {
                return;
            }
            if (! $student->organization_id) {
                $student->forceFill(['organization_id' => $form->championship->organization_id, 'is_external' => true])->save();
                TeamActivity::record($actor, 'external_form.student.adopted', User::class, $student->id,
                    ['form_id' => $form->id, 'old' => ['organization_id' => null], 'new' => ['organization_id' => $student->organization_id, 'is_external' => true]]);
            }
            DB::table('external_form_students')->insert($where + ['user_id' => $studentId, 'created_at' => now(), 'updated_at' => now()]);
            TeamActivity::record($actor, 'external_form.student.link.confirmed', User::class, $studentId,
                ['form_id' => $form->id, 'row_id' => $rowId, 'old' => null, 'new' => ['user_id' => $studentId]]);
            app(ExternalParticipantIdentity::class)->resolve($form, $row, $actor, false);
        });
    }
}
