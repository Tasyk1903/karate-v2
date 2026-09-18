<?php

namespace App\Services\Team;

use App\Models\User;
use App\Services\Admin\AuditValues;
use Illuminate\Support\Facades\DB;

final class TeamActivity
{
    public static function record(?User $actor, string $event, string $subjectType, int $subjectId, array $properties, string $logName = 'panel'): void
    {
        DB::table('activity_log')->insert([
            'log_name' => $logName, 'event' => $event, 'description' => $event,
            'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'causer_type' => $actor ? User::class : null, 'causer_id' => $actor?->id,
            'target_user_id' => AuditValues::target($subjectType, $subjectId, $properties),
            'properties' => json_encode(AuditValues::safe($properties), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
