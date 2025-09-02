<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Pool;
use App\Models\TournamentMatch;
use App\Models\TournamentParticipant;
use App\Models\MatchSchedule;
use App\Models\MatchScheduleDetail;
use App\Models\TeamMember;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Faker\Factory as Faker;
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




    


    

    private function generateSingleRoundBracket($poolId)
    {
        return DB::transaction(function () use ($poolId) {
            TournamentMatch::where('pool_id', $poolId)->delete();

            $pool = Pool::find($poolId);
            if (!$pool) {
                return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
            }

            $matchCategoryId = $pool->match_category_id;
            if (!$matchCategoryId) {
                return response()->json(['message' => 'Match Category ID tidak ditemukan di pool.'], 400);
            }

            // Ambil peserta eligible sesuai scope pool
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
                    'team_members.name',
                    'team_members.category_class_id',
                    'team_members.gender',
                    'team_members.contingent_id'
                )
                ->get();

            if ($participants->isEmpty()) {
                return response()->json(['message' => 'Tidak ada peserta valid untuk pool ini.'], 400);
            }

            // --- util: bikin dummy opponent tersinkron dengan aturan Full Prestasi ---
            $dummyContingentPool = [310, 311, 312, 313, 314, 315];

            $makeDummyFor = function ($templateTmId, $gender = null) use ($pool, $matchCategoryId, $dummyContingentPool) {
                // pastikan contingent dummy ada
                if (method_exists($this, 'ensureContingentsExist')) {
                    $this->ensureContingentsExist($dummyContingentPool, $pool->tournament_id);
                }

                $template = \App\Models\TeamMember::find($templateTmId);
                if (!$template) {
                    // fallback: cari template by scope
                    $template = \App\Models\TeamMember::query()
                        ->where('category_class_id', $pool->category_class_id)
                        ->where('match_category_id', $matchCategoryId)
                        ->when(Schema::hasColumn('team_members', 'gender') && $gender, fn($q) => $q->where('gender', $gender))
                        ->first();
                }
                $chosenContingent = $dummyContingentPool[array_rand($dummyContingentPool)];

                // kalau ada helper createDummyTeamMemberAndRegister, gunakan itu
                if (method_exists($this, 'createDummyTeamMemberAndRegister')) {
                    $this->createDummyTeamMemberAndRegister(
                        $template,
                        $chosenContingent,
                        Schema::hasColumn('team_members', 'gender') ? ($gender ?? $template->gender) : null,
                        $pool->tournament_id,
                        $pool->id,
                        [
                            'match_category_id' => $matchCategoryId,
                            'category_class_id' => $pool->category_class_id,
                            // 'age_category_id' => $pool->age_category_id ?? $template->age_category_id,
                        ]
                    );

                    // ambil id team_member dummy terbaru di pool ini & contingent dummy
                    $dummyTmId = DB::table('tournament_participants as tp')
                        ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                        ->where('tp.tournament_id', $pool->tournament_id)
                        ->where('tp.pool_id', $pool->id)
                        ->where('tm.category_class_id', $pool->category_class_id)
                        ->where('tm.match_category_id', $matchCategoryId)
                        ->where('tm.contingent_id', $chosenContingent)
                        ->orderByDesc('tp.id')
                        ->value('tm.id');

                    return $dummyTmId;
                }

                // --- fallback inline (kalau helper tidak tersedia) ---
                $dummyName = 'Dummy ' . ($template?->name ? '(' . $template->name . ')' : strtoupper(uniqid()));
                $dummyGender = Schema::hasColumn('team_members', 'gender') ? ($gender ?? $template?->gender ?? 'male') : null;

                $dummyTmId = DB::table('team_members')->insertGetId(array_filter([
                    'name'                   => $dummyName,
                    'contingent_id'          => $chosenContingent,
                    'gender'                 => $dummyGender,
                    'championship_category_id' => $template?->championship_category_id,
                    'age_category_id'        => $pool->age_category_id ?? $template?->age_category_id,
                    'category_class_id'      => $pool->category_class_id,
                    'match_category_id'      => $matchCategoryId,
                    'is_dummy'               => Schema::hasColumn('team_members','is_dummy') ? 1 : null,
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]));

                // register ke tournament_participants & assign ke pool
                DB::table('tournament_participants')->insert([
                    'tournament_id'  => $pool->tournament_id,
                    'team_member_id' => $dummyTmId,
                    'pool_id'        => $pool->id,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                return $dummyTmId;
            };

            $matches = collect();
            $matchNumber = 1;

            $grouped = $participants->groupBy(function ($p) {
                return ($p->category_class_id ?? 'null') . '-' . ($p->gender ?? 'null');
            });

            foreach ($grouped as $group) {
                // shuffle biar pairing acak, tetap per class & gender
                $queue = $group->shuffle()->values();

                // SPECIAL: kalau dalam 1 grup cuma ada 1 peserta → buat dummy dulu
                if ($queue->count() === 1) {
                    $p1 = $queue->first();
                    $dummyId = $makeDummyFor($p1->id, $p1->gender ?? null);

                    // Dorong dummy ke antrian biar dipair
                    if ($dummyId) {
                        $queue->push((object)[
                            'id' => $dummyId,
                            'category_class_id' => $p1->category_class_id,
                            'gender' => $p1->gender ?? null,
                            'contingent_id' => null, // nggak kepake untuk pairing langsung
                        ]);
                    }
                }

                while ($queue->count() > 0) {
                    $p1 = $queue->shift();

                    // 1) Cari lawan dari kontingen berbeda
                    $opponentIndex = $queue->search(fn($p2) =>
                        ($p2->contingent_id ?? null) !== ($p1->contingent_id ?? null) &&
                        $p2->id !== $p1->id
                    );

                    // 2) Kalau tidak ada, cari lawan dari kontingen yang sama
                    if ($opponentIndex === false) {
                        $opponentIndex = $queue->search(fn($p2) => $p2->id !== $p1->id);
                    }

                    $p2 = $opponentIndex !== false ? $queue->pull($opponentIndex) : null;

                    // 3) Kalau tetap tidak ada lawan (ganjil) → buat dummy di sini
                    if (!$p2) {
                        $dummyId = $makeDummyFor($p1->id, $p1->gender ?? null);
                        if ($dummyId) {
                            $p2 = (object)[
                                'id' => $dummyId,
                                'category_class_id' => $p1->category_class_id,
                                'gender' => $p1->gender ?? null,
                                'contingent_id' => null,
                            ];
                        }
                    }

                    // Safety: kalau setelah semua usaha p2 tetap null, baru auto-win (harusnya jarang)
                    $matches->push([
                        'pool_id'       => $poolId,
                        'round'         => 1,
                        'round_label'   => $this->getRoundLabel(1, 1),
                        'match_number'  => $matchNumber++,
                        'participant_1' => $p1->id,
                        'participant_2' => $p2?->id,
                        'winner_id'     => $p2 ? null : $p1->id, // kalau ada dummy, biarkan kosong (akan bertanding)
                        'next_match_id' => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ]);
                }
            }

            TournamentMatch::insert($matches->toArray());

            return response()->json([
                'message'           => 'Bracket berhasil dibuat (single round) dengan dummy opponent bila diperlukan.',
                'total_participant' => $participants->count(),
                'total_match'       => $matches->count(),
                'matches'           => $matches,
            ]);
        });
    }



    
    

   private function generateBracketForSix($poolId, $participants)
    {
        return DB::transaction(function () use ($poolId, $participants) {
            TournamentMatch::where('pool_id', $poolId)->delete();

            $pool = Pool::with('tournament')->find($poolId);
            if (!$pool) {
                return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
            }

            // --- util: bikin dummy opponent konsisten dengan Full Prestasi ---
            $dummyContingentPool = [310, 311, 312, 313, 314, 315];

            $makeDummyFor = function ($templateTmId, $gender = null) use ($pool, $dummyContingentPool) {
                if (method_exists($this, 'ensureContingentsExist')) {
                    $this->ensureContingentsExist($dummyContingentPool, $pool->tournament_id);
                }

                $template = \App\Models\TeamMember::find($templateTmId);
                if (!$template) {
                    $template = \App\Models\TeamMember::query()
                        ->where('category_class_id', $pool->category_class_id)
                        ->where('match_category_id', $pool->match_category_id)
                        ->when(Schema::hasColumn('team_members', 'gender') && $gender, fn($q) => $q->where('gender', $gender))
                        ->first();
                }
                $chosenContingent = $dummyContingentPool[array_rand($dummyContingentPool)];

                if (method_exists($this, 'createDummyTeamMemberAndRegister')) {
                    $this->createDummyTeamMemberAndRegister(
                        $template,
                        $chosenContingent,
                        Schema::hasColumn('team_members', 'gender') ? ($gender ?? $template->gender) : null,
                        $pool->tournament_id,
                        $pool->id,
                        [
                            'match_category_id' => $pool->match_category_id,
                            'category_class_id' => $pool->category_class_id,
                        ]
                    );

                    return DB::table('tournament_participants as tp')
                        ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                        ->where('tp.tournament_id', $pool->tournament_id)
                        ->where('tp.pool_id', $pool->id)
                        ->where('tm.category_class_id', $pool->category_class_id)
                        ->where('tm.match_category_id', $pool->match_category_id)
                        ->where('tm.contingent_id', $chosenContingent)
                        ->orderByDesc('tp.id')
                        ->value('tm.id');
                }

                // --- fallback inline ---
                $dummyName   = 'Dummy ' . ($template?->name ? '(' . $template->name . ')' : strtoupper(uniqid()));
                $dummyGender = Schema::hasColumn('team_members', 'gender') ? ($gender ?? $template?->gender ?? 'male') : null;

                $dummyTmId = DB::table('team_members')->insertGetId(array_filter([
                    'name'                     => $dummyName,
                    'contingent_id'            => $chosenContingent,
                    'gender'                   => $dummyGender,
                    'championship_category_id' => $template?->championship_category_id,
                    'age_category_id'          => $pool->age_category_id ?? $template?->age_category_id,
                    'category_class_id'        => $pool->category_class_id,
                    'match_category_id'        => $pool->match_category_id,
                    'is_dummy'                 => Schema::hasColumn('team_members','is_dummy') ? 1 : null,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]));

                DB::table('tournament_participants')->insert([
                    'tournament_id'  => $pool->tournament_id,
                    'team_member_id' => $dummyTmId,
                    'pool_id'        => $pool->id,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                return $dummyTmId;
            };

            // Hindari peserta yang sudah dipakai di pool lain di turnamen yang sama
            $usedParticipantIds = TournamentMatch::whereHas('pool', fn($q) =>
                $q->where('tournament_id', $pool->tournament_id)
            )->pluck('participant_1')
            ->merge(
                TournamentMatch::whereHas('pool', fn($q) =>
                    $q->where('tournament_id', $pool->tournament_id)
                )->pluck('participant_2')
            )->unique();

            $participants = $participants->reject(fn($p) => $usedParticipantIds->contains($p->id))->values();

            // Ambil maksimal 6 untuk pool ini
            $selected       = $participants->slice(0, 6)->values();
            $participantIds = $selected->pluck('id')->toArray();

            // Assign ke pool ini
            TournamentParticipant::whereIn('team_member_id', $participantIds)
                ->where('tournament_id', $pool->tournament_id)
                ->update(['pool_id' => $poolId]);

            // === HANYA buat dummy kalau pool ini isinya TEPAT 1 peserta ===
            if ($selected->count() === 1) {
                $tpl = $selected->first();
                $dummyId = $makeDummyFor($tpl->id, $tpl->gender ?? null);
                if ($dummyId) {
                    $selected->push((object)[
                        'id' => $dummyId,
                        'gender' => $tpl->gender ?? null,
                        'category_class_id' => $pool->category_class_id,
                    ]);
                    $participantIds = $selected->pluck('id')->toArray();
                }
            }

            // Handle khusus 5 & 6 peserta (TIDAK bikin dummy)
            if ($selected->count() === 5) {
                return $this->generateBracketForFive($poolId, $selected);
            }

            if ($selected->count() === 6) {
                $rounds = $this->generateDefaultSix($poolId, $selected);
                return response()->json([
                    'message' => 'Bracket untuk 6 peserta berhasil dibuat.',
                    'rounds'  => $rounds,
                ]);
            }

            // ========== Generic builder untuk jumlah selain 5/6 ==========
            $matchNumber = 1;
            $matchMap = [];
            $queue = $selected->pluck('id')->toArray();

            // Slot nearest power of two
            $slot     = pow(2, ceil(log(max(count($queue), 2), 2)));
            $maxRound = (int) ceil(log($slot, 2));
            $byeCount = $slot - count($queue);

            // BYE hanya untuk menyesuaikan slot power-of-two (bukan karena sisa 1; itu sudah ditangani di atas)
            for ($i = 0; $i < $byeCount; $i++) {
                $queue[] = null;
            }

            // ROUND 1
            for ($i = 0; $i < count($queue); $i += 2) {
                $p1 = $queue[$i] ?? null;
                $p2 = $queue[$i + 1] ?? null;

                $winner = ($p1 && !$p2) ? $p1 : (($p2 && !$p1) ? $p2 : null);

                $matchId = DB::table('tournament_matches')->insertGetId([
                    'pool_id'       => $poolId,
                    'round'         => 1,
                    'round_label'   => $this->getRoundLabel(1, $maxRound),
                    'match_number'  => $matchNumber++,
                    'participant_1' => $p1,
                    'participant_2' => $p2,
                    'winner_id'     => $winner,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ]);

                $matchMap[] = [
                    'id'     => $matchId,
                    'winner' => $winner,
                ];
            }

            // ROUNDS 2+
            while (count($matchMap) > 1) {
                $nextRound    = [];
                $currentRound = ceil(log($slot, 2)) - ceil(log(count($matchMap), 2)) + 1;

                foreach (array_chunk($matchMap, 2) as $pair) {
                    $blue = $pair[0];
                    $red  = $pair[1] ?? ['id' => null, 'winner' => null];

                    $matchId = DB::table('tournament_matches')->insertGetId([
                        'pool_id'              => $poolId,
                        'round'                => $currentRound,
                        'round_label'          => $this->getRoundLabel($currentRound, $maxRound),
                        'match_number'         => $matchNumber++,
                        'participant_1'        => $blue['winner'],
                        'participant_2'        => $red['winner'],
                        'winner_id'            => null,
                        'parent_match_blue_id' => $blue['id'],
                        'parent_match_red_id'  => $red['id'],
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ]);

                    if ($blue['id']) {
                        DB::table('tournament_matches')->where('id', $blue['id'])->update(['next_match_id' => $matchId]);
                    }
                    if ($red['id']) {
                        DB::table('tournament_matches')->where('id', $red['id'])->update(['next_match_id' => $matchId]);
                    }

                    $nextRound[] = [
                        'id'     => $matchId,
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
                'message' => 'Bracket untuk ' . $selected->count() . ' peserta berhasil dibuat (dummy hanya untuk pool 1 peserta).',
                'rounds'  => $inserted,
            ]);
        });
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

    




    private function generateFullPrestasiBracket($poolId, $participants = null)
    {
        return DB::transaction(function () use ($poolId, $participants) {
            // Bersihkan match lama di pool ini
            TournamentMatch::where('pool_id', $poolId)->delete();

            $pool = Pool::with('tournament')->find($poolId);
            if (!$pool) {
                return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
            }

            $tournamentId            = $pool->tournament_id;
            $desiredClassId          = $pool->category_class_id;
            $desiredMatchCategoryId  = $pool->match_category_id;

            if (!$desiredClassId || !$desiredMatchCategoryId) {
                return response()->json(['message' => 'Pool tidak memiliki kelas atau kategori pertandingan.'], 400);
            }

            // Gender detection (kalau kolom gender ada)
            $genderColumnExists = Schema::hasColumn('team_members', 'gender');
            $genderFilter = null;

            if ($genderColumnExists) {
                $genderFilter = DB::table('tournament_participants as tp')
                    ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                    ->where('tp.pool_id', $poolId)
                    ->where('tp.tournament_id', $tournamentId)
                    ->where('tm.category_class_id', $desiredClassId)
                    ->where('tm.match_category_id', $desiredMatchCategoryId)
                    ->value('tm.gender');

                if (!$genderFilter) {
                    $genderFilter = DB::table('tournament_participants as tp')
                        ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                        ->where('tp.tournament_id', $tournamentId)
                        ->where('tm.category_class_id', $desiredClassId)
                        ->where('tm.match_category_id', $desiredMatchCategoryId)
                        ->value('tm.gender');
                }
                if (!$genderFilter) {
                    $genderFilter = 'male';
                }
            }

            // Ambil semua peserta eligible (tanpa pembagian pool)
            $eligible = DB::table('tournament_participants as tp')
                ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                ->where('tp.tournament_id', $tournamentId)
                ->where('tm.category_class_id', $desiredClassId)
                ->where('tm.match_category_id', $desiredMatchCategoryId)
                ->when($genderColumnExists, fn($q) => $q->where('tm.gender', $genderFilter))
                ->select(
                    'tp.id as tp_id','tm.id as tm_id','tm.name','tm.contingent_id','tm.gender',
                    'tm.championship_category_id','tm.age_category_id','tm.category_class_id','tm.match_category_id'
                )
                ->get();

            // Batasi ke $participants (array team_member_id) bila diberikan
            if (is_array($participants) && count($participants) > 0) {
                $eligible = DB::table('tournament_participants as tp')
                    ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                    ->where('tp.tournament_id', $tournamentId)
                    ->whereIn('tm.id', $participants)
                    ->where('tm.category_class_id', $desiredClassId)
                    ->where('tm.match_category_id', $desiredMatchCategoryId)
                    ->when($genderColumnExists, fn($q) => $q->where('tm.gender', $genderFilter))
                    ->select(
                        'tp.id as tp_id','tm.id as tm_id','tm.name','tm.contingent_id','tm.gender',
                        'tm.championship_category_id','tm.age_category_id','tm.category_class_id','tm.match_category_id'
                    )
                    ->get();
            }

            $currentCount   = $eligible->count();
            $addedDummies   = 0;
            $contingentPool = [310, 311, 312, 313, 314, 315];

            // Pastikan contingent dummy Full Prestasi tersedia (hindari FK error)
            $this->ensureContingentsExist($contingentPool, $tournamentId);

            // Template original untuk copy field saat bikin dummy
            $templateOriginal = null;
            if ($currentCount > 0) {
                $templateOriginal = TeamMember::find($eligible->first()->tm_id);
            } else {
                $templateOriginal = TeamMember::query()
                    ->where('category_class_id', $desiredClassId)
                    ->where('match_category_id',  $desiredMatchCategoryId)
                    ->when($genderColumnExists, fn($q) => $q->where('gender', $genderFilter))
                    ->first();
            }

            // Aturan PB IPSI: minimal 6 peserta → tambah dummy sampai 6
            if ($currentCount < 6) {
                $needed = 6 - $currentCount;

                for ($i = 0; $i < $needed; $i++) {
                    $contingentId = $contingentPool[$i % count($contingentPool)];

                    $this->createDummyTeamMemberAndRegister(
                        $templateOriginal,
                        $contingentId,
                        $genderColumnExists ? $genderFilter : null,
                        $tournamentId,
                        $poolId,
                        [
                            'match_category_id' => $desiredMatchCategoryId,
                            'category_class_id' => $desiredClassId,
                            // jika ada kolom age di pool, set di sini:
                            // 'age_category_id' => $pool->age_category_id ?? ($templateOriginal->age_category_id ?? null),
                        ]
                    );

                    $addedDummies++;
                }

                // refresh eligible setelah tambah dummy
                $eligible = DB::table('tournament_participants as tp')
                    ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                    ->where('tp.tournament_id', $tournamentId)
                    ->where('tm.category_class_id', $desiredClassId)
                    ->where('tm.match_category_id', $desiredMatchCategoryId)
                    ->when($genderColumnExists, fn($q) => $q->where('tm.gender', $genderFilter))
                    ->select('tp.id as tp_id','tm.id as tm_id','tm.name','tm.contingent_id','tm.gender')
                    ->get();

                $currentCount = $eligible->count();
            }

            // Pastikan semua eligible di-assign ke pool tunggal ini
            $tpIdsAll = $eligible->pluck('tp_id')->all();
            if (!empty($tpIdsAll)) {
                DB::table('tournament_participants')->whereIn('id', $tpIdsAll)->update(['pool_id' => $poolId]);
            }

            // Build bracket single-elim
            $participantIds = $eligible->pluck('tm_id')->shuffle()->values();
            $total          = $participantIds->count();

            if ($total === 0) {
                return response()->json(['message' => 'Tidak ada peserta untuk Full Prestasi.'], 400);
            }

            if ($total === 1) {
                DB::table('tournament_matches')->insert([
                    'pool_id'        => $poolId,
                    'round'          => 1,
                    'round_label'    => 'Final',
                    'match_number'   => 1,
                    'participant_1'  => $participantIds[0],
                    'participant_2'  => null,
                    'winner_id'      => $participantIds[0],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                return response()->json([
                    'message'            => 'Bracket untuk 1 peserta berhasil dibuat.',
                    'total_participants' => $total,
                    'bracket_size'       => 1,
                    'total_matches'      => 1,
                    'rounds_generated'   => 1,
                    'added_dummies'      => $addedDummies,
                    'gender'             => $genderColumnExists ? $genderFilter : null,
                ]);
            }

            $bracketSize        = (int) pow(2, ceil(log($total, 2)));
            $maxRound           = (int) ceil(log($bracketSize, 2));
            $preliminaryMatches = max(0, $total - ($bracketSize / 2));
            $roundMatchCounts   = [];
            $matchNumber        = 1;
            $matches            = [];

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
                $roundMatchCounts[$round] = (int) ($bracketSize / pow(2, $round));
            }

            // Buat slot match
            for ($round = 1; $round <= $maxRound; $round++) {
                for ($i = 0; $i < $roundMatchCounts[$round]; $i++) {
                    $matches[] = [
                        'pool_id'              => $poolId,
                        'round'                => $round,
                        'round_label'          => $getLabel($round),
                        'match_number'         => $matchNumber++,
                        'participant_1'        => null,
                        'participant_2'        => null,
                        'winner_id'            => null,
                        'next_match_id'        => null,
                        'parent_match_red_id'  => null,
                        'parent_match_blue_id' => null,
                        'created_at'           => now(),
                        'updated_at'           => now(),
                    ];
                }
            }

            // === Pairing preliminary: HINDARI dummy vs dummy, prefer beda kontingen ===
            $firstRoundIdx = array_keys(array_filter($matches, fn($m) => $m['round'] === 1));

            $isDummyCol = Schema::hasColumn('team_members', 'is_dummy');
            $tmMeta = DB::table('team_members')
                ->whereIn('id', $participantIds)
                ->select(array_filter([
                    'id',
                    'contingent_id',
                    $isDummyCol ? 'is_dummy' : null,
                ]))
                ->get()
                ->keyBy('id');

            $dummyContingentIds = [310, 311, 312, 313, 314, 315];
            $isDummy = function ($id) use ($tmMeta, $isDummyCol, $dummyContingentIds) {
                $tm = $tmMeta[$id] ?? null;
                if (!$tm) return false;
                if ($isDummyCol) return (bool) $tm->is_dummy;
                return in_array($tm->contingent_id, $dummyContingentIds, true);
            };

            $ids = $participantIds->all();
            $unusedReals   = array_values(array_filter($ids, fn($id) => !$isDummy($id)));
            $unusedDummies = array_values(array_filter($ids, fn($id) =>  $isDummy($id)));

            $pairings = [];
            $used = [];

            $pickPartner = function ($candidateId, array &$pool, $preferDifferentContingent = true) use ($tmMeta) {
                $cid = $tmMeta[$candidateId]->contingent_id ?? null;
                if ($preferDifferentContingent) {
                    foreach ($pool as $k => $pid) {
                        if (($tmMeta[$pid]->contingent_id ?? null) !== $cid) {
                            $partner = $pid;
                            unset($pool[$k]);
                            $pool = array_values($pool);
                            return $partner;
                        }
                    }
                }
                if (!empty($pool)) {
                    $partner = array_shift($pool);
                    return $partner;
                }
                return null;
            };

            // Urutan prioritas: Real vs Dummy → Real vs Real → Dummy vs Dummy (terakhir)
            while (count($pairings) < $preliminaryMatches) {
                $p1 = null; $p2 = null;

                // 1) Real vs Dummy
                if (!empty($unusedReals) && !empty($unusedDummies)) {
                    $p1 = array_shift($unusedReals);
                    $p2 = $pickPartner($p1, $unusedDummies, true);
                    if (!$p2) {
                        array_unshift($unusedReals, $p1);
                        $p1 = $p2 = null;
                    }
                }

                // 2) Real vs Real
                if ((!$p1 || !$p2) && count($unusedReals) >= 2) {
                    $p1 = array_shift($unusedReals);
                    $p2 = $pickPartner($p1, $unusedReals, true);
                }

                // 3) Dummy vs Dummy (unavoidable)
                if ((!$p1 || !$p2) && count($unusedDummies) >= 2) {
                    $p1 = array_shift($unusedDummies);
                    $p2 = $pickPartner($p1, $unusedDummies, true);
                }

                if ($p1 && $p2) {
                    $pairings[] = [$p1, $p2];
                    $used[] = $p1; $used[] = $p2;
                } else {
                    break; // safety
                }
            }

            // Isi preliminary matches
            for ($j = 0; $j < $preliminaryMatches; $j++) {
                $idx  = $firstRoundIdx[$j];
                $pair = $pairings[$j] ?? [null, null];

                $matches[$idx]['participant_1'] = $pair[0];
                $matches[$idx]['participant_2'] = $pair[1];
            }

            // BYE → prioritas peserta NON-DUMMY
            $remainingIds = array_values(array_diff($ids, $used));
            usort($remainingIds, function ($a, $b) use ($isDummy) {
                // false(0) < true(1) → real dulu
                return ($isDummy($a) <=> $isDummy($b));
            });
            $byeTargets = array_slice($firstRoundIdx, $preliminaryMatches);
            foreach ($byeTargets as $idx) {
                $id = array_shift($remainingIds);
                if (!$id) break;
                $matches[$idx]['participant_1'] = $id;
                $matches[$idx]['winner_id']     = $id;
            }

            // Simpan match
            DB::table('tournament_matches')->insert($matches);

            // Link parent-child & propagate winner BYE
            $matchMap    = TournamentMatch::where('pool_id', $poolId)->orderBy('match_number')->get();
            $roundGroups = $matchMap->groupBy('round');

            foreach ($roundGroups as $round => $matchesInRound) {
                if ($round >= $maxRound) continue;

                $nextRoundIndexed = ($roundGroups[$round + 1] ?? collect())->values();

                foreach ($matchesInRound->values() as $i => $match) {
                    $targetIndex = (int) floor($i / 2);
                    $nextMatch   = $nextRoundIndexed->get($targetIndex);
                    if (!$nextMatch) continue;

                    $match->next_match_id = $nextMatch->id;
                    $match->save();

                    if ($i % 2 === 0) {
                        TournamentMatch::where('id', $nextMatch->id)->update(['parent_match_blue_id' => $match->id]);
                    } else {
                        TournamentMatch::where('id', $nextMatch->id)->update(['parent_match_red_id' => $match->id]);
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

            return response()->json([
                'message'            => 'Bracket Full Prestasi berhasil dibuat (tanpa pembagian pool). Peserta < 6 diisi dummy hingga 6. Pairing menghindari dummy vs dummy.',
                'total_participants' => $total,
                'bracket_size'       => $bracketSize,
                'total_matches'      => $matchMap->count(),
                'rounds_generated'   => $maxRound,
                'added_dummies'      => $addedDummies,
                'gender'             => $genderColumnExists ? $genderFilter : null,
            ]);
        });
    }

    /** =================== HELPER & UTIL =================== **/

    // Buat TeamMember dummy + daftarin ke TP (pool tertentu)
    private function createDummyTeamMemberAndRegister(
        ?TeamMember $original,
        int $contingentId,
        ?string $gender,
        int $tournamentId,
        int $poolId,
        array $overrides = []
    ): TeamMember {
        $payload = $this->buildDummyTeamMemberPayload($original, $contingentId, $gender, $overrides);

        $dummy = new TeamMember();
        $dummy->forceFill($payload)->save();

        TournamentParticipant::create([
            'tournament_id'  => $tournamentId,
            'team_member_id' => $dummy->id,
            'pool_id'        => $poolId,
        ]);

        return $dummy;
    }

    // Payload dummy TeamMember (pakai Faker id_ID) + perisai kolom NOT NULL
    private function buildDummyTeamMemberPayload(?TeamMember $original, int $contingentId, ?string $gender = null, array $overrides = []): array
    {
        $faker = Faker::create('id_ID');

        $g = $gender ?: ($original->gender ?? 'male');
        $first = $g === 'female' ? $faker->firstNameFemale : $faker->firstNameMale;
        $last  = $faker->lastName;

        $payload = [
            'contingent_id'            => $contingentId,
            'name'                     => $first.' '.$last,
            'birth_place'              => $faker->city,
            'birth_date'               => $faker->date(),
            'gender'                   => $g,
            'body_weight'              => 70,
            'body_height'              => 170,
            'blood_type'               => 'O',
            'nik'                      => $faker->numerify('###############'),
            'family_card_number'       => $faker->numerify('###############'),
            'country_id'               => 103,
            'province_id'              => 32,
            'district_id'              => 3217,
            'subdistrict_id'           => 321714,
            'ward_id'                  => 3217142009,
            'address'                  => $faker->address,
            'championship_category_id' => $original->championship_category_id ?? null,
            'match_category_id'        => $original->match_category_id ?? null,
            'age_category_id'          => $original->age_category_id ?? null,
            'category_class_id'        => $original->category_class_id ?? null,
            'registration_status'      => 'approved',
            'is_dummy'                 => true,
            'created_at'               => now(),
            'updated_at'               => now(),
        ];

        $payload = array_merge($payload, $overrides);

        // Perisai NOT NULL khusus team_members
        $payload = $this->withTeamMemberDefaults($payload);

        return $payload;
    }

    // Pastikan contingent (310–315) ada → hindari FK error
    private function ensureContingentsExist(array $ids, ?int $tournamentId = null): void
    {
        $now = now();

        foreach ($ids as $cid) {
            $exists = DB::table('contingents')->where('id', $cid)->exists();
            if ($exists) continue;

            $data = [
                'id'         => $cid,
                'name'       => 'Dummy Contingent '.$cid,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (Schema::hasColumn('contingents', 'slug')) {
                $data['slug'] = 'dummy-contingent-'.$cid;
            }
            if ($tournamentId && Schema::hasColumn('contingents', 'tournament_id')) {
                $data['tournament_id'] = $tournamentId;
            }

            // Perisai NOT NULL lainnya pada tabel contingents
            $data = $this->withTableDefaults('contingents', $data, []);

            DB::table('contingents')->insertOrIgnore($data);
        }
    }

    // Perisai generic untuk tabel apa pun (skip kolom tertentu)
    private function withTableDefaults(string $table, array $data, array $exclude = []): array
    {
        $exclude = array_values(array_unique(array_merge($exclude, ['id', 'created_at', 'updated_at'])));
        try {
            $cols = DB::table('information_schema.COLUMNS')
                ->select('COLUMN_NAME','DATA_TYPE','IS_NULLABLE','COLUMN_DEFAULT','COLUMN_TYPE')
                ->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->get();

            foreach ($cols as $col) {
                $name = $col->COLUMN_NAME;
                if (in_array($name, $exclude, true)) continue;
                if (array_key_exists($name, $data)) continue;

                if ($col->IS_NULLABLE === 'NO' && is_null($col->COLUMN_DEFAULT)) {
                    $data[$name] = $this->defaultValueForType($col->DATA_TYPE, $col->COLUMN_TYPE);
                }
            }
        } catch (\Throwable $e) { /* ignore */ }

        return $data;
    }

    // Perisai khusus team_members (pakai generic shield juga)
    private function withTeamMemberDefaults(array $data): array
    {
        if (Schema::hasColumn('team_members', 'birth_place') && !array_key_exists('birth_place', $data)) {
            $data['birth_place'] = 'Jakarta';
        }
        if (Schema::hasColumn('team_members', 'birth_date') && !array_key_exists('birth_date', $data)) {
            $data['birth_date'] = '2000-01-01';
        }
        if (Schema::hasColumn('team_members', 'is_dummy') && !array_key_exists('is_dummy', $data)) {
            $data['is_dummy'] = 1;
        }

        return $this->withTableDefaults('team_members', $data, []);
    }

    private function defaultValueForType(string $type, ?string $columnType = null)
    {
        switch (strtolower($type)) {
            case 'int': case 'bigint': case 'smallint': case 'mediumint': case 'tinyint':
            case 'decimal': case 'float': case 'double':
                return 0;
            case 'date':
                return '2000-01-01';
            case 'datetime': case 'timestamp':
                return now();
            case 'enum':
                if ($columnType && preg_match("/enum\\((.*)\\)/i", $columnType, $m)) {
                    $opts = array_map(fn($s) => trim($s, " '"), explode(',', $m[1]));
                    return $opts[0] ?? '';
                }
                return '';
            default:
                return '';
        }
    }

    /** OPTIONAL: createDummyOpponent() pakai helper yang sama (kalau perlu) **/
    public function createDummyOpponent($matchId)
    {
        $match = TournamentMatch::with('pool')->find($matchId);
        if (!$match) return response()->json(['message' => 'Pertandingan tidak ditemukan.'], 404);

        if ($match->participant_1 && $match->participant_2) {
            return response()->json(['message' => 'Pertandingan sudah lengkap, tidak bisa tambah dummy.'], 400);
        }

        $participantId = $match->participant_1 ?? $match->participant_2;
        $original = TeamMember::find($participantId);
        if (!$original) return response()->json(['message' => 'Peserta asli tidak ditemukan.'], 404);

        // contingent khusus untuk dummy opponent
        $contingentId = 127;

        $dummy = $this->createDummyTeamMemberAndRegister(
            $original,
            $contingentId,
            $original->gender ?? null,
            $match->pool->tournament_id,
            $match->pool_id,
            [
                'match_category_id'        => $original->match_category_id,
                'age_category_id'          => $original->age_category_id,
                'category_class_id'        => $original->category_class_id,
                'championship_category_id' => $original->championship_category_id,
            ]
        );

        if (!$match->participant_1) {
            $match->participant_1 = $dummy->id;
        } else {
            $match->participant_2 = $dummy->id;
        }
        $match->winner_id = null;
        $match->save();

        return response()->json([
            'message'  => 'Dummy berhasil ditambahkan ke match.',
            'match_id' => $match->id,
            'dummy'    => $dummy,
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
        return DB::transaction(function () use ($tournamentId, $poolId, $participants, $matchChart) {
            TournamentMatch::where('pool_id', $poolId)->delete();

            $pool = Pool::with('tournament')->find($poolId);
            if (!$pool) {
                return response()->json(['message' => 'Pool tidak ditemukan.'], 404);
            }

            // --- util: bikin dummy opponent konsisten dengan Full Prestasi ---
            $dummyContingentPool = [310, 311, 312, 313, 314, 315];
            $addedDummies = 0;

            $makeDummyFor = function ($templateTmId, $gender = null) use ($pool, $dummyContingentPool, $tournamentId, &$addedDummies) {
                if (method_exists($this, 'ensureContingentsExist')) {
                    $this->ensureContingentsExist($dummyContingentPool, $tournamentId);
                }

                $template = \App\Models\TeamMember::find($templateTmId);
                if (!$template) {
                    $template = \App\Models\TeamMember::query()
                        ->where('category_class_id', $pool->category_class_id)
                        ->where('match_category_id', $pool->match_category_id)
                        ->when(Schema::hasColumn('team_members','gender') && $gender, fn($q) => $q->where('gender', $gender))
                        ->first();
                }
                $chosenContingent = $dummyContingentPool[array_rand($dummyContingentPool)];

                if (method_exists($this, 'createDummyTeamMemberAndRegister')) {
                    $this->createDummyTeamMemberAndRegister(
                        $template,
                        $chosenContingent,
                        Schema::hasColumn('team_members','gender') ? ($gender ?? $template->gender) : null,
                        $tournamentId,
                        $pool->id,
                        [
                            'match_category_id' => $pool->match_category_id,
                            'category_class_id' => $pool->category_class_id,
                            // 'age_category_id' => $pool->age_category_id ?? $template?->age_category_id,
                        ]
                    );

                    $addedDummies++;

                    return DB::table('tournament_participants as tp')
                        ->join('team_members as tm', 'tp.team_member_id', '=', 'tm.id')
                        ->where('tp.tournament_id', $tournamentId)
                        ->where('tp.pool_id', $pool->id)
                        ->where('tm.category_class_id', $pool->category_class_id)
                        ->where('tm.match_category_id', $pool->match_category_id)
                        ->where('tm.contingent_id', $chosenContingent)
                        ->orderByDesc('tp.id')
                        ->value('tm.id');
                }

                // --- fallback inline ---
                $dummyName   = 'Dummy ' . ($template?->name ? '(' . $template->name . ')' : strtoupper(uniqid()));
                $dummyGender = Schema::hasColumn('team_members','gender') ? ($gender ?? $template?->gender ?? 'male') : null;

                $dummyTmId = DB::table('team_members')->insertGetId(array_filter([
                    'name'                     => $dummyName,
                    'contingent_id'            => $chosenContingent,
                    'gender'                   => $dummyGender,
                    'championship_category_id' => $template?->championship_category_id,
                    'age_category_id'          => $pool->age_category_id ?? $template?->age_category_id,
                    'category_class_id'        => $pool->category_class_id,
                    'match_category_id'        => $pool->match_category_id,
                    'is_dummy'                 => Schema::hasColumn('team_members','is_dummy') ? 1 : null,
                    'created_at'               => now(),
                    'updated_at'               => now(),
                ]));

                DB::table('tournament_participants')->insert([
                    'tournament_id'  => $tournamentId,
                    'team_member_id' => $dummyTmId,
                    'pool_id'        => $pool->id,
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);

                $addedDummies++;
                return $dummyTmId;
            };

            // Hindari peserta yang sudah dipakai di turnamen lain
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

            $maxParticipantCount   = (int) $matchChart; // ukuran bracket (2,4,8,16,...)
            $selectedParticipants  = $participants->slice(0, $maxParticipantCount)->values();
            $participantIds        = $selectedParticipants->pluck('id')->toArray();

            // Assign ke pool
            TournamentParticipant::whereIn('team_member_id', $participantIds)
                ->where('tournament_id', $tournamentId)
                ->update(['pool_id' => $poolId]);

            // === NEW: jika jumlah peserta ganjil (termasuk 1) → tambah 1 dummy agar pairing R1 bukan BYE
            if ($selectedParticipants->count() % 2 === 1) {
                $tpl = $selectedParticipants->first();
                $dummyId = $makeDummyFor($tpl->id, $tpl->gender ?? null);
                if ($dummyId) {
                    $selectedParticipants->push((object)[
                        'id' => $dummyId,
                        'gender' => $tpl->gender ?? null,
                        'category_class_id' => $pool->category_class_id,
                    ]);
                    $participantIds = $selectedParticipants->pluck('id')->toArray();
                }
            }

            // WARNING kalau jumlah kurang dari slot
            $warning = null;
            if ($selectedParticipants->count() < $maxParticipantCount) {
                $warning = "Peserta tidak ideal: ditemukan " . $selectedParticipants->count() . " dari $maxParticipantCount.";
            }

            $now          = now();
            $matchNumber  = 1;
            $matches      = collect();
            $totalPeserta = count($participantIds);

            // === 1 Peserta (setelah penambahan dummy di atas, kasus ini harusnya jadi 2 peserta)
            if ($totalPeserta === 1) {
                // Safety net: tetap bikin dummy & final 1 vs dummy
                $dummyId = $makeDummyFor($participantIds[0], null);
                $participantIds[] = $dummyId;
                $totalPeserta = 2;
            }

            // === 2 Peserta → Final langsung
            if ($totalPeserta === 2) {
                $matches->push([
                    'pool_id' => $poolId,
                    'round' => 1,
                    'round_label' => 'Final',
                    'match_number' => $matchNumber++,
                    'participant_1' => $participantIds[0],
                    'participant_2' => $participantIds[1],
                    'winner_id' => null, // tanding normal (bukan auto-menang)
                    'next_match_id' => null,
                    'parent_match_red_id' => null,
                    'parent_match_blue_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('tournament_matches')->insert($matches->toArray());

                return response()->json([
                    'message' => '✅ Bracket 2 peserta: langsung final (dummy dibuat bila perlu).',
                    'warning' => $warning,
                    'added_dummies' => $addedDummies,
                    'rounds' => TournamentMatch::where('pool_id', $poolId)
                        ->orderBy('round')->orderBy('match_number')->get(),
                ]);
            }

            // === Struktur Bracket (berdasarkan ukuran matchChart)
            $totalRounds = (int) log($matchChart, 2);
            for ($round = 1; $round <= $totalRounds; $round++) {
                $matchCount = (int) ($matchChart / pow(2, $round));
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
                        $parentIndex = (int) floor($i / 2);
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
            $used  = [];
            $pairs = [];

            $preferDifferentContingent = function ($id1, $pool) use ($teamMembers) {
                foreach ($pool as $k => $id2) {
                    if ($teamMembers[$id1]->contingent_id !== $teamMembers[$id2]->contingent_id) {
                        $partner = $id2;
                        unset($pool[$k]);
                        return [$partner, array_values($pool)];
                    }
                }
                if (!empty($pool)) {
                    $partner = array_shift($pool);
                    return [$partner, $pool];
                }
                return [null, $pool];
            };

            $poolIds = $participantIds;

            while (count($used) < $totalPeserta) {
                // pick p1
                $p1 = null;
                foreach ($poolIds as $idx => $id1) {
                    if (in_array($id1, $used, true)) continue;
                    $p1 = $id1; unset($poolIds[$idx]); $poolIds = array_values($poolIds);
                    break;
                }
                if (!$p1) break;

                // pick p2 dengan prefer beda kontingen
                [$p2, $poolIds] = $preferDifferentContingent($p1, $poolIds);

                // kalau p2 tetap null → buat dummy sekarang biar bukan BYE
                if (!$p2) {
                    $dummyId = $makeDummyFor($p1, $teamMembers[$p1]->gender ?? null);
                    $p2 = $dummyId;
                }

                $pairs[] = [$p1, $p2];
                $used[] = $p1; $used[] = $p2;
            }

            // === Assign ke Round 1
            $firstRoundMatches = $byRound[1]->values();
            foreach ($firstRoundMatches as $i => $match) {
                $pair = $pairs[$i] ?? [null, null];
                $match->participant_1 = $pair[0] ?? null;
                $match->participant_2 = $pair[1] ?? null;

                // Jangan auto-winner untuk kasus dummy; biar tetap tanding normal.
                // Auto-winner hanya jika slot kosong karena penyesuaian power-of-two (harusnya minim).
                if ($match->participant_1 && !$match->participant_2) {
                    $match->winner_id = $match->participant_1;
                }

                $match->save();
            }

            // === Special Case lama untuk 3 peserta (optional, aman untuk tetap ada)
            if (count($participantIds) === 3 && $totalRounds >= 2) {
                $finalRound = $byRound[$totalRounds]->first();
                $semiFinalMatches = $byRound[$totalRounds - 1] ?? collect();

                foreach ($semiFinalMatches as $m) {
                    if ($m->participant_1 && !$m->participant_2) {
                        if (!$finalRound->participant_1) {
                            $finalRound->participant_1 = $m->participant_1;
                        } elseif (!$finalRound->participant_2) {
                            $finalRound->participant_2 = $m->participant_1;
                        }
                        $m->winner_id = $m->participant_1;
                        $m->save();
                        $finalRound->save();
                    }
                }
            }

            return response()->json([
                'message'       => '✅ Bracket eliminasi tunggal berhasil dibuat (sisa 1 dipasangkan dummy).',
                'warning'       => $warning,
                'added_dummies' => $addedDummies,
                'rounds'        => TournamentMatch::where('pool_id', $poolId)
                    ->orderBy('round')->orderBy('match_number')->get(),
            ]);
        });
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
