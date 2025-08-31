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
            // Label babak (Preliminary, Quarterfinal, Semifinal, Final, dst)
            $table->string('round_label', 50)->nullable()->after('round');

            // Prioritas/urutan babak untuk sorting (0=prelim, 1=QF, 2=SF, 3=Final, dst)
            $table->unsignedTinyInteger('round_priority')->nullable()->after('round_label');

            // Index yang berguna untuk query & sorting per pool/babak
            $table->index(['pool_id', 'round_priority', 'battle_group', 'match_order'], 'seni_matches_round_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('seni_matches', function (Blueprint $table) {
            $table->dropIndex('seni_matches_round_idx');
            $table->dropColumn(['round_label', 'round_priority']);
        });
    }
};
