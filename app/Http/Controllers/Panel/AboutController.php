<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AboutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeAccess($request->user());

        return response()->json(__('about'));
    }

    private function authorizeAccess(User $user): void
    {
        abort_unless($user->hasAnyProjectRole(['Organization', 'Secretary', 'Student']), 403);
    }
}
