<?php

namespace App\Console\Commands;

use App\Jobs\DeleteUnusedKataVideo;
use App\Models\OnlineKataApplication;
use App\Models\Tournament;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\OnlineKataPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

final class MaintainKataApplications extends Command
{
    protected $signature = 'kata:maintain-applications';

    protected $description = 'Reconcile pending payments and remove expired unreferenced private videos';

    public function handle(OnlineKataPaymentService $payments, ProtectedMedia $media): int
    {
        OnlineKataApplication::whereIn('status', ['creating', 'pending'])->chunkById(50, function ($rows) use ($payments) {
            foreach ($rows as $a) {
                $payments->sync($a);
            }
        });
        OnlineKataApplication::where('status', 'canceled')->where('updated_at', '<', now()->subDays(7))->whereNotNull('video_path')
            ->chunkById(50, function ($rows) use ($media) {
                foreach ($rows as $a) {
                    $path = $a->video_path;
                    DB::transaction(function () use ($a, $path) {
                        $a->update(['video_path' => null]);
                        Queue::connection('protected_media_cleanup')->push(new DeleteUnusedKataVideo($path));
                        TeamActivity::record(null, 'online_kata.upload.expired', Tournament::class, $a->tournament_id,
                            ['old' => ['video_path' => $path], 'new' => ['video_path' => null], 'application_id' => $a->id, 'payer_id' => $a->payer_id]);
                    });
                    (new DeleteUnusedKataVideo($path))->handle($media);
                }
            });
        // Sweep interrupted uploads only after a grace period; the deletion job checks all references.
        foreach (Storage::disk('protected')->files('online-kata-videos') as $path) {
            if (Storage::disk('protected')->lastModified($path) < now()->subDays(7)->timestamp) {
                (new DeleteUnusedKataVideo($path))->handle($media);
            }
        }

        return self::SUCCESS;
    }
}
