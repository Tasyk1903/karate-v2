<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrganizationSettingsController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $organization = $this->organizationFor($request->user());

        return response()->json([
            'settings' => $this->payload($organization),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $organization = $this->organizationFor($request->user());

        $data = $request->validate([
            'can_edit_students' => ['required', 'boolean'],
            'can_edit_coaches' => ['required', 'boolean'],
        ]);

        $old = $this->payload($organization);

        DB::transaction(function () use ($request, $organization, $data, $old): void {
            $organization->forceFill([
                'can_edit_students' => $data['can_edit_students'],
                'can_edit_coaches' => $data['can_edit_coaches'],
            ])->save();

            DB::table('activity_log')->insert([
                'log_name' => 'panel',
                'description' => 'Обновлены настройки организации',
                'subject_type' => User::class,
                'subject_id' => $organization->id,
                'event' => 'organization.settings.updated',
                'causer_type' => User::class,
                'causer_id' => $request->user()->id,
                'properties' => json_encode([
                    'old' => $old,
                    'new' => $this->payload($organization->refresh()),
                    'organization' => [
                        'id' => $organization->id,
                        'name' => $organization->name,
                    ],
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return response()->json([
            'settings' => $this->payload($organization->refresh()),
        ]);
    }

    private function organizationFor(User $user): User
    {
        abort_unless($user->hasAnyProjectRole(['Organization', 'Secretary']), 403);

        if ($user->hasProjectRole('Organization')) {
            return $user;
        }

        abort_unless($user->organization_id, 403);

        return User::query()->findOrFail($user->organization_id);
    }

    private function payload(User $organization): array
    {
        return [
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
            ],
            'can_edit_students' => $this->boolColumn($organization, 'can_edit_students', true),
            'can_edit_coaches' => $this->boolColumn($organization, 'can_edit_coaches', true),
        ];
    }

    private function boolColumn(User $user, string $column, bool $default): bool
    {
        if (! Schema::hasColumn('users', $column)) {
            return $default;
        }

        return (bool) $user->{$column};
    }
}
