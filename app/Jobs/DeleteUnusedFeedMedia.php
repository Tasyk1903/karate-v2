<?php

namespace App\Jobs;

use App\Models\Post;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

final class DeleteUnusedFeedMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public function __construct(public string $path) {}

    public function backoff(): array
    {
        return [10, 60, 300, 900];
    }

    public function handle(): void
    {
        if (! str_starts_with($this->path, 'posts/') || str_contains($this->path, '..') || Post::where('image', $this->path)->exists()) {
            return;
        }
        $disk = Storage::disk('public');
        if ($disk->exists($this->path) && ! $disk->delete($this->path)) {
            throw new \RuntimeException('Could not delete unused feed media.');
        }
    }
}
