<?php

namespace Tests;

use App\Jobs\RunPanelTask;
use App\Models\PanelTask;
use App\Models\User;
use App\Services\Account\Agreements;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // A missing per-test fake must never write exports or uploads to the real media disks.
        Storage::fake('public');
        Storage::fake('protected');
        Storage::fake('s3');
    }

    protected function runPanelTask(string $id): PanelTask
    {
        app()->call([new RunPanelTask($id), 'handle']);

        return PanelTask::findOrFail($id)->refresh();
    }

    protected function acceptMobileAgreements(User $user): void
    {
        $service = app(Agreements::class);
        foreach (array_keys($service::REQUIRED) as $id) {
            DB::table('agreements')->insertOrIgnore([
                'id' => $id, 'type' => 'Document '.$id, 'description' => '<p>Terms</p>',
            ]);
            $document = DB::table('agreements')->find($id);
            $service->accept($user, $id, $service->version($document));
        }
    }

    protected function acceptPaymentOffer(User $user): void
    {
        DB::table('agreements')->insertOrIgnore(['id' => 1, 'type' => 'Offer', 'description' => '<p>Offer terms</p>']);
        $agreements = app(Agreements::class);
        $agreements->accept($user, 1, $agreements->version(DB::table('agreements')->find(1)));
    }
}
