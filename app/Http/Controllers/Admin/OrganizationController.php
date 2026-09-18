<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Team\OrganizationInvitations;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

final class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['search' => 'nullable|string|max:150', 'page' => 'sometimes|integer|min:1']);
        $rows = User::role('Organization')->select('users.id', 'name', 'email', 'region_id', 'created_at')
            ->addSelect(['code' => DB::table('organization_join_codes')->select('code')->whereColumn('organization_id', 'users.id')->limit(1)])
            ->when($data['search'] ?? null, fn ($q, $s) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$s.'%')->orWhere('email', 'like', '%'.$s.'%')))
            ->orderBy('name')->paginate(20);

        return response()->json($rows);
    }

    public function save(Request $request, ?int $id = null)
    {
        $data = $request->validate(['name' => 'required|string|max:255', 'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)],
            'password' => [$id ? 'nullable' : 'required', 'string', 'min:10', 'max:128'], 'region_id' => 'nullable|integer|exists:regions,id']);
        DB::transaction(function () use ($request, $id, $data): void {
            $row = $id ? User::role('Organization')->lockForUpdate()->findOrFail($id) : new User;
            $old = $row->only(['name', 'email', 'region_id']);
            $row->forceFill(collect($data)->except('password')->all());
            if (! empty($data['password'])) {
                $row->password = Hash::make($data['password']);
                $row->remember_token = null;
            }
            if (! $id) {
                $role = DB::table('roles')->where('name', 'Organization')->value('id');
                abort_unless($role, 409, __('admin.role_missing'));
                $row->role_id = $role;
            }
            $row->save();
            if (! empty($data['password']) && $id) {
                DB::table('mobile_access_tokens')->where('user_id', $id)->delete();
                DB::table('sessions')->where('user_id', $id)->delete();
            }
            app(OrganizationInvitations::class)->code($request->user(), $row);
            TeamActivity::record($request->user(), 'admin.organization.saved', User::class, $row->id,
                ['old' => $id ? $old : null, 'new' => $row->only(['name', 'email', 'region_id']), 'password_changed' => ! empty($data['password'])], 'admin');
        });

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request)
    {
        $data = $request->validate(['ids' => 'required|array|min:1|max:100', 'ids.*' => 'required|integer|distinct', 'confirmed' => 'required|accepted']);
        DB::transaction(function () use ($request, $data): void {
            foreach (collect($data['ids'])->sort()->values() as $id) {
                $row = User::role('Organization')->lockForUpdate()->findOrFail($id);
                abort_if((int) $id === $request->user()->id || $row->hasProjectRole('super_admin'), 403);
                $used = DB::table('users')->where('organization_id', $id)->exists();
                foreach (['championships', 'tournaments', 'examinations'] as $table) {
                    $used = $used || DB::table($table)->where('organization_id', $id)->exists();
                }
                abort_if($used, 409, __('admin.in_use'));
                $old = $row->only(['name', 'email', 'region_id']);
                $row->delete();
                DB::table('mobile_access_tokens')->where('user_id', $id)->delete();
                DB::table('sessions')->where('user_id', $id)->delete();
                TeamActivity::record($request->user(), 'admin.organization.deleted', User::class, $id, ['old' => $old, 'new' => null], 'admin');
            }
        });

        return response()->json(['ok' => true]);
    }
}
