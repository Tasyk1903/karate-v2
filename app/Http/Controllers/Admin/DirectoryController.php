<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Region;
use App\Models\Scale;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class DirectoryController extends Controller
{
    private function model(string $directory): string
    {
        abort_unless(in_array($directory, ['regions', 'scales'], true), 404);

        return $directory === 'regions' ? Region::class : Scale::class;
    }

    public function index(Request $request, string $directory)
    {
        $model = $this->model($directory);
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1']);

        return response()->json($model::query()->when($data['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', '%'.$s.'%'))->orderBy('name')->paginate(25));
    }

    public function save(Request $request, string $directory, ?int $id = null)
    {
        $model = $this->model($directory);
        $data = $request->validate(['name' => ['required', 'string', 'max:255', Rule::unique($directory, 'name')->ignore($id)]]);
        DB::transaction(function () use ($request, $model, $data, $id, $directory): void {
            $row = $id ? $model::query()->lockForUpdate()->findOrFail($id) : new $model;
            $old = $row->getAttributes();
            if (! $id && $directory === 'scales') {
                $row->is_rating = false;
            }
            // Existing scale slugs are business identifiers, not editable labels.
            $row->fill($data)->save();
            TeamActivity::record($request->user(), 'admin.directory.saved', $model, $row->id, ['old' => $old, 'new' => $row->getAttributes()], 'admin');
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $directory)
    {
        $model = $this->model($directory);
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $model, $directory, $data): void {
            foreach (collect($data['ids'])->sort()->values() as $id) {
                $row = $model::query()->lockForUpdate()->findOrFail($id);
                $column = $directory === 'regions' ? 'region_id' : 'scale_id';
                $used = DB::table('tournaments')->where($column, $id)->exists()
                    || ($directory === 'regions' && DB::table('users')->where('region_id', $id)->exists());
                abort_if($used || ($directory === 'scales' && $row->slug), 409, __('admin.in_use'));
                $row->delete();
                TeamActivity::record($request->user(), 'admin.directory.deleted', $model, $id, ['old' => $row->getAttributes(), 'new' => null], 'admin');
            }
        });

        return response()->json(['ok' => true]);
    }
}
