<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('educational_resources', function (Blueprint $table) {
            $table->string('license_type', 200)->nullable();  // Puedes quitar ->nullable() si quieres que la columna sea obligatoria
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('educational_resources', function (Blueprint $table) {
            $table->dropColumn('license_type');
        });
    }
};
