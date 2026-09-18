<?php

namespace App\Services\Tournaments;

use App\Models\ExternalForm;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

class ExternalFormRows
{
    public const CATEGORY_LABELS = [
        'kumite' => 'Кумитэ',
        'kata_flag' => 'Ката (флажковая система)',
        'kata_point' => 'Ката (бальная система)',
        'kata_group' => 'Ката (групповые)',
    ];

    public function categoryOptions(ExternalForm $form): array
    {
        $options = [];
        $form->loadMissing('championship.tournaments');

        foreach ($form->championship?->tournaments ?? [] as $tournament) {
            $type = (int) $tournament->tournament_type;
            $kata = (int) ($tournament->tournament_type_kata ?? 0);

            if ($type === Tournament::KUMITE) {
                $options['kumite'] = self::CATEGORY_LABELS['kumite'];
            } elseif ($type === Tournament::KATA && $kata === Tournament::FLAG_SYSTEM) {
                $options['kata_flag'] = self::CATEGORY_LABELS['kata_flag'];
            } elseif ($type === Tournament::KATA && $kata === Tournament::POINT_SYSTEM) {
                $options['kata_point'] = self::CATEGORY_LABELS['kata_point'];
                $options['kata_group'] = self::CATEGORY_LABELS['kata_group'];
            }
        }

        return $options;
    }

    public function normalizeRows(array $rows, array $categoryOptions): array
    {
        $clean = [];

        foreach ($rows as $row) {
            $row = array_map(fn ($value) => is_string($value) ? trim($value) : $value, (array) $row);
            foreach ($row as $key => $value) {
                if ($key !== 'category' && ! is_scalar($value) && $value !== null) {
                    $row[$key] = null;
                }
            }
            $birthday = $this->normalizeBirthday($row['birthday'] ?? null);
            $categories = $this->normalizeCategories($row['category'] ?? [], $categoryOptions);
            // Old forms encoded group kata as kata_point plus a group number.
            if (! empty($row['kata_group']) && in_array('kata_point', $categories, true) && ! in_array('kata_group', $categories, true)) {
                $categories = array_values(array_diff($categories, ['kata_point']));
                $categories[] = 'kata_group';
            }

            $clean[] = [
                'row_id' => is_string($row['row_id'] ?? null) && Str::isUuid($row['row_id']) ? $row['row_id'] : null,
                'last_name' => $row['last_name'] ?? '',
                'first_name' => $row['first_name'] ?? '',
                'gender' => $this->normalizeGender($row['gender'] ?? null),
                'birthday' => $birthday ?? ($row['birthday'] ?? null),
                'rank' => $this->normalizeNullableString($row['rank'] ?? null),
                'weight' => $this->normalizeNumber($row['weight'] ?? null),
                'age' => $this->normalizeAge($row['age'] ?? null, $birthday),
                'category' => $categories,
                'kata_group' => $this->normalizeKataGroup($row['kata_group'] ?? null, $categories),
                'region' => $this->normalizeNullableString($row['region'] ?? null),
                'city' => $this->normalizeNullableString($row['city'] ?? null),
                'club' => $this->normalizeNullableString($row['club'] ?? null),
                'coach_last_name' => $this->normalizeNullableString($row['coach_last_name'] ?? null),
                'coach_first_name' => $this->normalizeNullableString($row['coach_first_name'] ?? null),
                'best_results' => $this->normalizeNullableString($row['best_results'] ?? null),
                'razriad' => $this->normalizeNullableString($row['razriad'] ?? null),
            ];
        }

        return $clean;
    }

    public function revision(ExternalForm $form): string
    {
        return hash('sha256', json_encode($form->data ?? [], JSON_THROW_ON_ERROR));
    }

    public function rows(ExternalForm $form): array
    {
        $rows = $this->normalizeRows($form->data['participants'] ?? [], $this->categoryOptions($form));
        foreach ($rows as $index => &$row) {
            $row['row_id'] ??= (string) Uuid::uuid5(Uuid::NAMESPACE_URL, 'external-form:'.$form->id.':'.$index);
        }

        return $rows;
    }

    public function authorize(ExternalForm $form, User $actor): void
    {
        $organization = $actor->hasProjectRole('Organization') ? $actor->id : $actor->organization_id;
        abort_unless($actor->hasAnyProjectRole(['Organization', 'Secretary']) && $organization
            && (int) $form->championship?->organization_id === (int) $organization, 403);
    }

    public function validateRows(array $rows): void
    {
        $rules = ['participants' => ['array', 'max:2000'], 'participants.*' => ['array']];
        foreach (['first_name', 'last_name', 'gender', 'birthday', 'rank', 'region', 'city', 'club', 'coach_first_name', 'coach_last_name', 'razriad', 'row_id'] as $field) {
            $rules['participants.*.'.$field] = ['nullable', 'string', 'max:255'];
        }
        $rules['participants.*.best_results'] = ['nullable', 'string', 'max:4000'];
        $rules['participants.*.age'] = ['nullable', 'integer', 'between:0,120'];
        $rules['participants.*.weight'] = ['nullable', 'numeric', 'between:0,400'];
        $rules['participants.*.kata_group'] = ['nullable', 'integer', 'between:1,15'];
        $rules['participants.*.category'] = ['nullable', 'array', 'max:4'];
        $rules['participants.*.category.*'] = ['string', Rule::in(array_merge(array_keys(self::CATEGORY_LABELS), array_values(self::CATEGORY_LABELS)))];
        Validator::make(['participants' => $rows], $rules)->validate();
        foreach ($rows as $index => $row) {
            if (! empty($row['birthday']) && ! $this->normalizeBirthday($row['birthday'])) {
                throw ValidationException::withMessages(['participants.'.$index.'.birthday' => __('forms.invalid_birthday')]);
            }
        }
    }

    public function saveRows(ExternalForm $form, array $rows, ?User $actor = null, ?string $revision = null): array
    {
        return DB::transaction(function () use ($form, $rows, $actor, $revision): array {
            $locked = ExternalForm::query()->lockForUpdate()->findOrFail($form->id);
            if ($actor) {
                $this->authorize($locked, $actor);
            } else {
                abort_if($locked->status === 'closed', 403);
            }
            abort_if($revision !== null && ! hash_equals($this->revision($locked), $revision), 409, __('forms.stale'));
            $this->validateRows($rows);
            $before = $this->rows($locked);
            $knownIds = array_column($before, 'row_id');
            $clean = $this->normalizeRows($rows, $this->categoryOptions($locked));
            $seen = [];
            foreach ($clean as &$row) {
                $id = $row['row_id'];
                $row['row_id'] = $id && in_array($id, $knownIds, true) && ! isset($seen[$id])
                    ? $id : (string) Str::uuid();
                $seen[$row['row_id']] = true;
            }
            unset($row);
            $data = $locked->data ?? [];
            $data['participants'] = $clean;
            $locked->update(['data' => $data]);
            TeamActivity::record($actor, 'external_form.rows.saved', ExternalForm::class, $locked->id,
                ['championship_id' => $locked->championship_id, 'source' => $actor ? 'panel' : 'public_form', 'old' => $before, 'new' => $clean]);

            return $clean;
        });
    }

    private function normalizeBirthday($birthday): ?string
    {
        if (! $birthday) {
            return null;
        }

        $birthday = (string) $birthday;
        $format = preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthday) ? 'Y-m-d' : 'd.m.Y';
        try {
            $date = Carbon::createFromFormat('!'.$format, $birthday);

            return $date && $date->format($format) === $birthday && ! $date->isFuture() ? $date->format('d.m.Y') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeGender($gender): ?string
    {
        $gender = mb_strtolower((string) $gender);

        return match (true) {
            in_array($gender, ['m', 'м', 'male', 'муж'], true) => 'm',
            in_array($gender, ['f', 'ж', 'female', 'жен'], true) => 'f',
            default => null,
        };
    }

    private function normalizeCategories($value, array $categoryOptions): array
    {
        $values = is_array($value) ? $value : array_filter([$value]);

        return collect($values)
            ->map(fn ($item) => $this->normalizeCategory($item, $categoryOptions))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizeCategory($value, array $categoryOptions): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }
        $categoryOptions = self::CATEGORY_LABELS + $categoryOptions;
        if (isset($categoryOptions[$value])) {
            return $value;
        }

        $flipped = array_change_key_case(array_flip($categoryOptions), CASE_LOWER);

        return $flipped[mb_strtolower(trim($value))] ?? $value;
    }

    private function normalizeKataGroup($value, array $categories): ?int
    {
        if (! in_array('kata_group', $categories, true) || $value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        $number = (int) $value;

        return $number >= 1 && $number <= 15 ? $number : null;
    }

    private function normalizeNumber($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (float) str_replace(',', '.', (string) $value);
    }

    private function normalizeAge($value, ?string $birthday): ?int
    {
        if ($value !== null && $value !== '') {
            return (int) $value;
        }

        return $birthday ? Carbon::createFromFormat('d.m.Y', $birthday)->age : null;
    }

    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
