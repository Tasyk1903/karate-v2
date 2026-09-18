<?php

namespace App\Jobs;

use App\Services\MediaStorage;
use App\Services\ProtectedMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteUnusedKataVideo implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $path) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(ProtectedMedia $media): void
    {
        $path = $media->storedPath($this->path);
        if (! $media->validPath($path)) {
            return;
        }
        $aliases = [$this->path, $path, '/'.$path, '/storage/'.$path];
        if (DB::table('online_kata_applications')->whereIn('video_path', $aliases)->exists()) {
            return;
        }
        foreach (['student_tournaments' => ProtectedMedia::VIDEOS, 'users' => [...ProtectedMedia::DOCUMENTS, 'avatar']] as $table => $fields) {
            if (DB::table($table)->where(function ($q) use ($fields, $aliases) {
                foreach ($fields as $field) {
                    $q->orWhereIn($field, $aliases);
                }
            })->exists()) {
                return;
            }
        }
        foreach (['protected', 'public'] as $disk) {
            // Legacy public storage may also contain unrelated site assets.
            if ($disk === 'public' && ! str_starts_with($path, 'online-kata-videos/')) {
                continue;
            }
            if (app(MediaStorage::class)->exists($disk, $path) && ! Storage::disk($disk)->delete($path)) {
                throw new \RuntimeException('Unable to remove superseded kata video.');
            }
        }
    }
}
