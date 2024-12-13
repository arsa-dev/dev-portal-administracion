<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        Schema::table('notifications_types', function (Blueprint $table) {
            $table->string('description')->nullable()->after('name'); // Añadir el campo 'description'
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('notifications_types', function (Blueprint $table) {
            $table->dropColumn('description'); // Eliminar el campo 'description'
        });
    }
};