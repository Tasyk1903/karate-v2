<?php

namespace App\Console\Commands;

use App\Services\MediaStorage;
use App\Services\ProtectedMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class PrivatizeMedia extends Command
{
    protected $signature = 'media:privatize {--apply : Copy, verify SHA-256, then remove public copies}';

    protected $description = 'Audit or privatize user documents and online kata videos, including legacy paths';

    public function handle(ProtectedMedia $media): int
    {
        $paths = [];
        foreach (ProtectedMedia::REFERENCES as $table => $columns) {
            DB::table($table)->select(['id', ...$columns])->orderBy('id')->chunkById(500, function ($rows) use (&$paths, $columns): void {
                foreach ($rows as $row) {
                    foreach ($columns as $column) {
                        if ($row->{$column}) {
                            $paths[$row->{$column}] = true;
                        }
                    }
                }
            });
        }
        foreach (ProtectedMedia::DIRECTORIES as $directory) {
            foreach (Storage::disk('public')->allFiles($directory) as $path) {
                $paths[$path] = true;
            }
        }
        $counts = ['public' => 0, 'private' => 0, 'moved' => 0, 'missing' => 0, 'invalid' => 0];
        foreach (array_keys($paths) as $path) {
            $path = $media->storedPath($path);
            $status = ! $media->validPath($path) ? 'invalid' : ($this->option('apply')
                ? $media->migrateFile($path)
                : (app(MediaStorage::class)->exists('public', $path) ? 'public' : (app(MediaStorage::class)->exists('protected', $path) ? 'private' : 'missing')));
            $counts[$status]++;
        }
        // Static storage links bypass Laravel under the PHP development server as well.
        if ($this->option('apply') && is_link(public_path('storage'))) {
            if (! unlink(public_path('storage'))) {
                $this->error('Unable to remove public/storage symlink.');

                return self::FAILURE;
            }
        }
        $this->line(json_encode($counts));
        if ($this->option('apply')) {
            DB::table('activity_log')->insert([
                'log_name' => 'security', 'event' => 'media.privatized', 'description' => 'media.privatized',
                'properties' => json_encode($counts), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $counts['invalid'] ? self::FAILURE : self::SUCCESS;
    }
}
