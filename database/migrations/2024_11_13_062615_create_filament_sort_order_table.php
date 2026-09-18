<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {

        $sortColumn = 'sort_order';


            Schema::table( 'template_student_lists', function ( Blueprint $table ) use ( $sortColumn ) {
                $table->integer( $sortColumn )->unsigned()->default( 0 );
            } );

            DB::table( 'template_student_lists' )
                ->update( [ $sortColumn => DB::raw( 'id' ) ] );


    }

};
