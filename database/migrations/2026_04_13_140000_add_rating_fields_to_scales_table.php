<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scales', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->boolean('is_rating')->default(true)->after('slug');
            $table->unsignedInteger('sort_order')->default(0)->after('is_rating');
        });

        $now = now();

        foreach ($this->presets() as $preset) {
            DB::table('scales')->updateOrInsert(
                ['name' => $preset['name']],
                [
                    'slug' => $preset['slug'],
                    'is_rating' => $preset['is_rating'],
                    'sort_order' => $preset['sort_order'],
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('scales', function (Blueprint $table) {
            $table->dropColumn(['slug', 'is_rating', 'sort_order']);
        });
    }

    private function presets(): array
    {
        return [
            [
                'name' => 'Клубный закрытый',
                'slug' => 'closed_club',
                'is_rating' => false,
                'sort_order' => 1,
            ],
            [
                'name' => 'МежКлубный',
                'slug' => 'interclub',
                'is_rating' => false,
                'sort_order' => 2,
            ],
            [
                'name' => 'Городской',
                'slug' => 'city',
                'is_rating' => true,
                'sort_order' => 4,
            ],
            [
                'name' => 'Областной/Краевой',
                'slug' => 'region',
                'is_rating' => true,
                'sort_order' => 5,
            ],
            [
                'name' => 'Федеральный округ',
                'slug' => 'federal_district',
                'is_rating' => true,
                'sort_order' => 6,
            ],
            [
                'name' => 'Всероссийские',
                'slug' => 'all_russian',
                'is_rating' => true,
                'sort_order' => 7,
            ],
            [
                'name' => 'Международные',
                'slug' => 'international',
                'is_rating' => true,
                'sort_order' => 8,
            ],
            [
                'name' => 'Первенство России',
                'slug' => 'russian_championship',
                'is_rating' => true,
                'sort_order' => 9,
            ],
        ];
    }
};
