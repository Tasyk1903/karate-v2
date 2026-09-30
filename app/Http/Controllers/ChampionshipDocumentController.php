<?php

namespace App\Http\Controllers;

use App\Models\Championship;
use App\Models\ChampionshipDocument;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\ChampionshipDocumentAccess;
use App\Services\Tournaments\ChampionshipDocuments;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

final class ChampionshipDocumentController extends Controller
{
    public function index(Request $request, Championship $championship)
    {
        abort_unless(app(ChampionshipDocumentAccess::class)->view($request->user(), $championship), 403);
        $items = ChampionshipDocument::where('championship_id', $championship->id)->orderByDesc('id')->paginate(20);

        return response()->json(['data' => $items->getCollection()->map(fn ($item) => $this->format($item)),
            'can_manage' => app(ChampionshipDocumentAccess::class)->manage($request->user(), $championship),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()]]);
    }

    public function store(Request $request, Championship $championship)
    {
        return $this->save($request, $championship);
    }

    public function update(Request $request, Championship $championship, ChampionshipDocument $document)
    {
        abort_unless((int) $document->championship_id === (int) $championship->id, 404);

        return $this->save($request, $championship, $document);
    }

    private function save(Request $request, Championship $championship, ?ChampionshipDocument $document = null)
    {
        abort_unless(app(ChampionshipDocumentAccess::class)->manage($request->user(), $championship), 403);
        $data = $request->validate(['name' => ['required', 'string', 'max:160'],
            'file' => [$document ? 'nullable' : 'required', 'file', 'mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp', 'max:20480']]);
        $item = app(ChampionshipDocuments::class)->save($request->user(), $championship, $data['name'], $request->file('file'), $document);

        return response()->json(['item' => $this->format($item)], $document ? 200 : 201);
    }

    public function destroy(Request $request, Championship $championship, ChampionshipDocument $document)
    {
        app(ChampionshipDocuments::class)->delete($request->user(), $championship, $document);

        return response()->json(['deleted' => true]);
    }

    public function file(Request $request, Championship $championship, ChampionshipDocument $document)
    {
        $this->authorizeRead($request->user(), $championship, $document);

        return $this->stream($request->user(), $championship, $document, $request->boolean('download'));
    }

    public function link(Request $request, Championship $championship, ChampionshipDocument $document)
    {
        $this->authorizeRead($request->user(), $championship, $document);

        return response()->json(['url' => URL::temporarySignedRoute('championship-document.open', now()->addMinutes(2),
            ['championship' => $championship->id, 'document' => $document->id, 'viewer' => $request->user()->id])]);
    }

    public function open(Request $request, Championship $championship, ChampionshipDocument $document)
    {
        $viewer = User::findOrFail($request->integer('viewer'));
        $this->authorizeRead($viewer, $championship, $document);

        return $this->stream($viewer, $championship, $document, false);
    }

    private function authorizeRead(User $user, Championship $championship, ChampionshipDocument $document): void
    {
        abort_unless(app(ChampionshipDocumentAccess::class)->view($user, $championship), 403);
        abort_unless((int) $document->championship_id === (int) $championship->id, 404);
    }

    private function stream(User $user, Championship $championship, ChampionshipDocument $document, bool $download)
    {
        TeamActivity::record($user, $download ? 'championship.document.downloaded' : 'championship.document.opened', ChampionshipDocument::class, $document->id,
            ['championship_id' => $championship->id, 'name' => $document->name]);

        return app(MediaStorage::class)->response($document->disk, $document->path, $download ? $document->file_name : null);
    }

    private function format(ChampionshipDocument $document): array
    {
        return ['id' => $document->id, 'name' => $document->name, 'file_name' => $document->id.'-'.$document->file_name,
            'extension' => strtolower(pathinfo($document->file_name, PATHINFO_EXTENSION)), 'updated_at' => $document->updated_at?->toISOString()];
    }
}
