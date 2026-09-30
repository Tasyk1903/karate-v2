<?php

namespace App\Services\Tournaments;

use App\Models\Championship;
use App\Models\ChampionshipDocument;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class ChampionshipDocuments
{
    public function save(User $actor, Championship $championship, string $name, ?UploadedFile $file, ?ChampionshipDocument $document = null): ChampionshipDocument
    {
        abort_unless(app(ChampionshipDocumentAccess::class)->manage($actor, $championship), 403);
        $path = null;
        try {
            if ($file) {
                $path = $file->store('championship-documents/'.$championship->id, 'protected');
                if (! $path) {
                    throw new RuntimeException('Document storage failed.');
                }
            }

            return DB::transaction(function () use ($actor, $championship, $name, $file, $path, $document) {
                $championship = Championship::lockForUpdate()->findOrFail($championship->id);
                abort_unless(app(ChampionshipDocumentAccess::class)->manage($actor, $championship), 403);
                $item = $document ? ChampionshipDocument::where('championship_id', $championship->id)->lockForUpdate()->findOrFail($document->id) : new ChampionshipDocument;
                $old = $item->getAttributes();
                $item->fill(['championship_id' => $championship->id, 'name' => $name]);
                if ($path) {
                    $extension = $file->extension();
                    $item->fill(['path' => $path, 'disk' => 'protected', 'file_name' => 'document.'.$extension]);
                }
                $item->save();
                TeamActivity::record($actor, $document ? 'championship.document.updated' : 'championship.document.created', ChampionshipDocument::class, $item->id,
                    ['championship_id' => $championship->id, 'old' => $old, 'new' => $item->getAttributes()]);
                if ($path && isset($old['path'])) {
                    DB::afterCommit(fn () => $this->cleanup($old['disk'], $old['path']));
                }

                return $item;
            });
        } catch (Throwable $error) {
            if ($path) {
                Storage::disk('protected')->delete($path);
            }
            throw $error;
        }
    }

    public function delete(User $actor, Championship $championship, ChampionshipDocument $document): void
    {
        DB::transaction(function () use ($actor, $championship, $document): void {
            $championship = Championship::lockForUpdate()->findOrFail($championship->id);
            abort_unless(app(ChampionshipDocumentAccess::class)->manage($actor, $championship), 403);
            $item = ChampionshipDocument::where('championship_id', $championship->id)->lockForUpdate()->findOrFail($document->id);
            $old = $item->getAttributes();
            $item->delete();
            TeamActivity::record($actor, 'championship.document.deleted', ChampionshipDocument::class, $item->id,
                ['championship_id' => $championship->id, 'old' => $old, 'new' => null]);
            DB::afterCommit(fn () => $this->cleanup($old['disk'], $old['path']));
        });
    }

    private function cleanup(string $disk, string $path): void
    {
        // Legacy tournament files remain referenced by historical records.
        if ($disk !== 'protected' || ! str_starts_with($path, 'championship-documents/')) {
            return;
        }
        try {
            if (! ChampionshipDocument::where('disk', $disk)->where('path', $path)->exists()) {
                Storage::disk($disk)->delete($path);
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
