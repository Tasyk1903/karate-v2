<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\ProtectedMedia;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ProtectedDocumentController extends Controller
{
    public function __invoke(Request $request, User $owner, string $document, ProtectedMedia $media): Response
    {
        abort_unless(in_array($document, ProtectedMedia::DOCUMENTS, true), 404);
        abort_unless($request->user() && $media->canViewDocuments($request->user(), $owner), 403);

        return $media->response($owner->{$document});
    }
}
