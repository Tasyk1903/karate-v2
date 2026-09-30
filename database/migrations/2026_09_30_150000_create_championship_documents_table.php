<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('championship_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('championship_id')->constrained();
            $table->string('name', 160);
            $table->string('file_name');
            $table->string('disk', 20)->default('protected');
            $table->text('path');
            $table->string('legacy_key', 64)->nullable();
            $table->timestamps();
            $table->unique(['championship_id', 'legacy_key']);
        });

        // Preserve existing objects in place; deployment must not depend on S3 availability.
        DB::table('tournaments')->whereIn('championship_id', DB::table('championships')->select('id'))->orderBy('id')->chunkById(200, function ($rows): void {
            foreach ($rows as $row) {
                foreach (['regulation_document' => 'Положение', 'application_document' => 'Заявление'] as $field => $title) {
                    $path = $row->$field ?? null;
                    if (! $path) {
                        continue;
                    }
                    $path = str_starts_with($path, '/storage/') ? substr($path, 9) : ltrim($path, '/');
                    DB::table('championship_documents')->insertOrIgnore([
                        'championship_id' => $row->championship_id,
                        'name' => mb_substr($title.' — '.$row->name, 0, 160),
                        'file_name' => basename($path), 'path' => $path, 'disk' => 'public',
                        'legacy_key' => hash('sha256', $path), 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_documents');
    }
};
