<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $t) {
            $t->index(['city_id', 'id'], 'posts_feed_region_id');
            $t->index(['city_id', 'selected_organization', 'id'], 'posts_feed_region_org_id');
            $t->index(['city_id', 'user_id', 'id'], 'posts_feed_region_author_id');
        });
        Schema::table('comments', fn (Blueprint $t) => $t->index(['post_id', 'parent_id', 'id'], 'comments_feed_thread_id'));
        Schema::table('reactions', fn (Blueprint $t) => $t->index(['reactable_type', 'reactable_id', 'user_id'], 'reactions_feed_target_user'));
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $t) {
            $t->dropIndex('posts_feed_region_id');
            $t->dropIndex('posts_feed_region_org_id');
            $t->dropIndex('posts_feed_region_author_id');
        });
        Schema::table('comments', fn (Blueprint $t) => $t->dropIndex('comments_feed_thread_id'));
        Schema::table('reactions', fn (Blueprint $t) => $t->dropIndex('reactions_feed_target_user'));
    }
};
