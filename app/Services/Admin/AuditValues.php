<?php

namespace App\Services\Admin;

use App\Models\User;

final class AuditValues
{
    public static function safe(array $values): array
    {
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|authorization|cookie|api_key/i', (string) $key)) {
                $values[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $values[$key] = self::safe($value);
            }
        }

        return $values;
    }

    public static function target(string $type, int $id, array $properties): ?int
    {
        if ($type === User::class) {
            return $id;
        }
        foreach ([$properties, $properties['new'] ?? [], $properties['old'] ?? [], $properties['attributes'] ?? []] as $source) {
            if (! is_array($source)) {
                continue;
            }
            foreach (['target_user_id', 'student_id', 'user_id', 'trainer_id', 'coach_id'] as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    return (int) $source[$key];
                }
            }
        }

        return null;
    }
}
