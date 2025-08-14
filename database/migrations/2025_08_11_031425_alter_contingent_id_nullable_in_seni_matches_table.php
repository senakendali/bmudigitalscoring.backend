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
        Schema::table('seni_matches', function (Blueprint $table) {
             $table->unsignedBigInteger('contingent_id')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seni_matches', function (Blueprint $table) {
             $table->unsignedBigInteger('contingent_id')->nullable(false)->change();
        });
    }
};
