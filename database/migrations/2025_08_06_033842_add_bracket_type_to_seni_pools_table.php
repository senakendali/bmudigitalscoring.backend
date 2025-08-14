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
        Schema::table('seni_pools', function (Blueprint $table) {
             $table->string('bracket_type', 20)->nullable()->after('gender');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seni_pools', function (Blueprint $table) {
            $table->dropColumn('bracket_type');
        });
    }
};
