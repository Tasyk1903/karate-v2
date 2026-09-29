<?php

namespace App\Services\Offline;

use App\Models\Tournament;

/** Request-local permission for an authenticated, unexpired offline grant. */
final class OfflineReplay
{
    private static ?int $tournamentId = null;

    public static function permits(Tournament $tournament): bool
    {
        return ! $tournament->trashed() && self::$tournamentId === (int) $tournament->id;
    }

    public static function run(int $id, callable $action): mixed
    {
        $previous = self::$tournamentId;
        self::$tournamentId = $id;
        try {
            return $action();
        } finally {
            self::$tournamentId = $previous;
        }
    }
}
