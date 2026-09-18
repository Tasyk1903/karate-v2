<?php

namespace App\Services;

use Aws\Exception\AwsException;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

final class MediaStorage
{
    public function localPath(string $disk, string $path): ?string
    {
        if (! app(ProtectedMedia::class)->validPath($path) || Storage::disk($disk) instanceof AwsS3V3Adapter) {
            return null;
        }
        $root = realpath(Storage::disk($disk)->path(''));
        $file = realpath(Storage::disk($disk)->path($path));

        return $root && $file && str_starts_with($file, $root.DIRECTORY_SEPARATOR) && is_file($file) ? $file : null;
    }

    public function exists(string $disk, string $path): bool
    {
        if (! app(ProtectedMedia::class)->validPath($path)) {
            return false;
        }

        return Storage::disk($disk) instanceof AwsS3V3Adapter
            ? Storage::disk($disk)->fileExists($path) : $this->localPath($disk, $path) !== null;
    }

    public function response(string $disk, string $path, ?string $downloadName = null): Response
    {
        abort_unless(app(ProtectedMedia::class)->validPath($path), 404);
        $name = $downloadName ?? basename($path);
        $fallback = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name)) ?: 'file';
        $headers = [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'",
            'Content-Disposition' => HeaderUtils::makeDisposition($downloadName ? 'attachment' : 'inline', $name, $fallback),
        ];
        $storage = Storage::disk($disk);
        if (! $storage instanceof AwsS3V3Adapter) {
            $file = $this->localPath($disk, $path);
            abort_unless($file, 404);

            return response()->file($file, $headers)->setPrivate();
        }

        $params = ['Bucket' => $storage->getConfig()['bucket'], 'Key' => $storage->path($path)];
        $head = request()->isMethod('HEAD');
        $range = request()->header('Range');
        // A single byte range keeps video seeking bounded without downloading the full object.
        if (! $head && $range && ! request()->hasHeader('If-Range')) {
            abort_unless(preg_match('/^bytes=(?:\d+-\d*|-\d+)$/D', $range), 416);
            $params['Range'] = $range;
        }
        try {
            $result = $head ? $storage->getClient()->headObject($params)
                : $storage->getClient()->getObject($params + ['@http' => ['stream' => true]]);
        } catch (AwsException $error) {
            if ($error->getStatusCode() === 404) {
                abort(404);
            }
            if ($error->getStatusCode() === 416) {
                abort(416);
            }
            // SDK exceptions contain signed requests. Do not leak them to clients or logs.
            abort(503, __('storage.unavailable'));
        }
        $headers += ['Content-Type' => $result['ContentType'] ?? 'application/octet-stream',
            'Content-Length' => (string) $result['ContentLength'], 'Accept-Ranges' => 'bytes'];
        if (isset($result['ContentRange'])) {
            $headers['Content-Range'] = $result['ContentRange'];
        }
        if ($head) {
            return response('', 200, $headers);
        }
        $body = $result['Body'];

        return response()->stream(function () use ($body): void {
            try {
                while (! $body->eof()) {
                    echo $body->read(65536);
                    if (connection_aborted()) {
                        break;
                    }
                }
            } finally {
                $body->close();
            }
        }, isset($result['ContentRange']) ? 206 : 200, $headers);
    }

    public function checksum(string $disk, string $path): string
    {
        $stream = Storage::disk($disk)->readStream($path);
        if (! is_resource($stream)) {
            throw new \RuntimeException('Cannot read media for verification.');
        }
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    public function imageDataUri(?string $path): ?string
    {
        $path = $path ? app(ProtectedMedia::class)->storedPath($path) : null;
        if (! $path || ! $this->exists('public', $path) || Storage::disk('public')->size($path) > 10 * 1024 * 1024) {
            return null;
        }
        $bytes = Storage::disk('public')->get($path);
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)
            ? 'data:'.$mime.';base64,'.base64_encode($bytes) : null;
    }
}
