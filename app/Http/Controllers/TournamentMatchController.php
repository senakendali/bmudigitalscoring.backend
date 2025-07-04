<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pool;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\MatchSchedule;
use App\Models\MatchScheduleDetail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Arr;


class TournamentMatchController extends Controller
{
    private function getRoundLabel($round, $maxRound)
    {
        $labels = [
            0 => 'Final',
            1 => 'Semifinal',
            2 => '1/4 Final',
            3 => '1/8 Final',
            4 => '1/16 Final',
            5 => '1/32 Final',
            6 => '1/64 Final',
        ];

        $diff = $maxRound - $round;
        return $labels[$diff] ?? 'Penyisihan';
    }
    

    public function generateBracket($poolId)
    {
        // Ambil pool dan data penting
        $pool = Pool::with('categoryClass')->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $tournamentId = $pool->tournament_id;
        $matchChart = (int) $pool->match_chart;

        $matchCategoryId = $pool->match_category_id;
        $categoryClassId = $pool->category_class_id;
        $ageCategoryId = $pool->age_category_id;

        // Ambil peserta yang belum masuk ke match
        $existingMatches = TournamentMatch::where('pool_id', $poolId)->pluck('participant_1')
            ->merge(
                TournamentMatch::where('pool_id', $poolId)->pluck('participant_2')
            )->unique();

        // 🔍 Ambil peserta berdasarkan match_category_id, class, dan usia
        $participants = DB::table('tournament_participants')
            ->join('team_members', 'tournament_participants.team_member_id', '=', 'team_members.id')
            ->where('tournament_participants.tournament_id', $tournamentId)
            ->whereNotIn('team_members.id', $existingMatches)
            ->when($matchCategoryId, fn($q) => $q->where('team_members.match_category_id', $matchCategoryId))
            ->when($categoryClassId, fn($q) => $q->where('team_members.category_class_id', $categoryClassId))
            ->when($ageCategoryId, fn($q) => $q->where('team_members.age_category_id', $ageCategoryId))
            ->select('team_members.id', 'team_members.name', 'team_members.contingent_id')
            ->get()
            ->shuffle();

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'Semua peserta sudah memiliki match atau tidak ada peserta valid.'], 400);
        }

        // Cek jenis bagan
        if ($matchChart == 2) {
            return $this->generateSingleRoundBracket($poolId, $participants);
        }

        if ($matchChart == 6) {
            return $this->generateBracketForSix($poolId, $participants);
        }

        if ($matchChart == 0) {
            return $this->generateFullPrestasiBracket($poolId, $participants);
        }

        return $this->generateSingleElimination($tournamentId, $poolId, $participants, $matchChart);
    }

    public function regenerateBracket($poolId)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::with(['categoryClass'])->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $tournamentId = $pool->tournament_id;
        $matchChart = (int) $pool->match_chart;

        // Coba ambil peserta yang sudah masuk pool ini
        $participants = collect(
            DB::table('tournament_participants')
                ->join('team_members', 'tournament_participants.team_member_id', '=', 'team_members.id')
                ->where('tournament_participants.pool_id', $poolId)
                ->select('team_members.id', 'team_members.name')
                ->get()
        );

        // Kalau belum ada isinya, ambil peserta dari turnamen yg belum punya pool_id
        if ($participants->isEmpty()) {
            $participants = collect(
                DB::table('tournament_participants')
                    ->join('team_members', 'tournament_participants.team_member_id', '=', 'team_members.id')
                    ->where('tournament_participants.tournament_id', $tournamentId)
                    ->whereNull('tournament_participants.pool_id')
                    ->select('team_members.id', 'team_members.name')
                    ->get()
            );
        }

        // Shuffle ulang
        $participants = $participants->shuffle()->values();
        $participantCount = $participants->count();

        // Validasi jumlah peserta
        if (!in_array($matchChart, ['full_prestasi', 0, 6]) && $participantCount < $matchChart) {
            return response()->json([
                'message' => 'Peserta tidak mencukupi untuk membuat bagan ini.',
                'found' => $participantCount,
                'needed' => $matchChart
            ], 400);
        }

        // Generate berdasarkan match chart
        if ($matchChart === 2) {
            return $this->generateSingleRoundBracket($poolId, $participants);
        }

        if ($matchChart === 6) {
            return $this->generateBracketForSix($poolId, $participants);
        }

        if ($matchChart === 0) {
            return $this->generateFullPrestasiBracket($poolId, $participants);
        }

        return $this->generateSingleElimination($tournamentId, $poolId, $participants, $matchChart);
    }




    public function regenerateBracket_asli($poolId)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::with(['categoryClass'])->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $tournamentId = $pool->tournament_id;
        $matchChart = (int) $pool->match_chart;

        // ✅ Ambil peserta yang memang sudah punya pool_id = $poolId
        $participants = collect(
            DB::table('tournament_participants')
                ->join('team_members', 'tournament_participants.team_member_id', '=', 'team_members.id')
                ->where('tournament_participants.tournament_id', $tournamentId)
                ->where('tournament_participants.pool_id', $poolId)
                ->select('team_members.id', 'team_members.name')
                ->get()
        )->shuffle()->values();

       




        /*if ($participants->isEmpty()) {
            return response()->json(['message' => 'Tidak ada peserta sesuai pool ini yang belum dipakai.'], 400);
        }*/

        // ✅ Validasi peserta cukup
        $participantCount = $participants->count();
        if (!in_array($matchChart, ['full_prestasi', 0]) && $participantCount < $matchChart) {
            return response()->json([
                'message' => 'Peserta tidak mencukupi untuk membuat bagan ini.',
                'found' => $participantCount,
                'needed' => $matchChart
            ], 400);
        }

        // 🧠 Generate berdasarkan match chart
        if ($matchChart === 2) {
            return $this->generateSingleRoundBracket($poolId, $participants);
        }

        if ($matchChart === 6) {
            return $this->generateBracketForSix($poolId, $participants);
        }

        if ($matchChart === 0) {
            return $this->generateFullPrestasiBracket($poolId, $participants);
        }

        return $this->generateSingleElimination($tournamentId, $poolId, $participants, $matchChart);
    }


    

    private function generateSingleRoundBracket($poolId)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $matchCategoryId = $pool->match_category_id;
        if (!$matchCategoryId) {
            return response()->json(['message' => 'Match Category ID tidak ditemukan di pool.'], 400);
        }

        $participants = DB::table('team_members')
            ->join('tournament_participants', 'team_members.id', '=', 'tournament_participants.team_member_id')
            ->where('tournament_participants.tournament_id', $pool->tournament_id)
            ->where('team_members.age_category_id', $pool->age_category_id)
            ->where('team_members.match_category_id', $matchCategoryId)
            ->where('team_members.category_class_id', $pool->category_class_id)
            ->whereIn('team_members.contingent_id', function ($q) use ($pool) {
                $q->select('contingent_id')
                ->from('tournament_contingents')
                ->where('tournament_id', $pool->tournament_id);
            })
            ->select(
                'team_members.id',
                'team_members.category_class_id',
                'team_members.gender',
                'team_members.contingent_id'
            )
            ->get();

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'Tidak ada peserta valid untuk pool ini.'], 400);
        }

        $matches = collect();
        $matchNumber = 1;

        $grouped = $participants->groupBy(function ($p) {
            return ($p->category_class_id ?? 'null') . '-' . ($p->gender ?? 'null');
        });

        foreach ($grouped as $group) {
            $queue = $group->shuffle()->values();

            while ($queue->count() > 0) {
                $p1 = $queue->shift();

                // 1️⃣ Cari lawan dari kontingen berbeda
                $opponentIndex = $queue->search(fn($p2) =>
                    $p2->contingent_id !== $p1->contingent_id &&
                    $p2->id !== $p1->id
                );

                // 2️⃣ Kalau tidak ada, cari lawan dari kontingen yang sama
                if ($opponentIndex === false) {
                    $opponentIndex = $queue->search(fn($p2) => $p2->id !== $p1->id);
                }

                $p2 = $opponentIndex !== false ? $queue->pull($opponentIndex) : null;

                $matches->push([
                    'pool_id' => $poolId,
                    'round' => 1,
                    'round_label' => $this->getRoundLabel(1, 1),
                    'match_number' => $matchNumber++,
                    'participant_1' => $p1->id,
                    'participant_2' => $p2?->id,
                    'winner_id' => $p2 ? null : $p1->id,
                    'next_match_id' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        TournamentMatch::insert($matches->toArray());

        return response()->json([
            'message' => 'Bracket berhasil dibuat.',
            'total_participant' => $participants->count(),
            'total_match' => $matches->count(),
            'matches' => $matches,
        ]);
    }


    
    

   private function generateBracketForSix($poolId, $participants)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::with('tournament')->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $usedParticipantIds = TournamentMatch::whereHas('pool', fn($q) =>
            $q->where('tournament_id', $pool->tournament_id)
        )->pluck('participant_1')
        ->merge(
            TournamentMatch::whereHas('pool', fn($q) =>
                $q->where('tournament_id', $pool->tournament_id)
            )->pluck('participant_2')
        )->unique();

        $participants = $participants->reject(fn($p) => $usedParticipantIds->contains($p->id))->values();
        $selected = $participants->slice(0, 6)->values();
        $participantIds = $selected->pluck('id')->toArray();

        TournamentParticipant::whereIn('team_member_id', $participantIds)
            ->where('tournament_id', $pool->tournament_id)
            ->update(['pool_id' => $poolId]);

        if ($selected->count() === 5) { 
            return $this->generateBracketForFive($poolId, $selected);
        }

        if ($selected->count() === 6) {
            $rounds = $this->generateDefaultSix($poolId, $selected);
            return response()->json([
                'message' => 'Bracket untuk 6 peserta berhasil dibuat.',
                'rounds' => $rounds,
            ]);
        }

        $matchNumber = 1;
        $matchMap = [];
        $queue = $selected->pluck('id')->toArray();

        $slot = pow(2, ceil(log(max(count($queue), 2), 2)));
        $maxRound = ceil(log($slot, 2));
        $byeCount = $slot - count($queue);

        for ($i = 0; $i < $byeCount; $i++) {
            $queue[] = null;
        }

        // ROUND 1
        for ($i = 0; $i < count($queue); $i += 2) {
            $p1 = $queue[$i] ?? null;
            $p2 = $queue[$i + 1] ?? null;

            $winner = ($p1 && !$p2) ? $p1 : (($p2 && !$p1) ? $p2 : null);
            $matchId = DB::table('tournament_matches')->insertGetId([
                'pool_id' => $poolId,
                'round' => 1,
                'round_label' => $this->getRoundLabel(1, $maxRound),
                'match_number' => $matchNumber++,
                'participant_1' => $p1,
                'participant_2' => $p2,
                'winner_id' => $winner,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $matchMap[] = [
                'id' => $matchId,
                'winner' => $winner,
            ];
        }

        // ROUNDS 2+
        while (count($matchMap) > 1) {
            $nextRound = [];
            $currentRound = ceil(log($slot, 2)) - ceil(log(count($matchMap), 2)) + 1;

            foreach (array_chunk($matchMap, 2) as $pair) {
                $blue = $pair[0]; // kiri
                $red = $pair[1] ?? ['id' => null, 'winner' => null]; // kanan

                $matchId = DB::table('tournament_matches')->insertGetId([
                    'pool_id' => $poolId,
                    'round' => $currentRound,
                    'round_label' => $this->getRoundLabel($currentRound, $maxRound),
                    'match_number' => $matchNumber++,
                    'participant_1' => $blue['winner'],
                    'participant_2' => $red['winner'],
                    'winner_id' => null,
                    'parent_match_blue_id' => $blue['id'],
                    'parent_match_red_id' => $red['id'],
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($blue['id']) {
                    DB::table('tournament_matches')->where('id', $blue['id'])->update(['next_match_id' => $matchId]);
                }
                if ($red['id']) {
                    DB::table('tournament_matches')->where('id', $red['id'])->update(['next_match_id' => $matchId]);
                }

                $nextRound[] = [
                    'id' => $matchId,
                    'winner' => null,
                ];
            }

            $matchMap = $nextRound;
        }

        $inserted = TournamentMatch::where('pool_id', $poolId)
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        return response()->json([
            'message' => 'Bracket untuk ' . $selected->count() . ' peserta berhasil dibuat.',
            'rounds' => $inserted,
        ]);
    }


   private function generateDefaultSix($poolId, $selected)
    {
        $matchNumber = 1;
        $maxRound = 3;

        // Shuffle agar pairing acak
        $shuffled = $selected->shuffle()->values();

        // Ambil 2 peserta untuk BYE
        $bye1 = $shuffled->shift();
        $bye2 = $shuffled->pop();

        // Ambil 4 peserta sisa → cari pairing beda kontingen dulu
        $remaining = $shuffled->values();
        $pairs = [];

        $used = [];

        for ($i = 0; $i < $remaining->count(); $i++) {
            $p1 = $remaining[$i];
            if (in_array($p1->id, $used)) continue;

            $p2Index = $remaining->search(fn($p2) =>
                !in_array($p2->id, $used) &&
                $p2->id !== $p1->id &&
                $p2->contingent_id !== $p1->contingent_id
            );

            if ($p2Index === false) {
                $p2Index = $remaining->search(fn($p2) =>
                    !in_array($p2->id, $used) && $p2->id !== $p1->id
                );
            }

            if ($p2Index !== false) {
                $p2 = $remaining[$p2Index];
                $pairs[] = [$p1, $p2];
                $used[] = $p1->id;
                $used[] = $p2->id;
            }
        }

        // Jika pairing kurang dari 2, isi dengan peserta tersisa
        foreach ($remaining as $p) {
            if (!in_array($p->id, $used)) {
                $pairs[] = [$p, null];
            }
        }

        // Insert match 1 (BYE)
        $match1Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $bye1->id,
            'participant_2' => null,
            'winner_id' => $bye1->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert match 2 & 3 (pair)
        $match2Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $pairs[0][0]->id ?? null,
            'participant_2' => $pairs[0][1]->id ?? null,
            'winner_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match3Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $pairs[1][0]->id ?? null,
            'participant_2' => $pairs[1][1]->id ?? null,
            'winner_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert match 4 (BYE)
        $match4Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $bye2->id,
            'participant_2' => null,
            'winner_id' => $bye2->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Semifinal
        $match5Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 2,
            'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $bye1->id,
            'participant_2' => null,
            'winner_id' => null,
            'parent_match_blue_id' => $match1Id,
            'parent_match_red_id' => $match2Id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $match6Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 2,
            'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $bye2->id,
            'participant_2' => null,
            'winner_id' => null,
            'parent_match_blue_id' => $match4Id,
            'parent_match_red_id' => $match3Id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Final
        $match7Id = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 3,
            'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => null,
            'participant_2' => null,
            'winner_id' => null,
            'parent_match_blue_id' => $match5Id,
            'parent_match_red_id' => $match6Id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Link next match
        DB::table('tournament_matches')->where('id', $match1Id)->update(['next_match_id' => $match5Id]);
        DB::table('tournament_matches')->where('id', $match2Id)->update(['next_match_id' => $match5Id]);
        DB::table('tournament_matches')->where('id', $match3Id)->update(['next_match_id' => $match6Id]);
        DB::table('tournament_matches')->where('id', $match4Id)->update(['next_match_id' => $match6Id]);
        DB::table('tournament_matches')->where('id', $match5Id)->update(['next_match_id' => $match7Id]);
        DB::table('tournament_matches')->where('id', $match6Id)->update(['next_match_id' => $match7Id]);

        return TournamentMatch::where('pool_id', $poolId)
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();
    }


    private function generateFullPrestasiBracket($poolId, $participants)
{
    TournamentMatch::where('pool_id', $poolId)->delete();

    $pool = Pool::with('tournament')->find($poolId);
    if (!$pool) {
        return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
    }

    $tournamentId = $pool->tournament_id;
    $desiredClassId = $pool->category_class_id;
    $desiredMatchCategoryId = $pool->match_category_id;

    if (!$desiredClassId || !$desiredMatchCategoryId) {
        return response()->json(['message' => 'Pool tidak memiliki kelas atau kategori pertandingan.'], 400);
    }

    $eligibleParticipants = DB::table('tournament_participants')
        ->join('team_members', 'tournament_participants.team_member_id', '=', 'team_members.id')
        ->where('tournament_participants.tournament_id', $tournamentId)
        ->where('team_members.category_class_id', $desiredClassId)
        ->where('team_members.match_category_id', $desiredMatchCategoryId)
        ->select(
            'tournament_participants.id as tp_id',
            'team_members.id as id',
            'team_members.name',
            'team_members.contingent_id'
        )
        ->get();

    // Reset pool_id peserta
    DB::table('tournament_participants')->where('pool_id', $poolId)->update(['pool_id' => null]);

    $shuffled = $eligibleParticipants->shuffle()->values();
    $participantIdsToUpdate = $shuffled->pluck('tp_id');
    DB::table('tournament_participants')->whereIn('id', $participantIdsToUpdate)->update(['pool_id' => $poolId]);

    $participantIds = $shuffled->pluck('id')->shuffle()->values();
    $total = $participantIds->count();

    if ($total === 1) {
        DB::table('tournament_matches')->insert([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => 'Final',
            'match_number' => 1,
            'participant_1' => $participantIds[0],
            'participant_2' => null,
            'winner_id' => $participantIds[0],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'Bracket untuk 1 peserta berhasil dibuat.',
            'total_participants' => $total,
            'bracket_size' => 1,
            'total_matches' => 1,
            'rounds_generated' => 1,
        ]);
    }

    $bracketSize = pow(2, ceil(log($total, 2)));
    $maxRound = ceil(log($bracketSize, 2));
    $matchNumber = 1;
    $matches = [];
    $preliminaryMatches = $total - ($bracketSize / 2);
    $byes = $bracketSize - $total;
    $roundMatchCounts = [];

    $getLabel = function ($round) use ($maxRound) {
        $labels = [
            0 => 'Final',
            1 => 'Semifinal',
            2 => '1/4 Final',
            3 => '1/8 Final',
            4 => '1/16 Final',
            5 => '1/32 Final',
            6 => '1/64 Final',
        ];
        $diff = $maxRound - $round;
        return $labels[$diff] ?? 'Penyisihan';
    };

    for ($round = 1; $round <= $maxRound; $round++) {
        $roundMatchCounts[$round] = $bracketSize / pow(2, $round);
    }

    for ($round = 1; $round <= $maxRound; $round++) {
        for ($i = 0; $i < $roundMatchCounts[$round]; $i++) {
            $matches[] = [
                'pool_id' => $poolId,
                'round' => $round,
                'round_label' => $getLabel($round),
                'match_number' => $matchNumber++,
                'participant_1' => null,
                'participant_2' => null,
                'winner_id' => null,
                'next_match_id' => null,
                'parent_match_red_id' => null,
                'parent_match_blue_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
    }

    // === Pairing preliminary (beda kontingen diprioritaskan) ===
    $firstRoundMatchIndexes = array_keys(array_filter($matches, fn($m) => $m['round'] === 1));

    // ✅ Pastiin team_member yang diambil hanya dari turnamen ini
    $teamMembers = DB::table('team_members')
        ->join('tournament_participants', 'team_members.id', '=', 'tournament_participants.team_member_id')
        ->whereIn('team_members.id', $participantIds)
        ->where('tournament_participants.tournament_id', $tournamentId)
        ->select('team_members.id', 'team_members.contingent_id')
        ->get()
        ->keyBy('id');

    $used = [];
    $pairings = [];
    $ids = $participantIds->all();

    while (count($used) < $preliminaryMatches * 2) {
        $p1 = null;
        $p2 = null;

        foreach ($ids as $id1) {
            if (in_array($id1, $used)) continue;
            $p1 = $id1;

            foreach ($ids as $id2) {
                if (in_array($id2, $used) || $id1 === $id2) continue;
                if ($teamMembers[$id1]->contingent_id !== $teamMembers[$id2]->contingent_id) {
                    $p2 = $id2;
                    break;
                }
            }

            if (!$p2) {
                foreach ($ids as $id2) {
                    if (in_array($id2, $used) || $id1 === $id2) continue;
                    $p2 = $id2;
                    break;
                }
            }

            if ($p1 && $p2) {
                $used[] = $p1;
                $used[] = $p2;
                $pairings[] = [$p1, $p2];
                break;
            }
        }
    }

    for ($j = 0; $j < $preliminaryMatches; $j++) {
        $index = $firstRoundMatchIndexes[$j];
        $pair = $pairings[$j];

        $matches[$index]['participant_1'] = $pair[0] ?? null;
        $matches[$index]['participant_2'] = $pair[1] ?? null;
    }

    // === BYE ===
    $remainingIds = array_values(array_diff($participantIds->all(), $used));
    $byeTargets = array_slice($firstRoundMatchIndexes, $preliminaryMatches);

    foreach ($byeTargets as $index) {
        $id = array_shift($remainingIds);
        $matches[$index]['participant_1'] = $id;
        $matches[$index]['winner_id'] = $id;
    }

    DB::table('tournament_matches')->insert($matches);

    // === Link parent-child match ===
    $matchMap = TournamentMatch::where('pool_id', $poolId)->orderBy('match_number')->get();
    $roundGroups = $matchMap->groupBy('round');

    foreach ($roundGroups as $round => $matchesInRound) {
        if ($round >= $maxRound) continue;

        $nextRoundMatches = $roundGroups[$round + 1] ?? collect();

        foreach ($matchesInRound->values() as $i => $match) {
            $targetIndex = floor($i / 2);

            if (isset($nextRoundMatches[$targetIndex])) {
                $nextMatch = $nextRoundMatches[$targetIndex];

                $match->next_match_id = $nextMatch->id;
                $match->save();

                if ($i % 2 === 0) {
                    TournamentMatch::where('id', $nextMatch->id)->update([
                        'parent_match_blue_id' => $match->id,
                    ]);
                } else {
                    TournamentMatch::where('id', $nextMatch->id)->update([
                        'parent_match_red_id' => $match->id,
                    ]);
                }

                if ($match->winner_id) {
                    if (is_null($nextMatch->participant_1)) {
                        $nextMatch->participant_1 = $match->winner_id;
                    } elseif (is_null($nextMatch->participant_2)) {
                        $nextMatch->participant_2 = $match->winner_id;
                    }
                    $nextMatch->save();
                }
            }
        }
    }

    return response()->json([
        'message' => 'Bracket berhasil dibuat dan peserta sudah di-assign ke pool.',
        'total_participants' => $total,
        'bracket_size' => $bracketSize,
        'total_matches' => count($matches),
        'rounds_generated' => $maxRound,
    ]);
}






   
   
   




    private function getByeSlots($count, $total)
    {
        $slots = [];
        if ($count == 1) $slots[] = 0;
        elseif ($count == 2) $slots = [0, $total - 1];
        elseif ($count == 3) $slots = [0, (int) floor($total / 2), $total - 1];
        else {
            for ($i = 0; $i < $count; $i++) {
                $slots[] = (int) round($i * $total / $count);
            }
        }
        return $slots;
    }

   private function generateBracketForFive($poolId, $participants)
    {
        $matchNumber = 1;
        $maxRound = 3;

        // Acak urutan peserta
        $shuffled = $participants->shuffle()->values();

        // 🔍 Cari pasangan preliminary dengan kontingen berbeda
        $p1 = $shuffled[0];
        $p2Index = $shuffled->search(fn($p) => $p->id !== $p1->id && $p->contingent_id !== $p1->contingent_id);

        if ($p2Index === false) {
            // Gak ada yang beda kontingen, ambil peserta kedua sembarang
            $p2Index = 1;
        }

        $p2 = $shuffled[$p2Index];
        $remaining = $shuffled->reject(fn($p) => in_array($p->id, [$p1->id, $p2->id]))->values();

        // ROUND 1 - Preliminary
        $prelimId = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $p1->id,
            'participant_2' => $p2->id,
            'winner_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ROUND 2 - Semifinal 1 (winner dari preliminary vs peserta berikutnya)
        $semi1 = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 2,
            'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => null, // winner dari preliminary
            'participant_2' => $remaining[0]->id,
            'winner_id' => null,
            'parent_match_blue_id' => $prelimId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ROUND 2 - Semifinal 2
        $semi2 = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 2,
            'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $remaining[1]->id,
            'participant_2' => $remaining[2]->id,
            'winner_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ROUND 3 - Final
        $final = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 3,
            'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => null,
            'participant_2' => null,
            'winner_id' => null,
            'parent_match_blue_id' => $semi1,
            'parent_match_red_id' => $semi2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // 🔗 next_match_id
        DB::table('tournament_matches')->where('id', $prelimId)->update([
            'next_match_id' => $semi1,
        ]);
        DB::table('tournament_matches')->where('id', $semi1)->update([
            'next_match_id' => $final,
        ]);
        DB::table('tournament_matches')->where('id', $semi2)->update([
            'next_match_id' => $final,
        ]);

        return response()->json([
            'message' => '✅ Bracket 5 peserta berhasil dibuat (3 babak, pair beda kontingen diprioritaskan).',
            'matches' => TournamentMatch::where('pool_id', $poolId)
                ->orderBy('round')
                ->orderBy('match_number')
                ->get()
        ]);
    }




    private function generateBracketForNine($poolId, $participants)
    {
        $matchNumber = 1;
        $maxRound = 4;

        $shuffled = $participants->shuffle()->values();
        $participantIds = $shuffled->pluck('id')->values();

        // ROUND 1 - Preliminary
        $prelim1 = $participantIds[0];
        $prelim2 = $participantIds[1];

        $preliminaryId = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 1,
            'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++,
            'participant_1' => $prelim1,
            'participant_2' => $prelim2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sisa 7 peserta
        $remaining = $participantIds->slice(2)->values();
        $matchIds = [];

        // ROUND 2 - 4 pertandingan
        for ($i = 0; $i < 4; $i++) {
            $p1 = null;
            $p2 = null;
            $parentBlue = null;
            $parentRed = null;

            if ($i === 0) {
                // Match pertama lawan pemenang preliminary
                $p2 = $remaining[0] ?? null;
                $parentBlue = $preliminaryId;
            } else {
                $p1 = $remaining[($i - 1) * 2 + 1] ?? null;
                $p2 = $remaining[($i - 1) * 2 + 2] ?? null;
            }

            $id = DB::table('tournament_matches')->insertGetId([
                'pool_id' => $poolId,
                'round' => 2,
                'round_label' => $this->getRoundLabel(2, $maxRound),
                'match_number' => $matchNumber++,
                'participant_1' => $p1,
                'participant_2' => $p2,
                'parent_match_blue_id' => $parentBlue,
                'parent_match_red_id' => $parentRed,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $matchIds[] = $id;
        }

        // ROUND 3 - Semifinal
        $semi1 = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 3,
            'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++,
            'parent_match_blue_id' => $matchIds[0],
            'parent_match_red_id' => $matchIds[1],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $semi2 = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 3,
            'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++,
            'parent_match_blue_id' => $matchIds[2],
            'parent_match_red_id' => $matchIds[3],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // ROUND 4 - Final
        $final = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId,
            'round' => 4,
            'round_label' => $this->getRoundLabel(4, $maxRound),
            'match_number' => $matchNumber++,
            'parent_match_blue_id' => $semi1,
            'parent_match_red_id' => $semi2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Update next_match_id (optional)
        DB::table('tournament_matches')->where('id', $preliminaryId)->update([
            'next_match_id' => $matchIds[0],
        ]);
        DB::table('tournament_matches')->where('id', $matchIds[0])->update(['next_match_id' => $semi1]);
        DB::table('tournament_matches')->where('id', $matchIds[1])->update(['next_match_id' => $semi1]);
        DB::table('tournament_matches')->where('id', $matchIds[2])->update(['next_match_id' => $semi2]);
        DB::table('tournament_matches')->where('id', $matchIds[3])->update(['next_match_id' => $semi2]);
        DB::table('tournament_matches')->where('id', $semi1)->update(['next_match_id' => $final]);
        DB::table('tournament_matches')->where('id', $semi2)->update(['next_match_id' => $final]);

        // Bersihkan participant_1 dari match pertama karena diisi oleh pemenang preliminary
        DB::table('tournament_matches')->where('id', $matchIds[0])->update([
            'participant_1' => null
        ]);

        return response()->json(['message' => '✅ Bracket untuk 9 peserta berhasil dibuat dengan match BYE di awal.']);
    }



    private function generateBracketForTen($poolId, $participants)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::with('tournament')->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        // Saring peserta yang belum masuk ke pool manapun
        $usedParticipantIds = TournamentMatch::whereHas('pool', fn($q) => 
            $q->where('tournament_id', $pool->tournament_id)
        )->pluck('participant_1')
        ->merge(
            TournamentMatch::whereHas('pool', fn($q) => 
                $q->where('tournament_id', $pool->tournament_id)
            )->pluck('participant_2')
        )->unique();

        $participants = $participants->reject(fn($p) =>
            $usedParticipantIds->contains($p->id)
        )->values();

        if ($participants->count() < 10) {
            return response()->json(['message' => 'Peserta kurang dari 10 setelah disaring.'], 400);
        }

        $selected = $participants->slice(0, 10)->values();
        $participantIds = $selected->pluck('id')->toArray();

        // Set pool_id ke peserta
        TournamentParticipant::whereIn('team_member_id', $participantIds)
            ->where('tournament_id', $pool->tournament_id)
            ->update(['pool_id' => $poolId]);

        $matchNumber = 1;
        $now = now();
        $matchIds = [];
        $maxRound = 4;

        // ======================
        // ROUND 1 - Total 6 Match
        // ======================
        $matchIds[0] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[0]->id,
            'participant_2' => null, 'winner_id' => $selected[0]->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[1] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[1]->id,
            'participant_2' => $selected[2]->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[2] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[3]->id,
            'participant_2' => $selected[4]->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[3] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[5]->id,
            'participant_2' => $selected[6]->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[4] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[7]->id,
            'participant_2' => $selected[8]->id, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[5] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 1, 'round_label' => $this->getRoundLabel(1, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => $selected[9]->id,
            'participant_2' => null, 'winner_id' => $selected[9]->id,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        // ======================
        // ROUND 2 - Total 4 Match
        // ======================
        $matchIds[6] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 2, 'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++, 'participant_1' => null,
            'participant_2' => $selected[0]->id, // BYE lawan pemenang #1
            'parent_match_blue_id' => $matchIds[0], 'parent_match_red_id' => $matchIds[1],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[7] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 2, 'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++, 'parent_match_blue_id' => $matchIds[2],
            'parent_match_red_id' => $matchIds[3], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[8] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 2, 'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++, 'participant_2' => $selected[9]->id,
            'parent_match_blue_id' => $matchIds[4], 'parent_match_red_id' => $matchIds[5],
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[9] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 2, 'round_label' => $this->getRoundLabel(2, $maxRound),
            'match_number' => $matchNumber++, 'created_at' => $now, 'updated_at' => $now,
        ]);

        // ======================
        // ROUND 3 - SEMIFINAL
        // ======================
        $matchIds[10] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 3, 'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++, 'parent_match_blue_id' => $matchIds[6],
            'parent_match_red_id' => $matchIds[7], 'created_at' => $now, 'updated_at' => $now,
        ]);
        $matchIds[11] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 3, 'round_label' => $this->getRoundLabel(3, $maxRound),
            'match_number' => $matchNumber++, 'parent_match_blue_id' => $matchIds[8],
            'parent_match_red_id' => $matchIds[9], 'created_at' => $now, 'updated_at' => $now,
        ]);

        // ======================
        // FINAL
        // ======================
        $matchIds[12] = DB::table('tournament_matches')->insertGetId([
            'pool_id' => $poolId, 'round' => 4, 'round_label' => $this->getRoundLabel(4, $maxRound),
            'match_number' => $matchNumber++, 'parent_match_blue_id' => $matchIds[10],
            'parent_match_red_id' => $matchIds[11], 'created_at' => $now, 'updated_at' => $now,
        ]);

        // ======================
        // Optional: next_match_id
        // ======================
        $map = [
            0 => 6, 1 => 6, 2 => 7, 3 => 7, 4 => 8, 5 => 8,
            6 => 10, 7 => 10, 8 => 11, 9 => 11,
            10 => 12, 11 => 12,
        ];
        foreach ($map as $from => $to) {
            DB::table('tournament_matches')->where('id', $matchIds[$from])->update([
                'next_match_id' => $matchIds[$to]
            ]);
        }

        $inserted = TournamentMatch::where('pool_id', $poolId)
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        return response()->json([
            'message' => '✅ Bracket 10 peserta berhasil dibuat dengan parent match lengkap.',
            'rounds' => $inserted,
        ]);
    }

    private function generateSingleElimination($tournamentId, $poolId, $participants, $matchChart)
    {
        TournamentMatch::where('pool_id', $poolId)->delete();

        $pool = Pool::with('tournament')->find($poolId);
        if (!$pool) {
            return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
        }

        $usedParticipantIds = TournamentMatch::whereHas('pool', fn($q) =>
            $q->where('tournament_id', $tournamentId)
        )->pluck('participant_1')
        ->merge(
            TournamentMatch::whereHas('pool', fn($q) =>
                $q->where('tournament_id', $tournamentId)
            )->pluck('participant_2')
        )->unique();

        $participants = $participants->reject(fn($p) =>
            $usedParticipantIds->contains($p->id)
        )->values();

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'Semua peserta sudah masuk match di pool lain.'], 400);
        }

        $maxParticipantCount = (int) $matchChart;
        $selectedParticipants = $participants->slice(0, $maxParticipantCount)->values();
        $participantIds = $selectedParticipants->pluck('id')->toArray();

        TournamentParticipant::whereIn('team_member_id', $participantIds)
            ->where('tournament_id', $tournamentId)
            ->update(['pool_id' => $poolId]);

        $warning = null;
        if ($selectedParticipants->count() < $maxParticipantCount) {
            $warning = "Peserta tidak ideal: ditemukan " . $selectedParticipants->count() . " dari $maxParticipantCount.";
        }

        $now = now();
        $matchNumber = 1;
        $matches = collect();
        $totalPeserta = count($participantIds);

        // === 1 Peserta
        if ($totalPeserta === 1) {
            $matches->push([
                'pool_id' => $poolId,
                'round' => 1,
                'round_label' => 'Final',
                'match_number' => $matchNumber++,
                'participant_1' => $participantIds[0],
                'participant_2' => null,
                'winner_id' => $participantIds[0],
                'next_match_id' => null,
                'parent_match_red_id' => null,
                'parent_match_blue_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('tournament_matches')->insert($matches->toArray());

            return response()->json([
                'message' => '✅ Bracket 1 peserta: auto menang.',
                'warning' => $warning,
                'rounds' => TournamentMatch::where('pool_id', $poolId)
                    ->orderBy('round')->orderBy('match_number')->get(),
            ]);
        }

        // === 2 Peserta
        if ($totalPeserta === 2) {
            $matches->push([
                'pool_id' => $poolId,
                'round' => 1,
                'round_label' => 'Final',
                'match_number' => $matchNumber++,
                'participant_1' => $participantIds[0],
                'participant_2' => $participantIds[1],
                'winner_id' => null,
                'next_match_id' => null,
                'parent_match_red_id' => null,
                'parent_match_blue_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('tournament_matches')->insert($matches->toArray());

            return response()->json([
                'message' => '✅ Bracket 2 peserta: langsung final.',
                'warning' => $warning,
                'rounds' => TournamentMatch::where('pool_id', $poolId)
                    ->orderBy('round')->orderBy('match_number')->get(),
            ]);
        }

        // === Struktur Bracket
        $totalRounds = (int) log($matchChart, 2);
        for ($round = 1; $round <= $totalRounds; $round++) {
            $matchCount = $matchChart / pow(2, $round);
            $roundLabel = $this->getRoundLabel($round, $totalRounds);

            for ($i = 0; $i < $matchCount; $i++) {
                $matches->push([
                    'pool_id' => $poolId,
                    'round' => $round,
                    'round_label' => $roundLabel,
                    'match_number' => $matchNumber++,
                    'participant_1' => null,
                    'participant_2' => null,
                    'winner_id' => null,
                    'next_match_id' => null,
                    'parent_match_red_id' => null,
                    'parent_match_blue_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        DB::table('tournament_matches')->insert($matches->toArray());

        $allMatches = TournamentMatch::where('pool_id', $poolId)
            ->orderBy('round')->orderBy('match_number')->get();

        $byRound = $allMatches->groupBy('round');

        // === Linking parent-child match
        foreach ($byRound as $round => $roundMatches) {
            if (isset($byRound[$round + 1])) {
                $nextMatches = $byRound[$round + 1]->values();
                foreach ($roundMatches as $i => $match) {
                    $parentIndex = floor($i / 2);
                    $nextMatch = $nextMatches[$parentIndex] ?? null;

                    if ($nextMatch) {
                        $match->next_match_id = $nextMatch->id;
                        $match->save();

                        TournamentMatch::where('id', $nextMatch->id)->update([
                            $i % 2 === 0 ? 'parent_match_blue_id' : 'parent_match_red_id' => $match->id
                        ]);
                    }
                }
            }
        }

        // === Pairing Peserta di Round 1 (utamakan kontingen berbeda)
        $teamMembers = DB::table('team_members')->whereIn('id', $participantIds)->get()->keyBy('id');
        $used = [];
        $pairs = [];

        while (count($used) < $totalPeserta) {
            $p1 = null;
            $p2 = null;

            foreach ($participantIds as $id1) {
                if (in_array($id1, $used)) continue;
                $p1 = $id1;

                foreach ($participantIds as $id2) {
                    if (in_array($id2, $used) || $id2 === $id1) continue;
                    if ($teamMembers[$id1]->contingent_id !== $teamMembers[$id2]->contingent_id) {
                        $p2 = $id2;
                        break;
                    }
                }

                if (!$p2) {
                    foreach ($participantIds as $id2) {
                        if (!in_array($id2, $used) && $id2 !== $id1) {
                            $p2 = $id2;
                            break;
                        }
                    }
                }

                $used[] = $p1;
                if ($p2) $used[] = $p2;
                $pairs[] = [$p1, $p2];
                break;
            }
        }

        // === Assign ke Round 1
        $firstRoundMatches = $byRound[1]->values();
        foreach ($firstRoundMatches as $i => $match) {
            $pair = $pairs[$i] ?? [null, null];
            $match->participant_1 = $pair[0] ?? null;
            $match->participant_2 = $pair[1] ?? null;

            if ($match->participant_1 && !$match->participant_2) {
                $match->winner_id = $match->participant_1;
            }

            $match->save();
        }

        // === Special Case: 3 Peserta
        if (count($participantIds) === 3 && $totalRounds >= 2) {
            $finalRound = $byRound[$totalRounds]->first();
            $semiFinalMatches = $byRound[$totalRounds - 1] ?? collect();

            foreach ($semiFinalMatches as $match) {
                if ($match->participant_1 && !$match->participant_2) {
                    if (!$finalRound->participant_1) {
                        $finalRound->participant_1 = $match->participant_1;
                    } elseif (!$finalRound->participant_2) {
                        $finalRound->participant_2 = $match->participant_1;
                    }

                    $match->winner_id = $match->participant_1;
                    $match->save();
                    $finalRound->save();
                }
            }
        }

        return response()->json([
            'message' => '✅ Bracket eliminasi tunggal berhasil dibuat.',
            'warning' => $warning,
            'rounds' => TournamentMatch::where('pool_id', $poolId)
                ->orderBy('round')->orderBy('match_number')->get(),
        ]);
    }



    


   





    




    public function getMatches($poolId)
    {
        $pool = Pool::findOrFail($poolId);
        $matchChart = (int) $pool->match_chart;

        $matches = TournamentMatch::where('pool_id', $poolId)
            ->with(['participantOne.contingent', 'participantTwo.contingent', 'winner'])
            ->orderBy('round')
            ->orderBy('id')
            ->get();

        $groupedRounds = [];
        $allGamesEmpty = true;

        foreach ($matches as $match) {
            $round = $match->round;

            if (!isset($groupedRounds[$round])) {
                $groupedRounds[$round] = ['games' => []];
            }

            $player1 = $match->participantOne;
            $player2 = $match->participantTwo;
            $winner = $match->winner;

            $game = [
                'player1' => $player1 ? [
                    'id' => (string) $player1->id,
                    'name' => $player1->name,
                    'contingent' => $player1->contingent->name ?? '-', // ✅ nama kontingen
                    'winner' => $winner && $winner->id === $player1->id
                ] : [
                    'id' => null,
                    'name' => $round === 1 ? 'BYE' : 'TBD',
                    'contingent' => '-',
                    'winner' => false
                ],
                'player2' => $player2 ? [
                    'id' => (string) $player2->id,
                    'name' => $player2->name,
                    'contingent' => $player2->contingent->name ?? '-', // ✅ nama kontingen
                    'winner' => $winner && $winner->id === $player2->id
                ] : [
                    'id' => null,
                    'name' => $round === 1 ? 'BYE' : 'TBD',
                    'contingent' => '-',
                    'winner' => false
                ]
            ];

            if ($player1 || $player2) {
                $allGamesEmpty = false;
            }

            $groupedRounds[$round]['games'][] = $game;
        }

        ksort($groupedRounds);

        $rounds = array_values(array_map(function ($round) {
            return ['games' => $round['games']];
        }, $groupedRounds));

        return response()->json([
            'rounds' => $rounds,
            'match_chart' => $matchChart,
            'status' => $allGamesEmpty ? 'pending' : 'ongoing',
        ]);
    } 

    public function listMatches(Request $request, $tournamentId)
    {
        $query = TournamentMatch::with([
            'participantOne:id,name,contingent_id',
            'participantOne.contingent:id,name',
            'participantTwo:id,name,contingent_id',
            'participantTwo.contingent:id,name',
            'winner:id,name,contingent_id',
            'winner.contingent:id,name',
            'pool:id,name,tournament_id,category_class_id,match_category_id',
            'pool.categoryClass:id,name,age_category_id,gender',
            'pool.categoryClass.ageCategory:id,name',
            'pool.matchCategory:id,name',
        ])
        ->whereHas('pool', function ($q) use ($tournamentId) {
            $q->where('tournament_id', $tournamentId);
        });

        // ✅ Exclude scheduled match kalau tidak ada flag include_scheduled
        if (!$request->boolean('include_scheduled')) {
            $query->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('match_schedule_details')
                    ->whereColumn('match_schedule_details.tournament_match_id', 'tournament_matches.id');
            });
        }

        // ✅ Filter opsional
        if ($request->has('match_category_id')) {
            $query->whereHas('pool', function ($q) use ($request) {
                $q->where('match_category_id', $request->match_category_id);
            });
        }

        if ($request->has('age_category_id')) {
            $query->where('age_category_id', $request->age_category_id);
        }

        if ($request->has('category_class_id')) {
            $query->where('category_class_id', $request->category_class_id);
        }

        if ($request->has('pool_id')) {
            $query->where('pool_id', $request->pool_id);
        }

        // ✅ Ambil dan group match
        $matches = $query
            ->orderBy('pool_id')
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        $groupedMatches = $matches->groupBy('pool_id');

        $data = $groupedMatches->map(function ($matches, $poolId) {
            $firstMatch = $matches->first();
            $pool = $firstMatch->pool;

            $roundGroups = $matches->groupBy('round');

            $rounds = $roundGroups->map(function ($matchesInRound, $round) {
                $first = $matchesInRound->first();
                $roundLabel = $first->round_label;

                // ✅ Filter BYE match berdasarkan participant_1 / participant_2
                $filtered = $matchesInRound->filter(function ($match) use ($roundLabel) {
                    $isFirstRound = $match->round == 1;
                    $isNotFinal = strtolower(trim($roundLabel)) !== 'final';
                    $hasBye = is_null($match->participant_1) || is_null($match->participant_2);

                    return !($isFirstRound && $isNotFinal && $hasBye);
                });

                return [
                    'round' => (int) $round,
                    'round_label' => $roundLabel,
                    'matches' => $filtered->values()
                ];
            })->filter(function ($round) {
                return $round['matches']->isNotEmpty(); // ✅ Jangan kirim round kosong
            })->values();

            return [
                'pool_id' => $poolId,
                'pool_name' => $pool->name,
                'match_category_id' => $pool->matchCategory->id ?? null,
                'class_name' => $pool->categoryClass->name ?? '-',
                'age_category_id' => $pool->categoryClass->age_category_id ?? null,
                'age_category_name' => $pool->categoryClass->ageCategory->name ?? '-',
                'gender' => $pool->categoryClass->gender ?? null,
                'rounds' => $rounds
            ];
        })->values();

        return response()->json([
            'message' => 'List pertandingan berhasil diambil',
            'data' => $data
        ]);
    }


    public function listMatches_backup(Request $request, $tournamentId)
    {
        $query = TournamentMatch::with([
            'participantOne:id,name,contingent_id',
            'participantOne.contingent:id,name',
            'participantTwo:id,name,contingent_id',
            'participantTwo.contingent:id,name',
            'winner:id,name,contingent_id',
            'winner.contingent:id,name',
            'pool:id,name,tournament_id,category_class_id,match_category_id',
            'pool.categoryClass:id,name,age_category_id,gender',
            'pool.categoryClass.ageCategory:id,name', // ✅ ini buat ambil nama usia
            'pool.matchCategory:id,name',
        ])                
        ->whereHas('pool', function ($q) use ($tournamentId) {
            $q->where('tournament_id', $tournamentId);
        });

        // ✅ Exclude scheduled match kalau tidak ada flag include_scheduled
        if (!$request->boolean('include_scheduled')) {
            $query->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('match_schedule_details')
                    ->whereColumn('match_schedule_details.tournament_match_id', 'tournament_matches.id');
            });
        }

        // Filter lainnya tetap
        if ($request->has('match_category_id')) {
            $query->whereHas('pool', function ($q) use ($request) {
                $q->where('match_category_id', $request->match_category_id);
            });
        }

        if ($request->has('age_category_id')) {
            $query->where('age_category_id', $request->age_category_id);
        }

        if ($request->has('category_class_id')) {
            $query->where('category_class_id', $request->category_class_id);
        }

        if ($request->has('pool_id')) {
            $query->where('pool_id', $request->pool_id);
        }

        // Group dan return data tetap
        $matches = $query
            ->orderBy('pool_id')
            ->orderBy('round')
            ->orderBy('match_number')
            ->get();

        $groupedMatches = $matches->groupBy('pool_id');

        $data = $groupedMatches->map(function ($matches, $poolId) {
            $firstMatch = $matches->first();
            $pool = $firstMatch->pool;

            $roundGroups = $matches->groupBy('round');
            $totalRounds = $roundGroups->count();
           

           $rounds = $roundGroups->map(function ($matchesInRound, $round) {
                return [
                    'round' => (int) $round,
                    'round_label' => $matchesInRound->first()->round_label,
                    'matches' => $matchesInRound->values()
                ];
            })->values();

            
            Log::info([
                'class_id' => $pool->category_class_id,
                'age_id_from_pool' => $pool->categoryClass->age_category_id ?? null,
                'age_name_from_rel' => $pool->categoryClass->ageCategory->name ?? null,
            ]);

            
            return [
                'pool_id'    => $poolId,
                'pool_name'  => $pool->name,
                'match_category_id' => $pool->matchCategory->id ?? null,
                'class_name' => $pool->categoryClass->name ?? '-',
                'age_category_id' => $pool->categoryClass->age_category_id ?? null,
                'age_category_name' => $pool->categoryClass->ageCategory->name ?? '-',
                'gender' => $pool->categoryClass->gender ?? null,
                'rounds'     => $rounds
            ];
            
            
            
        })->values();

        return response()->json([
            'message' => 'List pertandingan berhasil diambil',
            'data' => $data
        ]);
    }


    
    private function getRoundLabels($totalRounds)
    {
        $labels = [];

        for ($i = 1; $i <= $totalRounds; $i++) {
            if ($totalRounds === 1) {
                $labels[$i] = "Final";
            } elseif ($totalRounds === 2) {
                $labels[$i] = $i === 1 ? "Semifinal" : "Final";
            } elseif ($totalRounds === 3) {
                $labels[$i] = $i === 1 ? "Perempat Final" : ($i === 2 ? "Semifinal" : "Final");
            } else {
                if ($i === 1) {
                    $labels[$i] = "Penyisihan";
                } elseif ($i === $totalRounds - 2) {
                    $labels[$i] = "Perempat Final";
                } elseif ($i === $totalRounds - 1) {
                    $labels[$i] = "Semifinal";
                } elseif ($i === $totalRounds) {
                    $labels[$i] = "Final";
                } else {
                    $labels[$i] = "Babak {$i}";
                }
            }
        }

        return $labels;
    }


    public function getAvailableRounds(Request $request, $tournamentId)
    {
        $rounds = \App\Models\TournamentMatch::query()
            ->select('tournament_matches.round', 'tournament_matches.round_label')
            ->join('pools', 'pools.id', '=', 'tournament_matches.pool_id')
            ->where('pools.tournament_id', $tournamentId)
            ->whereNotNull('tournament_matches.round_label')
            ->orderBy('tournament_matches.round') // ← boleh karena round disertakan di SELECT
            ->get();

        if ($rounds->isEmpty()) {
            return response()->json([]);
        }

        // Ambil unique label, dan jadikan key & value = round_label
        $filteredLabels = $rounds->unique('round_label')->mapWithKeys(function ($item) {
            return [$item->round_label => $item->round_label];
        });

        return response()->json([
            'rounds' => $filteredLabels
        ]);
    }



    
    private function addNextRounds($bracket, $winners)
    {
        $maxRounds = count($bracket);
        for ($round = 1; $round <= $maxRounds; $round++) {
            if (!isset($bracket[$round]) && isset($winners[$round])) {
                $nextRoundMatches = [];

                for ($i = 0; $i < count($winners[$round]); $i += 2) {
                    $participant1 = $winners[$round][$i] ?? "TBD";
                    $participant2 = $winners[$round][$i + 1] ?? "TBD";

                    $nextRoundMatches[] = [
                        'match_id' => "TBD",
                        'round' => $round,
                        'next_match_id' => $match->next_match_id,
                        'team_member_1_name' => $participant1 === "TBD" ? "TBD" : $this->getParticipantName($participant1),
                        'team_member_2_name' => $participant2 === "TBD" ? "TBD" : $this->getParticipantName($participant2),
                        'winner' => "TBD",
                    ];
                }
                $bracket[$round] = $nextRoundMatches;
            }
        }

        return $bracket;
    }

    public function allMatches(Request $request, $scheduleId)
    {
        $schedule = MatchSchedule::findOrFail($scheduleId);

        $tournamentId = $schedule->tournament_id;

        // Match yang sudah dijadwalkan dalam schedule ini
        $scheduledMatches = MatchScheduleDetail::where('match_schedule_id', $scheduleId)
            ->pluck('tournament_match_id')
            ->toArray();

        // Ambil semua match (baik yang sudah dijadwalkan di jadwal ini maupun yang belum pernah dijadwalkan)
        $query = TournamentMatch::with([
                'participantOne:id,name,contingent_id',
                'participantOne.contingent:id,name',
                'participantTwo:id,name,contingent_id',
                'participantTwo.contingent:id,name',
                'winner:id,name,contingent_id',
                'winner.contingent:id,name',
                'pool:id,name,tournament_id'
            ])
            ->whereHas('pool', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            })
            ->where(function ($q) use ($scheduledMatches) {
                $q->whereIn('id', $scheduledMatches) // match yang udah dijadwalkan di jadwal ini
                ->orWhereNotExists(function ($sub) {
                    $sub->select(DB::raw(1))
                        ->from('match_schedule_details')
                        ->whereColumn('match_schedule_details.tournament_match_id', 'tournament_matches.id');
                });
            });

        // Optional filters
        if ($request->has('match_category_id')) {
            $query->where('match_category_id', $request->match_category_id);
        }
        if ($request->has('age_category_id')) {
            $query->where('age_category_id', $request->age_category_id);
        }
        if ($request->has('category_class_id')) {
            $query->where('category_class_id', $request->category_class_id);
        }
        if ($request->has('pool_id')) {
            $query->where('pool_id', $request->pool_id);
        }

        $matches = $query->orderBy('round')->orderBy('match_number')->get();

        return response()->json([
            'message' => 'List pertandingan berhasil diambil (yang belum dijadwalkan + sudah ada di schedule ini).',
            'data' => $matches
        ]);
    }

    public function getNextPoolByTournament(Request $request)
    {
        $request->validate([
            'current_pool_id' => 'required|exists:pools,id',
        ]);

        // Ambil pool sekarang
        $currentPool = Pool::findOrFail($request->current_pool_id);

        // Ambil semua pool dalam turnamen yang sama
        $pools = Pool::where('tournament_id', $currentPool->tournament_id)
            ->orderBy('id') // ganti ke 'order' kalau ada urutan custom
            ->select('id', 'name', 'tournament_id')
            ->get()
            ->values();

        // Cari index pool sekarang
        $currentIndex = $pools->search(fn($pool) => $pool->id == $currentPool->id);

        if ($currentIndex === false || $currentIndex + 1 >= $pools->count()) {
            return response()->json(['message' => 'Tidak ada pool selanjutnya'], 404);
        }

        // Return pool berikutnya
        return response()->json($pools[$currentIndex + 1]);
    }




    private function formatBracketForVue($bracket)
    {
        $formattedBracket = [];

        foreach ($bracket as $round => $matches) {
            $formattedMatches = [];

            foreach ($matches as $match) {
                $formattedMatches[] = [
                    'id' => $match['match_id'],
                    'next' => $match['next_match_id'],
                    'player1' => [
                        'id' => $match['player1']['id'],
                        'name' => $match['player1']['name'],
                        'winner' => $match['player1']['winner'],
                    ],
                    'player2' => [
                        'id' => $match['player2']['id'],
                        'name' => $match['player2']['name'],
                        'winner' => $match['player2']['winner'],
                    ],
                ];
            }

            $formattedBracket[] = [
                'round' => $round,
                'matches' => $formattedMatches,
            ];
        }

        return $formattedBracket;
    }

    private function buildFinalBracket($winners)
    {
        $finalParticipants = array_slice($winners, -2);

        return [
            'final_match' => [
                'participants' => $finalParticipants
            ]
        ];
    }

    private function getParticipantName($participantId)
    {
        if ($participantId === "TBD") return "TBD";

        $participant = TournamentParticipant::find($participantId);
        return $participant ? $participant->name : "TBD";
    }



}
