<?php

namespace App\Console\Commands;

use App\Services\ProtectedMedia;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class AuditS3Media extends Command
{
    protected $signature = 'media:s3-audit {--missing : Print missing relative paths}';

    protected $description = 'Read-only inventory of database media references against S3 objects';

    public function handle(ProtectedMedia $media): int
    {
        $objects = [];
        foreach (Storage::disk('s3')->getDriver()->listContents('', true) as $entry) {
            if ($entry->isFile()) {
                $objects[$entry->path()] = true;
            }
        }
        $references = ProtectedMedia::REFERENCES;
        $references['users'][] = 'avatar';
        $references += ['championships' => ['banner'],
            'tournaments' => ['regulation_document', 'application_document', 'logo_report'],
            'posts' => ['image'], 'online_kata_applications' => ['video_path'], 'panel_tasks' => ['path']];
        $summary = [];
        $missing = [];
        foreach ($references as $table => $columns) {
            $paths = [];
            DB::table($table)->when($table === 'panel_tasks', fn ($query) => $query->where('status', 'ready')->where('expires_at', '>', now()))
                ->select(['id', ...$columns])->orderBy('id')->chunkById(500, function ($rows) use (&$paths, $columns, $media): void {
                    foreach ($rows as $row) {
                        foreach ($columns as $field) {
                            if ($row->$field) {
                                $paths[$media->storedPath($row->$field)] = true;
                            }
                        }
                    }
                });
            $counts = ['references' => count($paths), 'available' => 0, 'missing' => 0, 'external_or_invalid' => 0];
            foreach (array_keys($paths) as $path) {
                if (! $media->validPath($path)) {
                    $counts['external_or_invalid']++;
                } elseif (isset($objects['public/'.$path]) || isset($objects['protected/'.$path])) {
                    $counts['available']++;
                } else {
                    $counts['missing']++;
                    $missing[$path] = true;
                    if ($this->option('missing')) {
                        $this->line($table.': '.$path);
                    }
                }
            }
            $summary[$table] = $counts;
        }
        $this->line(json_encode(['objects' => count($objects), 'unique_missing_paths' => count($missing), 'tables' => $summary], JSON_PRETTY_PRINT));

        return array_sum(array_column($summary, 'missing')) || array_sum(array_column($summary, 'external_or_invalid')) ? self::FAILURE : self::SUCCESS;
    }
}
