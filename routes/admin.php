<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AgreementController;
use App\Http\Controllers\Admin\CommentController;
use App\Http\Controllers\Admin\DirectoryController;
use App\Http\Controllers\Admin\EducationController;
use App\Http\Controllers\Admin\FeedController;
use App\Http\Controllers\Admin\OrganizationController;
use App\Http\Middleware\SuperAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', SuperAdmin::class])->prefix('api/admin')->group(function (): void {
    Route::get('feed', [FeedController::class, 'index']);
    Route::put('feed/{post}', [FeedController::class, 'update'])->whereNumber('post');
    Route::delete('feed/{post}', [FeedController::class, 'destroy'])->whereNumber('post');
    Route::get('feed/{post}/comments', [CommentController::class, 'index'])->whereNumber('post');
    Route::put('feed/{post}/comments/{comment}', [CommentController::class, 'update'])->whereNumber('post')->whereNumber('comment');
    Route::delete('feed/{post}/comments/{comment}', [CommentController::class, 'destroy'])->whereNumber('post')->whereNumber('comment');
    Route::get('agreements', [AgreementController::class, 'index']);
    Route::put('agreements/{agreement}', [AgreementController::class, 'update'])->whereNumber('agreement');
    Route::get('organizations', [OrganizationController::class, 'index']);
    Route::post('organizations', [OrganizationController::class, 'save']);
    Route::put('organizations/{id}', [OrganizationController::class, 'save'])->whereNumber('id');
    Route::delete('organizations', [OrganizationController::class, 'destroy']);
    Route::get('activity', [ActivityLogController::class, 'index']);
    Route::get('activity/{activity}', [ActivityLogController::class, 'show'])->whereNumber('activity');
    Route::get('directories/{directory}', [DirectoryController::class, 'index']);
    Route::post('directories/{directory}', [DirectoryController::class, 'save']);
    Route::put('directories/{directory}/{id}', [DirectoryController::class, 'save'])->whereNumber('id');
    Route::delete('directories/{directory}', [DirectoryController::class, 'destroy']);
    Route::get('education/{section}', [EducationController::class, 'index']);
    Route::post('education/{section}', [EducationController::class, 'save']);
    Route::delete('education/{section}', [EducationController::class, 'destroy']);
    Route::put('education/{section}/{category}', [EducationController::class, 'save'])->whereNumber('category');
    Route::get('education/{section}/{category}/videos', [EducationController::class, 'videos'])->whereNumber('category');
    Route::post('education/{section}/{category}/videos/{video?}', [EducationController::class, 'saveVideo'])->whereNumber('category')->whereNumber('video');
    Route::delete('education/{section}/{category}/videos', [EducationController::class, 'deleteVideos'])->whereNumber('category');
    Route::get('education/{section}/videos/{video}/file/{field}', [EducationController::class, 'file'])->whereNumber('video');
});
