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
            // Mode pertandingan: default (urutan normal) / battle (head-to-head)
            $table->string('mode', 20)->default('default')->after('match_type');

            // ID grouping untuk pasangan battle
            $table->unsignedBigInteger('battle_group')->nullable()->after('mode');

            // Sudut battle: red / blue
            $table->enum('corner', ['red', 'blue'])->nullable()->after('battle_group');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seni_matches', function (Blueprint $table) {
            $table->dropColumn(['mode', 'battle_group', 'corner']);
        });
    }
};
