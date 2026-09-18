<?php

namespace App\Services\Education;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class EducationCatalog
{
    public function categories(string $section): Builder
    {
        abort_unless(in_array($section, array_diff(EducationAccess::SECTIONS, ['works']), true), 404);

        return $section === 'competition'
            ? DB::table('kata_competitions')
            : DB::table('education_kata_categories')->where('type', $section);
    }

    public function videos(string $section): Builder
    {
        return $section === 'competition'
            ? DB::table('kata_competitions_videos')
            : DB::table('education_kata_videos');
    }

    public function categoryKey(string $section): string
    {
        return $section === 'competition' ? 'kata_competition_id' : 'education_kata_category_id';
    }

    public function video(string $section, int $id): object
    {
        $row = $this->videos($section)->where('id', $id)->first();
        abort_unless($row && $this->categories($section)->where('id', $row->{$this->categoryKey($section)})->exists(), 404);

        return $row;
    }

    public function formatVideo(string $section, object $row, bool $mobile = true): array
    {
        $base = url(($mobile ? '/api/mobile/files/education/catalog/' : '/api/panel/student/education/files/')."{$section}/{$row->id}");

        return [
            'id' => $row->id, 'title' => $row->title,
            'video_url' => $base.'/video',
            'poster_url' => $row->poster_path ? $base.'/poster' : null,
        ];
    }
}
