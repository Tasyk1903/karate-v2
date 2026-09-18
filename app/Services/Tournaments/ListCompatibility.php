<?php

namespace App\Services\Tournaments;

use App\Models\TemplateStudentList;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

final class ListCompatibility
{
    public function constrain(Builder $query, Tournament $tournament, ?bool $group = null): Builder
    {
        if ((int) $tournament->tournament_type === Tournament::KUMITE) {
            return $query->where('list_type', TemplateStudentList::KUMITE)->where(fn (Builder $q) => $q->whereNull('kata_type')->orWhere('kata_type', ''))->when($group === true, fn (Builder $q) => $q->whereRaw('1 = 0'));
        }
        $types = (int) $tournament->tournament_type_kata === Tournament::POINT_SYSTEM
            ? ($group === null ? ['personal', 'group'] : [$group ? 'group' : 'personal']) : ['flag'];

        return $query->where('list_type', TemplateStudentList::KATA)->whereIn('kata_type', $types)->when($group === true && (int) $tournament->tournament_type_kata !== Tournament::POINT_SYSTEM, fn (Builder $q) => $q->whereRaw('1 = 0'));
    }

    public function matches(?TemplateStudentList $list, Tournament $tournament, ?bool $group = null): bool
    {
        if (! $list) {
            return false;
        }
        if ((int) $tournament->tournament_type === Tournament::KUMITE) {
            return ! $group && $list->list_type === 'kumite' && blank($list->kata_type);
        }
        if ($list->list_type !== 'kata') {
            return false;
        }
        if ((int) $tournament->tournament_type_kata !== Tournament::POINT_SYSTEM) {
            return ! $group && $list->kata_type === 'flag';
        }

        return in_array($list->kata_type, $group === null ? ['personal', 'group'] : [$group ? 'group' : 'personal'], true);
    }

    public function assert(?TemplateStudentList $list, Tournament $tournament, ?bool $group = null): void
    {
        if (! $this->matches($list, $tournament, $group)) {
            throw ValidationException::withMessages(['list' => __('lists.incompatible')]);
        }
    }
}
