<?php

namespace Tests\Feature;

use App\Jobs\DeleteUnusedKataVideo;
use App\Models\User;
use App\Services\MediaStorage;
use App\Services\ProtectedMedia;
use Aws\Command;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class S3MediaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('protected');
    }

    private function s3(string $name, array $results): MockHandler
    {
        $disk = Storage::build(['driver' => 's3', 'region' => 'ru-1', 'bucket' => 'test-only',
            'key' => 'dummy', 'secret' => 'dummy', 'endpoint' => 'https://storage.invalid',
            'root' => 'karaterating/'.$name, 'use_path_style_endpoint' => true, 'throw' => true, 'visibility' => 'private']);
        $mock = new MockHandler($results);
        $disk->getClient()->getHandlerList()->setHandler($mock);
        Storage::set($name, $disk);

        return $mock;
    }

    public function test_s3_video_range_is_streamed_and_keeps_private_headers(): void
    {
        $mock = $this->s3('protected', [new Result(['ContentLength' => 3, 'ContentType' => 'video/mp4',
            'ContentRange' => 'bytes 2-4/10', 'Body' => Utils::streamFor('234')])]);
        Route::get('/up', fn () => app(MediaStorage::class)->response('protected', 'online-kata-videos/test.mp4'));
        $this->withHeader('Range', 'bytes=2-4')->get('/up')->assertStatus(206)
            ->assertHeader('Content-Range', 'bytes 2-4/10')->assertHeader('Content-Length', '3')
            ->assertHeader('Cache-Control', 'no-store, private')->assertStreamedContent('234');
        $this->assertSame('karaterating/protected/online-kata-videos/test.mp4', $mock->getLastCommand()['Key']);
        $this->assertSame('bytes=2-4', $mock->getLastCommand()['Range']);
    }

    public function test_head_and_attachment_do_not_download_the_object_body(): void
    {
        $mock = $this->s3('protected', [new Result(['ContentLength' => 500, 'ContentType' => 'application/pdf'])]);
        Route::get('/up', fn () => app(MediaStorage::class)->response('protected', 'panel-tasks/test.pdf', 'results.pdf'));
        $this->head('/up')->assertOk()->assertHeader('Content-Length', '500')
            ->assertHeader('Content-Disposition', 'attachment; filename=results.pdf');
        $this->assertSame('HeadObject', $mock->getLastCommand()->getName());
    }

    public function test_upload_uses_private_acl_and_logical_disk_prefix(): void
    {
        $mock = $this->s3('public', [new Result]);
        Storage::disk('public')->put('avatar/test.txt', 'test');
        $this->assertSame('karaterating/public/avatar/test.txt', $mock->getLastCommand()['Key']);
        $this->assertSame('private', $mock->getLastCommand()['ACL']);
    }

    public function test_s3_audit_counts_missing_paths_once_and_checks_both_prefixes(): void
    {
        User::forceCreate(['email' => 'audit@example.test', 'password' => 'test',
            'passport' => 'passport/missing.png', 'brand' => 'passport/missing.png',
            'insurance' => 'insurance/found.png', 'avatar' => 'avatar/found.png']);
        Storage::disk('s3')->put('protected/insurance/found.png', 'file');
        Storage::disk('s3')->put('public/avatar/found.png', 'file');

        $this->assertSame(1, Artisan::call('media:s3-audit'));
        $audit = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(1, $audit['unique_missing_paths']);
        $this->assertSame(3, $audit['tables']['users']['references']);
        $this->assertSame(2, $audit['tables']['users']['available']);
        Storage::disk('s3')->put('public/passport/missing.png', 'restored');
        $this->assertSame(0, Artisan::call('media:s3-audit'));
        $this->assertSame(0, json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['unique_missing_paths']);
    }

    public function test_legacy_public_s3_document_is_only_available_to_authorized_owner(): void
    {
        $owner = User::forceCreate(['email' => 'owner@example.test', 'password' => 'test', 'passport' => 'passport/test.png']);
        $other = User::forceCreate(['email' => 'other@example.test', 'password' => 'test']);
        $mock = $this->s3('public', [new Result(['ContentLength' => 4, 'ContentType' => 'image/png', 'Body' => Utils::streamFor('file')])]);
        $this->get('/storage/passport/test.png')->assertNotFound();
        $this->actingAs($other)->getJson('/api/panel/files/users/'.$owner->id.'/passport')->assertForbidden();
        $this->assertNull($mock->getLastCommand());
        $this->actingAs($owner)->get('/api/panel/files/users/'.$owner->id.'/passport')->assertOk()->assertStreamedContent('file');
        $this->assertSame('karaterating/public/passport/test.png', $mock->getLastCommand()['Key']);
    }

    public function test_missing_and_invalid_range_do_not_expose_sdk_details(): void
    {
        $this->s3('public', [new S3Exception('sensitive signed request', new Command('GetObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)])]);
        Route::get('/up', fn () => app(MediaStorage::class)->response('public', 'avatar/missing.png'));
        $this->getJson('/up')->assertNotFound()->assertDontSee('sensitive signed request');
        $this->withHeader('Range', 'bytes=0-1,5-9')->getJson('/up')->assertStatus(416);
    }

    public function test_pdf_logo_reads_remote_bytes_without_a_local_path(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        $this->s3('public', [new Result(['ContentLength' => strlen($png)]), new Result(['ContentLength' => strlen($png)]),
            new Result(['Body' => Utils::streamFor($png)])]);
        $this->assertNull(app(ProtectedMedia::class)->localPath('public', 'logo_report/test.png'));
        $this->assertSame('data:image/png;base64,'.base64_encode($png), app(MediaStorage::class)->imageDataUri('logo_report/test.png'));
    }

    public function test_superseded_video_cleanup_deletes_remote_object_without_a_local_path(): void
    {
        $mock = $this->s3('protected', [new Result(['ContentLength' => 4]), new Result]);
        (new DeleteUnusedKataVideo('online-kata-videos/old.mp4'))->handle(app(ProtectedMedia::class));
        $this->assertSame('DeleteObject', $mock->getLastCommand()->getName());
        $this->assertSame('karaterating/protected/online-kata-videos/old.mp4', $mock->getLastCommand()['Key']);
    }

    public function test_legacy_cloud_file_is_verified_before_public_copy_is_removed(): void
    {
        $file = fn () => new Result(['ContentLength' => 4, 'Body' => Utils::streamFor('file')]);
        $public = $this->s3('public', [new Result(['ContentLength' => 4]), $file(), $file(), new Result]);
        $this->s3('protected', [new S3Exception('missing', new Command('HeadObject'), ['code' => 'NoSuchKey', 'response' => new Response(404)]),
            new Result, new Result(['ContentLength' => 4]), $file(), new Result(['ContentLength' => 4])]);
        $media = app(ProtectedMedia::class);
        $this->assertSame('moved', $media->migrateFile('legacy/document.png'));
        $this->assertSame('DeleteObject', $public->getLastCommand()->getName());
        $this->assertTrue($media->isProtected('legacy/document.png'));
    }
}
