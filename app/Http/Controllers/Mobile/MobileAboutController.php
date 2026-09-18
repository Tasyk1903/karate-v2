<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileAboutController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(__('about'));
    }
}
