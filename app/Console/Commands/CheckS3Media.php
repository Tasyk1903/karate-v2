<?php

namespace App\Console\Commands;

use App\Services\MediaStorage;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class CheckS3Media extends Command
{
    protected $signature = 'media:s3-check';

    protected $description = 'Check private S3 upload, streamed ranges and anonymous denial using a disposable probe';

    public function handle(MediaStorage $media): int
    {
        $disk = Storage::disk('s3');
        $path = '_checks/'.Str::uuid().'.txt';
        $content = 'KarateRating S3 private range verification';
        $request = app('request');
        $status = self::FAILURE;
        try {
            $disk->put($path, $content, ['visibility' => 'private']);
            if ($disk->get($path) !== $content) {
                throw new \RuntimeException('Read-back mismatch.');
            }
            app()->instance('request', Request::create('/probe', 'GET', server: ['HTTP_RANGE' => 'bytes=2-8']));
            $response = $media->response('s3', $path);
            ob_start();
            try {
                $response->sendContent();
                $bytes = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            if ($response->getStatusCode() !== 206 || $bytes !== substr($content, 2, 7)) {
                throw new \RuntimeException('Streamed range mismatch.');
            }
            $url = $disk->getClient()->getObjectUrl($disk->getConfig()['bucket'], $disk->path($path));
            $anonymousStatus = Http::withoutRedirecting()->connectTimeout(10)->timeout(20)->get($url)->status();
            if (! in_array($anonymousStatus, [403, 404], true)) {
                throw new \RuntimeException('Anonymous access was not denied.');
            }
            $this->line(json_encode(['upload' => true, 'read_back' => true, 'range_206' => true, 'anonymous_status' => $anonymousStatus]));

            $status = self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('S3 check failed ('.get_class($error).'). Check configuration and bucket access.');

        } finally {
            app()->instance('request', $request);
            try {
                $disk->delete($path);
            } catch (\Throwable $error) {
                $this->error('Probe cleanup failed ('.get_class($error).'): '.$path);
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
