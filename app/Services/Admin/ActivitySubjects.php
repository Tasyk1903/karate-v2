<?php

namespace App\Services\Admin;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ActivitySubjects
{
    private const TABLES = [
        'Tournament' => ['tournaments', 'name'], 'Championship' => ['championships', 'name'],
        'Region' => ['regions', 'name'], 'Scale' => ['scales', 'name'],
        'TemplateStudentList' => ['template_student_lists', 'name'], 'Examination' => ['examinations', 'name'],
        'EducationKataCategory' => ['education_kata_categories', 'name'], 'EducationKataVideo' => ['education_kata_videos', 'title'],
        'KataCompetitions' => ['kata_competitions', 'name'], 'KataCompetitionsVideo' => ['kata_competitions_videos', 'title'],
        'EducationKlassCategory' => ['education_klass_categories', 'name'],
    ];

    public function names(Collection $rows): array
    {
        $result = [];
        foreach ($rows->groupBy('subject_type') as $type => $items) {
            $config = self::TABLES[class_basename($type)] ?? null;
            if (! $config) {
                continue;
            }
            [$table, $field] = $config;
            foreach (DB::table($table)->whereIn('id', $items->pluck('subject_id'))->pluck($field, 'id') as $id => $name) {
                $result[$type.':'.$id] = $name;
            }
        }

        return $result;
    }
}
