<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\ProtectedMedia;
use Illuminate\Support\Facades\Storage;

final class ProfileFiles
{
    public function deleteUnreferenced(string $path): void
    {
        $media = app(ProtectedMedia::class);
        $path = $media->storedPath($path);
        if (! $media->validPath($path)) {
            return;
        }
        $used = User::withTrashed()->where(function ($query) use ($path) {
            foreach ([...ProtectedMedia::DOCUMENTS, 'avatar'] as $field) {
                $query->orWhereIn($field, [$path, '/'.$path, '/storage/'.$path]);
            }
        })->exists();
        if (! $used) {
            foreach (['protected', 'public'] as $disk) {
                Storage::disk($disk)->delete($path);
            }
        }
    }
}
