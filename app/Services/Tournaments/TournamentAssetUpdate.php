<?php

namespace App\Services\Tournaments;

use App\Models\Championship;
use App\Models\Tournament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class TournamentAssetUpdate
{
    public function save(Model $model, array $attributes, Request $request, array $directories, callable $afterSave): Model
    {
        $created = [];
        $previous = [];
        try {
            foreach ($directories as $field => $directory) {
                if (! $request->hasFile($field) && ($field === 'banner' || ! $request->boolean('remove_'.$field))) {
                    continue;
                }
                $previous[$field] = $model->getAttribute($field);
                $attributes[$field] = $request->hasFile($field) ? $request->file($field)->store($directory, 'public') : null;
                if ($attributes[$field] === false) {
                    throw new RuntimeException('File storage failed.');
                }
                if ($attributes[$field]) {
                    $created[] = $attributes[$field];
                }
            }

            return DB::transaction(function () use ($model, $attributes, $afterSave, $previous, $directories, $request) {
                if ($model->exists) {
                    $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
                    $model->setRawAttributes($locked->getAttributes(), true);
                    foreach (array_keys($previous) as $field) {
                        $previous[$field] = $model->getAttribute($field);
                    }
                    if ($model instanceof Tournament) {
                        abort_unless(TournamentLifecycle::canManage($request->user(), $model), 403);
                    }
                }
                $before = $model->getAttributes();
                $model->fill($attributes)->save();
                $afterSave($model, $before);
                DB::afterCommit(function () use ($previous, $directories): void {
                    foreach ($previous as $field => $path) {
                        $this->removeUnreferenced($path, $directories[$field], $field);
                    }
                });

                return $model;
            });
        } catch (Throwable $error) {
            foreach ($created as $path) {
                Storage::disk('public')->delete($path);
            }
            throw $error;
        }
    }

    private function removeUnreferenced(?string $path, string $directory, string $field): void
    {
        if (! $path || ! str_starts_with($path, $directory.'/') || str_contains($path, '..')) {
            return;
        }
        try {
            $query = $field === 'banner' ? Championship::withTrashed() : Tournament::withTrashed();
            if (! $query->where($field, $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
