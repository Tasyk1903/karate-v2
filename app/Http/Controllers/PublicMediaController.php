<?php

namespace App\Http\Controllers;

use App\Services\MediaStorage;
use App\Services\ProtectedMedia;
use Symfony\Component\HttpFoundation\Response;

final class PublicMediaController extends Controller
{
    public function __invoke(string $path, ProtectedMedia $media): Response
    {
        abort_if($media->isProtected($path), 404);

        return app(MediaStorage::class)->response('public', $path);
    }
}
