<?php

namespace App\Services\Tournaments;

use App\Models\ExternalForm;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ExternalFormImportService
{
    public function __construct(
        private ExternalFormRows $rows,
        private ExternalParticipantIdentity $identities,
        private ExternalFormApplications $applications,
    ) {}

    public function categoryOptions(ExternalForm $form): array
    {
        return $this->rows->categoryOptions($form);
    }

    public function normalizeRows(array $rows, array $options): array
    {
        return $this->rows->normalizeRows($rows, $options);
    }

    public function saveRows(ExternalForm $form, array $rows): array
    {
        return $this->rows->saveRows($form, $rows);
    }

    public function import(ExternalForm $form, User $actor, bool $syncProfiles = false, ?string $revision = null): array
    {
        $this->rows->authorize($form, $actor);

        return DB::transaction(function () use ($form, $actor, $syncProfiles, $revision): array {
            $form = ExternalForm::query()->lockForUpdate()->findOrFail($form->id);
            $this->rows->authorize($form, $actor);
            abort_unless($form->status === 'closed', 422, __('forms.must_close'));
            abort_if($revision !== null && ! hash_equals($revision, $this->rows->revision($form)), 409, __('forms.stale'));
            $form->load('championship.tournaments.lists.templateStudentList');
            $participants = $this->rows->rows($form);
            $report = [];
            $desired = [];
            $created = 0;
            $invalid = false;
            $profiles = [];
            foreach ($participants as $index => $row) {
                $raw = $form->data['participants'][$index] ?? [];
                if (is_array($raw) && is_string($raw['category'] ?? null)) {
                    $raw['category'] = array_filter([$raw['category']]);
                }
                $context = ['row_id' => $row['row_id'], 'row_number' => $index + 1, 'name' => trim($row['last_name'].' '.$row['first_name'])];
                try {
                    $this->rows->validateRows([$raw]);
                    $resolved = DB::transaction(function () use ($form, $row, $actor, $syncProfiles, &$profiles): array {
                        $this->rows->validateRows([$row]);
                        if (blank($row['first_name']) || blank($row['last_name'])) {
                            throw ValidationException::withMessages(['row' => 'invalid_row']);
                        }
                        $key = $this->identities->identityKey($row) ?? $row['row_id'];
                        $fields = $this->identities->profileFields($row);
                        if (isset($profiles[$key]) && $profiles[$key] !== $fields) {
                            throw ValidationException::withMessages(['row' => 'conflicting_rows']);
                        }
                        $resolved = $this->identities->resolve($form, $row, $actor, $syncProfiles);
                        $profiles[$key] = $fields;

                        return $resolved;
                    });
                } catch (ValidationException $error) {
                    $participants[$index] = (is_array($raw) ? $raw : []) + ['row_id' => $row['row_id']];
                    $invalid = true;
                    $report[] = $context + ['status' => $error->errors()['row'][0] ?? $error->errors()['profile'][0] ?? 'invalid_row', 'fields' => array_keys($error->errors())];

                    continue;
                }
                $created += (int) $resolved['created'];
                $user = $resolved['user'];
                if ($resolved['profile_pending']) {
                    $report[] = $context + ['student_id' => $user->id, 'status' => 'profile_pending'];
                }
                if (! $row['category']) {
                    $report[] = $context + ['student_id' => $user->id, 'status' => 'no_category'];
                }
                foreach ($row['category'] as $category) {
                    $tournament = $this->tournament($form, $category, (string) $user->rang);
                    if (! $tournament) {
                        $invalid = true;
                        $report[] = $context + ['student_id' => $user->id, 'category' => $category, 'status' => 'no_tournament'];

                        continue;
                    }
                    if ($category === 'kata_group' && ! $row['kata_group']) {
                        $invalid = true;
                        $report[] = $context + ['student_id' => $user->id, 'category' => $category, 'status' => 'invalid_group'];

                        continue;
                    }
                    $desired[] = ['row' => $row, 'user' => $user, 'category' => $category, 'tournament' => $tournament];
                }
            }
            array_push($report, ...$this->applications->synchronize($form, $actor, $desired, ! $invalid));
            $data = $form->data ?? [];
            $data['participants'] = $participants;
            $form->update(['data' => $data]);
            $counts = collect($report)->countBy('status');

            return ['created_users' => $created, 'attached' => $counts->get('attached', 0), 'removed' => $counts->get('removed', 0),
                'unchanged' => $counts->get('unchanged', 0), 'issues' => collect($report)->whereNotIn('status', ['attached', 'removed', 'unchanged'])->count(),
                'entries' => $report];
        });
    }

    private function tournament(ExternalForm $form, string $category, string $rank): ?Tournament
    {
        $number = app(StudentTournamentListAssignmentService::class)->normalizeRank($rank);

        return $form->championship->tournaments->sortBy([['date', 'asc'], ['id', 'asc']])->first(function (Tournament $tournament) use ($category, $number): bool {
            return match ($category) {
                'kumite' => (int) $tournament->tournament_type === Tournament::KUMITE
                    && (in_array($number, [10, 9], true) ? $tournament->KY_up_to_8 && ! $tournament->KY_from_8 : (bool) $tournament->KY_from_8),
                'kata_flag' => (int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::FLAG_SYSTEM,
                'kata_point', 'kata_group' => (int) $tournament->tournament_type === Tournament::KATA && (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM,
                default => false,
            };
        });
    }
}
