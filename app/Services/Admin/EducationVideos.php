<?php

namespace App\Services\Admin;

use App\Jobs\DeleteUnusedEducationMedia;
use App\Models\User;
use App\Services\Education\EducationCatalog;
use App\Services\Team\TeamActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class EducationVideos
{
    public function __construct(private EducationCatalog $catalog) {}

    public function save(User $actor, string $section, int $category, ?int $id, string $title, ?UploadedFile $video, ?UploadedFile $poster): void
    {
        abort_unless($this->catalog->categories($section)->where('id', $category)->exists(), 404);
        $query = $this->catalog->videos($section)->where($this->catalog->categoryKey($section), $category);
        if ($id) {
            abort_unless((clone $query)->where('id', $id)->exists(), 404);
        }
        $new = [];
        try {
            foreach (['path' => $video, 'poster_path' => $poster] as $field => $file) {
                if ($file) {
                    $new[$field] = $file->store('videos/education', 'protected');
                    if (! $new[$field]) {
                        throw new \RuntimeException('Unable to store education media.');
                    }
                }
            }
            DB::transaction(function () use ($actor, $section, $category, $id, $title, $query, $new): void {
                abort_unless($this->catalog->categories($section)->where('id', $category)->lockForUpdate()->first(), 404);
                $old = $id ? (clone $query)->where('id', $id)->lockForUpdate()->first() : null;
                abort_if($id && ! $old, 404);
                $values = ['title' => $title, 'updated_at' => now()] + $new;
                if (isset($new['path']) && ! isset($new['poster_path'])) {
                    $values['poster_path'] = null;
                }
                if ($id) {
                    (clone $query)->where('id', $id)->update($values);
                } else {
                    $id = $this->catalog->videos($section)->insertGetId($values + [$this->catalog->categoryKey($section) => $category, 'created_at' => now()]);
                }
                TeamActivity::record($actor, 'admin.education.video.saved', $section === 'competition' ? 'App\\Models\\KataCompetitionsVideo' : 'App\\Models\\EducationKataVideo', $id,
                    ['old' => (array) $old, 'new' => $values, 'section' => $section, 'category_id' => $category], 'admin');
                foreach (['path', 'poster_path'] as $field) {
                    if ($old && ! empty($old->$field) && array_key_exists($field, $values) && $values[$field] !== $old->$field) {
                        $this->cleanup($old->$field);
                    }
                }
            }, 3);
        } catch (\Throwable $error) {
            foreach ($new as $path) {
                if ($path) {
                    $this->cleanup($path);
                }
            }
            throw $error;
        }
    }

    public function delete(User $actor, string $section, int $category, array $ids): void
    {
        abort_unless($this->catalog->categories($section)->where('id', $category)->exists(), 404);
        DB::transaction(function () use ($actor, $section, $category, $ids): void {
            abort_unless($this->catalog->categories($section)->where('id', $category)->lockForUpdate()->first(), 404);
            foreach (collect($ids)->sort()->values() as $id) {
                $query = $this->catalog->videos($section)->where($this->catalog->categoryKey($section), $category)->where('id', $id);
                $row = (clone $query)->lockForUpdate()->first();
                abort_unless($row, 404);
                $query->delete();
                TeamActivity::record($actor, 'admin.education.video.deleted', $section === 'competition' ? 'App\\Models\\KataCompetitionsVideo' : 'App\\Models\\EducationKataVideo', $id,
                    ['old' => (array) $row, 'new' => null, 'section' => $section, 'category_id' => $category], 'admin');
                foreach ([$row->path, $row->poster_path] as $path) {
                    if ($path) {
                        $this->cleanup($path);
                    }
                }
            }
        }, 3);
    }

    private function cleanup(string $path): void
    {
        DB::afterCommit(function () use ($path): void {
            $job = new DeleteUnusedEducationMedia($path);
            try {
                $job->handle();
            } catch (\Throwable $error) {
                report($error);
                Queue::connection('protected_media_cleanup')->push($job);
            }
        });
    }
}
