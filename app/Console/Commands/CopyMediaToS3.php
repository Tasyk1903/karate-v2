<?php

namespace App\Console\Commands;

use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class CopyMediaToS3 extends Command
{
    protected $signature = 'media:copy-to-s3 {disk : public or protected} {--source= : Local source directory} {--apply : Copy and verify contents; never remove originals or overwrite differing objects}';

    protected $description = 'Audit or copy local durable media to private S3 with SHA-256 verification';

    public function handle(MediaStorage $media): int
    {
        $name = $this->argument('disk');
        if (! in_array($name, ['public', 'protected'], true)) {
            $this->error('Disk must be public or protected.');

            return self::FAILURE;
        }
        $root = realpath($this->option('source') ?: storage_path('app/'.$name));
        if (! $root || ! is_dir($root)) {
            $this->error('Source directory is missing.');

            return self::FAILURE;
        }
        $source = Storage::build(['driver' => 'local', 'root' => $root, 'throw' => true]);
        $config = config('filesystems.disks.s3');
        $target = Storage::build(array_merge($config, ['root' => trim($config['root'], '/').'/'.$name]));
        Storage::set('s3-migration', $target);
        $counts = ['files' => 0, 'bytes' => 0, 'copied' => 0, 'verified' => 0, 'conflicts' => 0, 'errors' => 0];
        foreach ($source->getDriver()->listContents('', true) as $entry) {
            $path = $entry->path();
            if (! $entry->isFile() || in_array(basename($path), ['.gitignore', '.DS_Store'], true) || str_starts_with($path, 'livewire-tmp/')) {
                continue;
            }
            $counts['files']++;
            $counts['bytes'] += $entry->fileSize();
            if (! $this->option('apply')) {
                continue;
            }
            try {
                $hash = hash_file('sha256', $source->path($path));
                if (! $target->fileExists($path)) {
                    $stream = $source->readStream($path);
                    try {
                        $target->put($path, $stream, ['visibility' => 'private']);
                    } finally {
                        fclose($stream);
                    }
                    $counts['copied']++;
                }
                if (! hash_equals($hash, $media->checksum('s3-migration', $path))) {
                    $counts['conflicts']++;
                    $this->error('Content mismatch; retained both originals: '.$path);
                } else {
                    $counts['verified']++;
                }
            } catch (\Throwable $error) {
                $counts['errors']++;
                $this->error('Copy/verification failed: '.$path.' ('.get_class($error).')');
            }
        }
        $this->line(json_encode($counts));
        if ($this->option('apply')) {
            DB::table('activity_log')->insert(['log_name' => 'storage', 'event' => 'media.copied_to_s3',
                'description' => 'media.copied_to_s3', 'properties' => json_encode(['source' => $root, 'disk' => $name, 'counts' => $counts]),
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $counts['errors'] || $counts['conflicts'] ? self::FAILURE : self::SUCCESS;
    }
}
