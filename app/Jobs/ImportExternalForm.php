<?php

namespace App\Jobs;

use App\Models\ExternalForm;
use App\Models\User;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\ExternalFormImportService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ImportExternalForm implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 240;

    public function __construct(public int $runId) {}

    public function handle(ExternalFormImportService $importer): void
    {
        $run = DB::table('external_form_import_runs')->where('id', $this->runId)->first();
        if (! $run || $run->status !== 'queued') {
            return;
        }
        if (! DB::table('external_form_import_runs')->where('id', $this->runId)->where('status', 'queued')->update(['status' => 'running', 'updated_at' => now()])) {
            return;
        }
        try {
            $actor = User::findOrFail($run->actor_id);
            DB::transaction(function () use ($run, $actor, $importer): void {
                $result = $importer->import(ExternalForm::findOrFail($run->external_form_id), $actor, (bool) $run->sync_profiles, $run->revision);
                DB::table('external_form_import_runs')->where('id', $this->runId)->update(['status' => 'completed', 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                TeamActivity::record($actor, 'external_form.import.completed', ExternalForm::class, $run->external_form_id,
                    ['run_id' => $this->runId, 'new' => array_diff_key($result, ['entries' => true])]);
            });
        } catch (Throwable $error) {
            $this->failed($error);
        }
    }

    public function failed(?Throwable $error): void
    {
        if ($error) {
            report($error);
        }
        $status = $error && method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 500;
        $code = match ($status) {
            403 => 'forbidden', 409 => 'stale', 422 => 'must_close', default => 'import_failed'
        };
        DB::table('external_form_import_runs')->where('id', $this->runId)->update(['status' => 'failed', 'error_code' => $code, 'updated_at' => now()]);
        $run = DB::table('external_form_import_runs')->find($this->runId);
        if ($run) {
            TeamActivity::record(User::find($run->actor_id), 'external_form.import.failed', ExternalForm::class, $run->external_form_id, ['run_id' => $this->runId, 'error_code' => $code]);
        }
    }
}
