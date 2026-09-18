<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

final class ProtectedMedia
{
    public const DOCUMENTS = ['passport', 'brand', 'insurance', 'iko_card', 'certificate'];

    public const VIDEOS = ['online_kata_video_path', 'online_kata_first_round_video_path', 'online_kata_second_round_video_path'];

    public const REFERENCES = [
        'users' => self::DOCUMENTS, 'student_tournaments' => self::VIDEOS,
        'education_klass_videos' => ['path'],
        'education_kata_videos' => ['path', 'poster_path'],
        'kata_competitions_videos' => ['path', 'poster_path'],
    ];

    public const DIRECTORIES = ['passport', 'passports', 'brand', 'brands', 'insurance', 'iko_card', 'certificate', 'online-kata-videos', 'video', 'videos'];

    public function validPath(string $path): bool
    {
        return $path !== '' && ! str_starts_with($path, '/')
            && ! str_contains($path, '//')
            && ! preg_match('~[\\\\\x00-\x1f]|(^|/)\.\.?(/|$)|://~', $path);
    }

    public function isProtected(string $path): bool
    {
        if (! $this->validPath($path) || Storage::disk('protected')->fileExists($path)) {
            return true;
        }

        foreach (self::DIRECTORIES as $directory) {
            if ($path === $directory || str_starts_with($path, $directory.'/')) {
                return true;
            }
        }

        // Legacy records can reference files outside the conventional directories.
        foreach (self::REFERENCES as $table => $columns) {
            if (DB::table($table)->where(function ($query) use ($columns, $path): void {
                foreach ($columns as $column) {
                    $query->orWhereIn($column, [$path, '/'.$path, '/storage/'.$path]);
                }
            })->exists()) {
                return true;
            }
        }

        return false;
    }

    public function canViewDocuments(User $viewer, User $owner): bool
    {
        if ($viewer->hasProjectRole('Admin') || $viewer->id === $owner->id) {
            return true;
        }
        if ($viewer->hasProjectRole('Coach')) {
            return $owner->hasProjectRole('Student') && (int) $owner->coach_id === (int) $viewer->id;
        }
        if (! $viewer->hasAnyProjectRole(['Organization', 'Secretary'])) {
            return false;
        }
        $organizationId = $viewer->hasProjectRole('Organization') ? $viewer->id : $viewer->organization_id;

        return $organizationId && ($owner->coach_id
            ? (int) $owner->coach?->organization_id === (int) $organizationId
            : (int) $owner->organization_id === (int) $organizationId);
    }

    public function documentUrl(User $owner, string $column, bool $mobile = false): ?string
    {
        if (! $owner->{$column}) {
            return null;
        }

        return url(($mobile ? '/api/mobile' : '/api/panel')."/files/users/{$owner->id}/{$column}");
    }

    public function localPath(string $disk, string $path): ?string
    {
        return app(MediaStorage::class)->localPath($disk, $path);
    }

    public function storedPath(string $path): string
    {
        return str_starts_with($path, '/storage/') ? substr($path, 9) : ltrim($path, '/');
    }

    public function response(?string $path, ?string $downloadName = null): Response
    {
        $path = $path ? $this->storedPath($path) : null;
        abort_unless($path && $this->validPath($path), 404);
        $storage = app(MediaStorage::class);
        $disk = $storage->exists('protected', $path) ? 'protected' : 'public';

        return $storage->response($disk, $path, $downloadName);
    }

    public function migrateFile(string $path): string
    {
        $path = $this->storedPath($path);
        $storage = app(MediaStorage::class);
        if (! $storage->exists('public', $path)) {
            return $storage->exists('protected', $path) ? 'private' : 'missing';
        }
        $disk = Storage::disk('protected');
        if (! $disk->fileExists($path)) {
            $stream = Storage::disk('public')->readStream($path);
            if (! is_resource($stream)) {
                throw new \RuntimeException('Cannot read source media.');
            }
            try {
                $disk->put($path, $stream);
            } finally {
                fclose($stream);
            }
        }
        if (! $storage->exists('protected', $path) || ! hash_equals($storage->checksum('public', $path), $storage->checksum('protected', $path))) {
            throw new \RuntimeException('Protected media checksum mismatch; public original retained.');
        }
        if (! Storage::disk('public')->delete($path)) {
            throw new \RuntimeException('Unable to remove verified public copy.');
        }

        return 'moved';
    }
}
