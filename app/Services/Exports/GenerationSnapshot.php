<?php

namespace App\Services\Exports;

use App\Models\Tournament;
use Illuminate\Support\Facades\DB;

final class GenerationSnapshot
{
    public static function hash(Tournament $tournament, bool $lock = false): string
    {
        $hash = hash_init('sha256');
        hash_update($hash, json_encode($tournament->getAttributes()));
        $lists = fn () => DB::table('list_tournaments')->where('tournament_id', $tournament->id)->select('id');
        $queries = [
            DB::table('pools')->where('tournament_id', $tournament->id),
            DB::table('kata_pools')->where('tournament_id', $tournament->id),
            DB::table('list_tournaments')->where('tournament_id', $tournament->id),
            DB::table('tournament_student_lists')->whereIn('list_tournament_id', $lists()),
            DB::table('template_student_lists')->whereIn('id', DB::table('list_tournaments')->where('tournament_id', $tournament->id)->select('template_student_list_id')),
        ];
        foreach ($queries as $query) {
            if ($lock) {
                $query->lockForUpdate();
            }
            $query->orderBy('id')->chunkById(500, function ($rows) use ($hash) {
                foreach ($rows as $row) {
                    hash_update($hash, json_encode($row)."\n");
                }
            });
            hash_update($hash, ';');
        }

        return hash_final($hash);
    }
}
