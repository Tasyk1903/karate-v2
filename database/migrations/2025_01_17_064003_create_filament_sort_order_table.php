<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {

        $sortColumn = 'sort_order';


        Schema::table('list_tournaments', function (Blueprint $table) use ($sortColumn) {
            $table->integer($sortColumn)->unsigned()->default(0);
        });

        DB::table('list_tournaments')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('pools')
                    ->whereColumn('pools.list_id', 'list_tournaments.id');
            })
            ->update([$sortColumn => DB::raw('id')]);


    }

};
