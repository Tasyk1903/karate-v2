<?php

use App\Http\Middleware\MobileCoachOnly;
use App\Http\Middleware\MobileTokenAuth;
use App\Http\Middleware\PanelLocale;
use Aws\Exception\AwsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Request;
use League\Flysystem\FilesystemException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['kr-locale']);
        $middleware->web(append: [PanelLocale::class]);
        $middleware->api(prepend: [PanelLocale::class]);
        $middleware->alias([
            'mobile.auth' => MobileTokenAuth::class,
            'mobile.coach' => MobileCoachOnly::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontReport([FilesystemException::class, AwsException::class]);
        $exceptions->render(function (FilesystemException|AwsException $error, Request $request) {
            return response()->json(['message' => __('storage.unavailable'), 'code' => 'storage_unavailable'], 503);
        });
        $exceptions->render(function (PostTooLargeException $exception, Request $request) {
            $locale = $request->getPreferredLanguage(['ru', 'en']) ?? 'ru';

            return response()->json(['message' => __('validation.custom.video.max', [], $locale),
                'code' => 'upload_too_large'], 413);
        });
    })->create();
