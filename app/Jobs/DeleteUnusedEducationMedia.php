<?php

namespace App\Jobs;

use App\Services\ProtectedMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteUnusedEducationMedia implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $path) {}

    public function handle(): void
    {
        $media = app(ProtectedMedia::class);
        if (! $media->validPath($this->path)) {
            return;
        }
        foreach (ProtectedMedia::REFERENCES as $table => $columns) {
            if (DB::table($table)->where(function ($q) use ($columns): void {
                foreach ($columns as $column) {
                    $q->orWhereIn($column, [$this->path, '/'.$this->path, '/storage/'.$this->path]);
                }
            })->exists()) {
                return;
            }
        }
        foreach (['protected', 'public'] as $disk) {
            Storage::disk($disk)->delete($this->path);
        }
    }
}
