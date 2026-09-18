<?php

namespace App\Services\Tournaments;

use App\Models\Region;
use App\Models\Scale;
use App\Models\Tournament;
use App\Models\User;
use Illuminate\Support\Collection;

final class BracketParticipantLabels
{
    private readonly bool $showRegions;

    private readonly Collection $regions;

    public function __construct(Tournament $tournament, Collection $participants)
    {
        $tournament->loadMissing('scale');
        $this->showRegions = in_array($tournament->scale?->slug, [
            Scale::REGION, Scale::FEDERAL_DISTRICT, Scale::ALL_RUSSIAN,
            Scale::RUSSIAN_CHAMPIONSHIP, Scale::INTERNATIONAL,
        ], true);
        $this->regions = $this->showRegions
            ? Region::query()->whereIn('id', $participants->pluck('region_id')->filter()->unique())->pluck('name', 'id')
            : collect();
    }

    public function affiliation(?User $student): string
    {
        if (! $student) {
            return '';
        }

        return $this->showRegions
            ? ($this->regions->get($student->region_id) ?: __('exports.region_not_specified'))
            : (string) $student->coach?->club;
    }

    public function line(?User $student): string
    {
        return trim($this->affiliation($student).' '.($student?->coach_short ?? ''));
    }
}
