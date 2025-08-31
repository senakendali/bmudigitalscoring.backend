<?php

namespace App\Http\Controllers;

use App\Models\TournamentParticipant;
use App\Models\SeniPool;
use App\Models\SeniMatch;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\MatchSchedule;
use App\Models\MatchScheduleDetail;
use Barryvdh\DomPDF\Facade\Pdf;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class SeniMatchController extends Controller
{
    public function index(Request $request)
    {
        $tournamentId = $request->query('tournament_id');

        // 🔍 Eager loading: pastikan pool + ageCategory ikut
        $query = SeniMatch::with([
            'matchCategory',
            'contingent',
            'teamMember1',
            'teamMember2',
            'teamMember3',
            'pool.ageCategory',
        ])
        ->orderBy('pool_id')
        ->orderBy('match_order');

        // ✅ Filter berdasarkan tournament_id jika ada
        if ($tournamentId) {
            $query->whereHas('pool', fn($q) =>
                $q->where('tournament_id', $tournamentId)
            );
        }

        $matches = $query->get();

        // Urutan umur yang digunakan untuk sorting akhir
        $ageOrder = [
            'Usia Dini 1' => 1,
            'Usia Dini 2' => 2,
            'Pra Remaja'  => 3,
            'Remaja'      => 4,
            'Dewasa'      => 5,
        ];

        // 🔄 Grouping & Struktur Final (dengan pool.id)
        $grouped = $matches
            ->groupBy(function ($match) {
                $category     = optional($match->matchCategory)->name ?? '-';
                $gender       = $match->gender ?? '-';
                $ageCategory  = optional(optional($match->pool)->ageCategory)->name ?? '-';
                return $category . '|' . $gender . '|' . $ageCategory;
            })
            ->map(function ($groupMatches, $key) {
                [$category, $gender, $ageCategory] = explode('|', $key);

                // ⛳ Group per pool_id (bukan pool.name)
                $pools = $groupMatches
                    ->groupBy(fn($m) => $m->pool_id)
                    ->map(function ($poolMatches, $poolId) {
                        // ambil 1 sample utk ambil nama pool
                        $samplePool = optional($poolMatches->first())->pool;
                        return [
                            'id'      => (int) $poolId,
                            'name'    => $samplePool->name ?? 'POOL-' . $poolId,
                            // 'bracket_type' => $samplePool->bracket_type ?? null, // (opsional, kalau ada di tabel pools)
                            'matches' => $poolMatches->values(),
                        ];
                    })
                    // urutkan by nama pool (opsional)
                    ->sortBy('name')
                    ->values();

                return [
                    'age_category' => $ageCategory,
                    'category'     => $category,
                    'gender'       => $gender,
                    'pools'        => $pools,
                ];
            })
            ->sortBy(fn($item) => $ageOrder[$item['age_category']] ?? 99)
            ->values();

        return response()->json($grouped);
    }

    public function getMatches($poolId)
    {
        // ✅ pakai SeniPool, bukan Pool
        $pool = SeniPool::findOrFail($poolId);

        // ambil tipe chart/bracket dari SeniPool (fallback ke beberapa nama kolom umum)
        $matchChart = (int) (
            $pool->match_chart
            ?? $pool->bracket_type
            ?? $pool->matchChart
            ?? 0
        );

        // Ambil semua row corner (blue/red) per pool (mode battle)
        $rows = SeniMatch::with([
                'contingent',
                'teamMember1', 'teamMember2', 'teamMember3',
            ])
            ->where('pool_id', $poolId)
            ->where('mode', 'battle')          // bracket mode
            ->orderBy('round_priority')        // urut per round
            ->orderBy('battle_group')          // ⬅️ pasangan game
            ->orderBy('corner')                // blue dulu, lalu red
            ->get();

        // Kelompokkan per round label (atau fallback 'Round')
        $byRound = $rows->groupBy(fn ($m) => $m->round_label ?? 'Round');

        $groupedRounds = [];
        $allGamesEmpty = true;

        foreach ($byRound as $roundLabel => $roundRows) {
            // ⛳ Pasangkan per battle_group (1 game = 1 battle_group)
            $byBattleGroup = $roundRows->groupBy('battle_group');

            $games = [];
            foreach ($byBattleGroup as $bg => $pairRows) {
                $blue = $pairRows->firstWhere('corner', 'blue');
                $red  = $pairRows->firstWhere('corner', 'red');

                $p1 = $this->buildPlayerPayload($blue, $roundLabel);
                $p2 = $this->buildPlayerPayload($red,  $roundLabel);

                // Winner pakai winner_corner (kalau belum ada, biarin false)
                $winnerCorner = $blue->winner_corner ?? $red->winner_corner ?? null;
                if ($winnerCorner === 'blue') $p1['winner'] = true;
                if ($winnerCorner === 'red')  $p2['winner'] = true;

                if ($p1['id'] || $p2['id']) $allGamesEmpty = false;

                $games[] = [
                    'player1' => $p1,
                    'player2' => $p2,
                ];
            }

            $groupedRounds[] = [
                'label' => $roundLabel,
                'games' => array_values($games),
            ];
        }

        // Urutkan round berdasarkan round_priority yang ada di rows
        $priorityMap = $rows->groupBy(fn($m) => $m->round_label ?? 'Round')
                            ->map(fn($g) => (int) ($g->first()->round_priority ?? 9999));

        usort($groupedRounds, fn($a, $b) =>
            ($priorityMap[$a['label']] ?? 9999) <=> ($priorityMap[$b['label']] ?? 9999)
        );

        // Samakan dengan struktur tanding (tanpa label)
        $rounds = array_values(array_map(fn($r) => ['games' => $r['games']], $groupedRounds));

        return response()->json([
            'rounds'      => $rounds,
            'match_chart' => $matchChart,
            'status'      => $allGamesEmpty ? 'pending' : 'ongoing',
        ]);
    }

    /**
     * Build payload player agar sama dengan struktur tanding:
     * { id: string|null, name: string, contingent: string, winner: bool }
     * - seni_tunggal  → nama = member1
     * - seni_ganda    → nama = "member1 & member2"
     * - seni_regu     → nama = "member1, member2, member3"
     * - kalau null    → BYE untuk round pertama, selain itu TBD
     */
    private function buildPlayerPayload($matchRow, string $roundLabel): array
    {
        // Jika sisi (blue/red) tidak ada → BYE/TBD
        if (!$matchRow) {
            $label = strtolower($roundLabel);
            $isFirst = str_contains($label, 'round 1')
                    || str_contains($label, 'babak 1')
                    || str_contains($label, 'preliminary')
                    || str_contains($label, '1'); // fallback kasar
            return [
                'id'         => null,
                'name'       => $isFirst ? 'BYE' : 'TBD',
                'contingent' => '-',
                'winner'     => false,
            ];
        }

        $type = $matchRow->match_type ?? 'seni_tunggal';

        // Kumpulkan nama anggota sesuai tipe
        $names = [];
        if (!empty($matchRow->teamMember1?->name)) $names[] = $matchRow->teamMember1->name;
        if ($type !== 'seni_tunggal' && !empty($matchRow->teamMember2?->name)) $names[] = $matchRow->teamMember2->name;
        if ($type === 'seni_regu'    && !empty($matchRow->teamMember3?->name)) $names[] = $matchRow->teamMember3->name;

        // Format tampilan nama
        $displayName = match ($type) {
            'seni_tunggal' => $names[0] ?? '-',
            'seni_ganda'   => implode(' & ', array_filter($names)),
            'seni_regu'    => implode(', ', array_filter($names)),
            default        => $names[0] ?? '-',
        };

        return [
            // Kalau kamu punya id peserta khusus, ganti ke field itu
            'id'         => isset($matchRow->id) ? (string) $matchRow->id : null,
            'name'       => $displayName ?: '-',
            'contingent' => $matchRow->contingent->name ?? '-',
            'winner'     => false, // akan di-set di getMatches() pakai winner_corner
        ];
    }

   public function getSchedules($slug)
    {
        $tournament = Tournament::where('slug', $slug)->firstOrFail();

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'seniMatch.contingent',
            'seniMatch.teamMember1',
            'seniMatch.teamMember2',
            'seniMatch.teamMember3',
            'seniMatch.pool.ageCategory',
            'seniMatch.matchCategory'
        ])
        ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id));

        // Optional filters
        if (request()->filled('arena_name')) {
            $query->whereHas('schedule.arena', function ($q) {
                $q->where('name', request()->arena_name);
            });
        }

        if (request()->filled('scheduled_date')) {
            $query->whereHas('schedule', function ($q) {
                $q->where('scheduled_date', request()->scheduled_date);
            });
        }

        if (request()->filled('pool_name')) {
            $query->whereHas('seniMatch.pool', function ($q) {
                $q->where('name', request()->pool_name);
            });
        }

        $details = $query->get();

        $tournamentName = $tournament->name;
        $grouped = [];

        foreach ($details as $detail) {
            $match = $detail->seniMatch;
            if (!$match) continue;

            $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $scheduledDate = $detail->schedule->scheduled_date ?? 'Tanpa Tanggal';
            $poolName = $match->pool->name ?? 'Tanpa Pool';
            $category = $match->matchCategory->name ?? '-';
            $gender = $match->gender ?? '-';
            $matchType = $match->match_type;
            $ageCategory = optional($match->pool?->ageCategory)->name ?? '-';

            $groupKey = $arenaName . '||' . $scheduledDate;
            $categoryKey = $category . '|' . $gender . '|' . $ageCategory;

            $matchData = [
                'id' => $match->id,
                'match_order' => $detail->order,
                'match_time' => $detail->start_time,
                'contingent' => optional($match->contingent)?->only(['id', 'name']),
                'team_member1' => optional($match->teamMember1)?->only(['id', 'name']),
                'team_member2' => optional($match->teamMember2)?->only(['id', 'name']),
                'team_member3' => optional($match->teamMember3)?->only(['id', 'name']),
                'match_type' => $matchType,
                'scheduled_date' => $scheduledDate,
                'tournament_name' => $tournamentName,
                'arena_name' => $arenaName,
                'pool' => [
                    'name' => $poolName,
                    'age_category' => ['name' => $ageCategory],
                ],
            ];

            $grouped[$groupKey]['arena_name'] = $arenaName;
            $grouped[$groupKey]['scheduled_date'] = $scheduledDate;
            $grouped[$groupKey]['tournament_name'] = $tournamentName;

            $grouped[$groupKey]['groups'][$categoryKey]['category'] = $category;
            $grouped[$groupKey]['groups'][$categoryKey]['gender'] = $gender;
            $grouped[$groupKey]['groups'][$categoryKey]['age_category'] = $ageCategory;

            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['name'] = $poolName;
            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['matches'][] = $matchData;
        }

        $result = [];

        foreach ($grouped as $entry) {
            $groups = [];
            foreach ($entry['groups'] as $group) {
                $pools = [];
                foreach ($group['pools'] as $pool) {
                    $pools[] = [
                        'name' => $pool['name'],
                        'matches' => $pool['matches'],
                    ];
                }

                $groups[] = [
                    'category' => $group['category'],
                    'gender' => $group['gender'],
                    'age_category' => $group['age_category'],
                    'pools' => $pools,
                ];
            }

            $result[] = [
                'arena_name' => $entry['arena_name'],
                'scheduled_date' => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'groups' => $groups,
            ];
        }

        return response()->json(['data' => $result]);
    }

    public function export(Request $request)
    {
        $arena = $request->query('arena_name');
        $date = $request->query('scheduled_date');

        if (!$arena || !$date) {
            return abort(400, 'Parameter arena_name dan scheduled_date wajib diisi');
        }

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'seniMatch.contingent',
            'seniMatch.teamMember1',
            'seniMatch.teamMember2',
            'seniMatch.teamMember3',
            'seniMatch.pool.ageCategory',
            'seniMatch.matchCategory'
        ])
        ->whereHas('schedule', fn($q) => $q->where('scheduled_date', $date))
        ->whereHas('schedule.arena', fn($q) => $q->where('name', $arena));

        $details = $query->get();

        // === Grouping sama seperti sebelumnya ===
        $grouped = [];
        foreach ($details as $detail) {
            $match = $detail->seniMatch;
            if (!$match) continue;

            $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $scheduledDate = $detail->schedule->scheduled_date ?? 'Tanpa Tanggal';
            $poolName = $match->pool->name ?? 'Tanpa Pool';
            $category = $match->matchCategory->name ?? '-';
            $gender = $match->gender ?? '-';
            $matchType = $match->match_type;
            $ageCategory = optional($match->pool?->ageCategory)->name ?? '-';
            $tournamentName = $detail->schedule->tournament->name ?? '-';

            $groupKey = $arenaName . '||' . $scheduledDate;
            $categoryKey = $category . '|' . $gender . '|' . $ageCategory;

            $matchData = [
                'id' => $match->id,
                'match_order' => $detail->order,
                'match_time' => $detail->start_time,
                'contingent' => optional($match->contingent)?->only(['id', 'name']),
                'team_member1' => optional($match->teamMember1)?->only(['id', 'name']),
                'team_member2' => optional($match->teamMember2)?->only(['id', 'name']),
                'team_member3' => optional($match->teamMember3)?->only(['id', 'name']),
                'match_type' => $matchType,
                'scheduled_date' => $scheduledDate,
                'tournament_name' => $tournamentName,
                'arena_name' => $arenaName,
                'pool' => [
                    'name' => $poolName,
                    'age_category' => ['name' => $ageCategory],
                ],
            ];

            $grouped[$groupKey]['arena_name'] = $arenaName;
            $grouped[$groupKey]['scheduled_date'] = $scheduledDate;
            $grouped[$groupKey]['tournament_name'] = $tournamentName;

            $grouped[$groupKey]['groups'][$categoryKey]['category'] = $category;
            $grouped[$groupKey]['groups'][$categoryKey]['gender'] = $gender;
            $grouped[$groupKey]['groups'][$categoryKey]['age_category'] = $ageCategory;

            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['name'] = $poolName;
            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['matches'][] = $matchData;
        }

        $result = [];
        foreach ($grouped as $entry) {
            $groups = [];
            foreach ($entry['groups'] as $group) {
                $pools = [];
                foreach ($group['pools'] as $pool) {
                    $pools[] = [
                        'name' => $pool['name'],
                        'matches' => $pool['matches'],
                    ];
                }

                $groups[] = [
                    'category' => $group['category'],
                    'gender' => $group['gender'],
                    'age_category' => $group['age_category'],
                    'pools' => $pools,
                ];
            }

            $result[] = [
                'arena_name' => $entry['arena_name'],
                'scheduled_date' => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'groups' => $groups,
            ];
        }

        $data = $result[0] ?? null;

        if (!$data) {
            return abort(404, 'Data tidak ditemukan');
        }

        $pdf = Pdf::loadView('exports.seni-schedule', compact('data'))->setPaper('a4', 'portrait');
        $filename = 'jadwal-' . str_replace(' ', '-', strtolower($arena)) . '-' . $date . '.pdf';

        return $pdf->download($filename);
        //return $pdf->stream($filename);

    }





    public function getSchedules_($slug)
    {
        $tournament = Tournament::where('slug', $slug)->firstOrFail();

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'seniMatch.contingent',
            'seniMatch.teamMember1',
            'seniMatch.teamMember2',
            'seniMatch.teamMember3',
            'seniMatch.pool.ageCategory',
            'seniMatch.matchCategory'
        ])
        ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
        ->whereHas('seniMatch');

        // Optional filters (kalau dipakai di query string)
        if (request()->filled('arena_name')) {
            $query->whereHas('schedule.arena', function ($q) {
                $q->where('name', request()->arena_name);
            });
        }

        if (request()->filled('scheduled_date')) {
            $query->whereHas('schedule', function ($q) {
                $q->where('scheduled_date', request()->scheduled_date);
            });
        }

        if (request()->filled('pool_name')) {
            $query->whereHas('seniMatch.pool', function ($q) {
                $q->where('name', request()->pool_name);
            });
        }

        $details = $query->get();

        $tournamentName = $tournament->name;
        $grouped = [];

        foreach ($details as $detail) {
            $match = $detail->seniMatch;
            if (!$match) continue;

            $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $scheduledDate = $detail->schedule->scheduled_date ?? 'Tanpa Tanggal';
            $poolName = $match->pool->name ?? 'Tanpa Pool';
            $category = $match->matchCategory->name ?? '-';
            $gender = $match->gender ?? '-';
            $matchType = $match->match_type;
            $ageCategory = optional($match->pool?->ageCategory)->name ?? '-';

            $groupKey = $arenaName . '||' . $scheduledDate;

            $matchData = [
                'id' => $match->id,
                'match_order' => $detail->order,
                'match_time' => $detail->start_time,
                'contingent' => optional($match->contingent)?->only(['id', 'name']),
                'team_member1' => optional($match->teamMember1)?->only(['id', 'name']),
                'team_member2' => optional($match->teamMember2)?->only(['id', 'name']),
                'team_member3' => optional($match->teamMember3)?->only(['id', 'name']),
                'match_type' => $matchType,
                'scheduled_date' => $scheduledDate,
                'tournament_name' => $tournamentName,
                'arena_name' => $arenaName,
                'pool' => [
                    'name' => $poolName,
                    'age_category' => ['name' => $ageCategory],
                ],
            ];

            $grouped[$groupKey]['arena_name'] = $arenaName;
            $grouped[$groupKey]['scheduled_date'] = $scheduledDate;
            $grouped[$groupKey]['tournament_name'] = $tournamentName;

            $categoryKey = $category . '|' . $gender;
            $grouped[$groupKey]['groups'][$categoryKey]['category'] = $category;
            $grouped[$groupKey]['groups'][$categoryKey]['gender'] = $gender;

            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['name'] = $poolName;
            $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['matches'][] = $matchData;
        }

        // Transform result
        $result = [];

        foreach ($grouped as $entry) {
            $groups = [];
            foreach ($entry['groups'] as $group) {
                $pools = [];
                foreach ($group['pools'] as $pool) {
                    $pools[] = [
                        'name' => $pool['name'],
                        'matches' => $pool['matches'],
                    ];
                }

                $groups[] = [
                    'category' => $group['category'],
                    'gender' => $group['gender'],
                    'pools' => $pools,
                ];
            }

            $result[] = [
                'arena_name' => $entry['arena_name'],
                'scheduled_date' => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'groups' => $groups,
            ];
        }

        return response()->json(['data' => $result]);
    }

    public function matchList(Request $request)
    {
        $tournamentId = $request->query('tournament_id');
        $includeScheduled = $request->boolean('include_scheduled'); // ← tambahin flag

        $query = SeniMatch::with([
            'matchCategory',
            'contingent',
            'teamMember1',
            'teamMember2',
            'teamMember3',
            'pool.ageCategory',
        ])
        ->orderBy('pool_id')
        ->orderBy('match_order');

        // ⬇️ Exclude yang sudah dijadwalkan hanya kalau bukan mode edit
        if (!$includeScheduled) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('match_schedule_details')
                    ->whereColumn('match_schedule_details.seni_match_id', 'seni_matches.id');
            });
        }

        // ⬇️ Filter berdasarkan tournament_id
        if ($tournamentId) {
            $query->whereHas('pool', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            });
        }

        $matches = $query->get();

        // ⬇️ Group by age_category + match category + gender
        $grouped = $matches->groupBy(fn($match) =>
            $match->pool->ageCategory->name . '|' .
            $match->matchCategory->name . '|' .
            $match->gender
        )
        ->map(function ($matchesByGroup, $key) {
            [$ageCategory, $category, $gender] = explode('|', $key);

            return [
                'age_category' => $ageCategory,
                'category' => $category,
                'gender' => $gender,
                'pools' => $matchesByGroup->groupBy(fn($match) => $match->pool->name)
                    ->map(function ($poolMatches, $poolName) {
                        return [
                            'name' => $poolName,
                            'matches' => $poolMatches->values()
                        ];
                    })->values()
            ];
        })->values();

        return response()->json($grouped);
    }



    public function matchList__(Request $request)
    {
        $tournamentId = $request->query('tournament_id');
        $includeScheduled = $request->boolean('include_scheduled'); // ← tambahin flag

        $query = SeniMatch::with([
            'matchCategory',
            'contingent',
            'teamMember1',
            'teamMember2',
            'teamMember3',
            'pool.ageCategory',
        ])
        ->orderBy('pool_id')
        ->orderBy('match_order');

        // ⬇️ Exclude yang sudah dijadwalkan hanya kalau bukan mode edit
        if (!$includeScheduled) {
            $query->whereNotExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('match_schedule_details')
                    ->whereColumn('match_schedule_details.seni_match_id', 'seni_matches.id');
            });
        }

        // ⬇️ Filter berdasarkan tournament_id
        if ($tournamentId) {
            $query->whereHas('pool', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            });
        }

        $matches = $query->get();

        $grouped = $matches->groupBy(fn($match) => $match->matchCategory->name . '|' . $match->gender)
            ->map(function ($matchesByCategory, $key) {
                [$category, $gender] = explode('|', $key);

                return [
                    'category' => $category,
                    'gender' => $gender,
                    'pools' => $matchesByCategory->groupBy(fn($match) => $match->pool->name)
                        ->map(function ($poolMatches, $poolName) {
                            return [
                                'name' => $poolName,
                                'matches' => $poolMatches->values()
                            ];
                        })->values()
                ];
            })->values();

        return response()->json($grouped);
    }
    
    public function generate(Request $request)
    {
        // Rule umum
        $rules = [
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|in:2,3,4,5',
            'age_category_id' => 'required|exists:age_categories,id',
            'gender' => 'required|in:male,female',
            'mode' => 'required|in:default,battle',
        ];

        // Kalau mode default → wajib pool_size
        if ($request->mode === 'default') {
            $rules['pool_size'] = 'required|integer|min:1';
        }

        // Kalau mode battle → wajib bracket_type
        if ($request->mode === 'battle') {
            $rules['bracket_type'] = 'required|in:2,4,8,16,full_prestasi';
        }

        $validated = $request->validate($rules);

        if ($validated['mode'] === 'default') {
            return $this->generatePoolMode($validated);
        } else {
            return $this->generateBattleMode($validated);
        }
    }



    /**
     * =========================
     * MODE DEFAULT (POOL / URUTAN)
     * =========================
     */
    protected function generatePoolMode($validated)
    {
        // Hapus data lama
        $existingPools = \App\Models\SeniPool::where('tournament_id', $validated['tournament_id'])
            ->where('match_category_id', $validated['match_category_id'])
            ->where('age_category_id', $validated['age_category_id'])
            ->where('gender', $validated['gender'])
            ->get();

        if ($existingPools->isNotEmpty()) {
            $poolIds = $existingPools->pluck('id');
            \App\Models\SeniMatch::whereIn('pool_id', $poolIds)->delete();
            \App\Models\SeniPool::whereIn('id', $poolIds)->delete();
        }

        // Ambil peserta
        $participants = \App\Models\TournamentParticipant::where('tournament_id', $validated['tournament_id'])
            ->whereHas('participant', function ($query) use ($validated) {
                $query->where('match_category_id', $validated['match_category_id'])
                    ->where('age_category_id', $validated['age_category_id'])
                    ->where('gender', $validated['gender']);
            })
            ->with('participant')
            ->get()
            ->filter(fn($tp) => $tp->participant !== null);

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'No participants found.'], 404);
        }

        // Kelompokkan berdasarkan kontingen untuk Ganda/Regu
        $matchCategory = $validated['match_category_id'];
        $requiredMembers = $matchCategory === 3 ? 2 : ($matchCategory === 4 ? 3 : 1);
        $usedMemberIds = [];

        if ($matchCategory === 2 || $matchCategory === 5) {
            $units = $participants->shuffle()->values();
        } else {
            $units = $participants
                ->groupBy(fn($tp) => $tp->participant->contingent_id)
                ->filter(fn($group) => $group->count() >= $requiredMembers)
                ->map(fn($group) => $group->shuffle()->take($requiredMembers))
                ->values()
                ->shuffle();
        }

        // Bagi ke dalam pool
        $chunks = $units->chunk($validated['pool_size']);

        foreach ($chunks as $i => $chunk) {
            $pool = \App\Models\SeniPool::create([
                'tournament_id' => $validated['tournament_id'],
                'match_category_id' => $validated['match_category_id'],
                'age_category_id' => $validated['age_category_id'],
                'gender' => $validated['gender'],
                'name' => 'Pool ' . ($i + 1),
            ]);

            foreach ($chunk->values() as $index => $unit) {
                if ($matchCategory === 2 || $matchCategory === 5) {
                    $teamMember = $unit->participant;
                    if (in_array($teamMember->id, $usedMemberIds)) continue;

                    \App\Models\SeniMatch::create([
                        'pool_id' => $pool->id,
                        'match_order' => $index + 1,
                        'gender' => $validated['gender'],
                        'match_category_id' => $matchCategory,
                        'match_type' => 'seni_tunggal',
                        'contingent_id' => $teamMember->contingent_id,
                        'team_member_1' => $teamMember->id,
                    ]);
                    $usedMemberIds[] = $teamMember->id;

                } else {
                    $members = $unit->pluck('participant')->filter()->values();
                    $memberIds = $members->pluck('id');

                    if ($memberIds->count() < $requiredMembers) continue;
                    if ($memberIds->intersect($usedMemberIds)->isNotEmpty()) continue;

                    $matchData = [
                        'pool_id' => $pool->id,
                        'match_order' => $index + 1,
                        'gender' => $validated['gender'],
                        'match_category_id' => $matchCategory,
                        'match_type' => match ($matchCategory) {
                            3 => 'seni_ganda',
                            4 => 'seni_regu',
                        },
                        'contingent_id' => $members[0]->contingent_id,
                        'team_member_1' => $memberIds[0],
                    ];

                    if ($requiredMembers >= 2) $matchData['team_member_2'] = $memberIds[1];
                    if ($requiredMembers === 3) $matchData['team_member_3'] = $memberIds[2];

                    \App\Models\SeniMatch::create($matchData);
                    $usedMemberIds = array_merge($usedMemberIds, $memberIds->toArray());
                }
            }
        }

        return response()->json(['message' => 'Seni matches created (pool mode) successfully.']);
    }

    private function getRoundLabel(int $round, int $maxRound): string
    {
        // round mulai dari 1 (Round 1 = babak paling awal)
        // diff 0 => Final, 1 => Semifinal, 2 => 1/4 Final, dst.
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

    

    /**
     * Map match_category_id → (match_type string, team size)
     */
    private function resolveMatchTypeAndSize(int $categoryId): array
    {
        $map = [
            2 => ['seni_tunggal', 1],
            3 => ['seni_ganda',   2],
            4 => ['seni_regu',    3],
            5 => ['solo_kreatif', 1],
        ];
        return $map[$categoryId] ?? ['seni_tunggal', 1];
    }

    private function nextPow2(int $n): int
    {
        if ($n <= 1) return 1;
        return (int) pow(2, ceil(log($n, 2)));
    }

    /**
     * Bentuk tim dari daftar TournamentParticipant.
     * - Kalau objek participant punya key tim (team_id / group_id / team_code / regu_code / pair_code / pairing_code),
     *   kita gunakan itu untuk nge-grup.
     * - Kalau tidak ada, fallback: grup per contingent_id, lalu chunk per $teamSize (urut created_at/id).
     *
     * Hasil: Collection of [
     *   'contingent_id' => ?int,
     *   'members'       => Collection<Participant>,
     *   'key'           => string|null,
     * ]
     */
    private function groupIntoTeams($participants, int $teamSize)
    {
        // normalize: ambil Participant model-nya
        $members = $participants->map(fn($tp) => $tp->participant)->filter();

        // cek apakah ada “kunci tim” yang konsisten
        $hasTeamKey = $members->contains(function ($p) {
            return isset($p->team_id) || isset($p->group_id) || isset($p->team_code)
                || isset($p->regu_code) || isset($p->pair_code) || isset($p->pairing_code);
        });

        $teams = collect();

        if ($hasTeamKey) {
            // Grup via key
            $grouped = $members->groupBy(function ($p) {
                return $p->team_id
                    ?? $p->group_id
                    ?? $p->team_code
                    ?? $p->regu_code
                    ?? $p->pair_code
                    ?? $p->pairing_code;
            });

            foreach ($grouped as $key => $group) {
                // Jika kebanyakan anggota (mis-key), pecah per teamSize
                $chunks = $group->values()->chunk($teamSize);
                foreach ($chunks as $c) {
                    $teams->push([
                        'contingent_id' => $c[0]->contingent_id ?? null,
                        'members'       => $c, // Collection<Participant>
                        'key'           => (string)$key,
                    ]);
                }
            }
        } else {
            // Fallback: grup per kontingen lalu chunk per teamSize (urut agar rapih)
            $byCont = $members->sortBy([
                    ['contingent_id', 'asc'],
                    ['id', 'asc'], // ganti ke created_at kalau ada
                ])
                ->groupBy('contingent_id');

            foreach ($byCont as $contId => $group) {
                foreach ($group->chunk($teamSize) as $c) {
                    $teams->push([
                        'contingent_id' => $contId ?: null,
                        'members'       => $c, // Collection<Participant>
                        'key'           => null,
                    ]);
                }
            }
        }

        // filter tim kosong (jaga-jaga)
        return $teams->filter(fn($t) => $t['members']->count() > 0)->values();
    }

    /** Tim khusus BYE (tanpa anggota) */
    private function buildBYETeam(int $teamSize): array
    {
        return [
            'contingent_id' => null,
            'members'       => collect([]), // 0 anggota
            'key'           => null,
        ];
    }

    private function isBYETeam($team): bool
    {
        return empty($team) || !isset($team['members']) || $team['members']->count() === 0;
    }

    /** Ambil sampai 3 member id untuk diisi ke team_member_1..3 */
    private function extractMemberIds($team, int $teamSize): array
    {
        if ($this->isBYETeam($team)) {
            return [null, null, null];
        }
        $m = $team['members']->values();
        $m1 = $m[0]->id ?? null;
        $m2 = $teamSize >= 2 ? ($m[1]->id ?? null) : null;
        $m3 = $teamSize >= 3 ? ($m[2]->id ?? null) : null;
        return [$m1, $m2, $m3];
    }



    /**
     * Generate pertandingan SENI mode battle (bracket) dengan dukungan:
     * - full_prestasi: ideal = power-of-two terdekat (tie → lebih kecil)
     * - BYE auto-advance: tim yang dapat BYE langsung “naik” ke ronde berikutnya
     * - Support tunggal/ganda/regu (teamSize 1/2/3)
     */
    protected function generateBattleMode(array $validated)
    {
        // Hapus pool & match lama
        $oldPools = \App\Models\SeniPool::where('tournament_id', $validated['tournament_id'])
            ->where('match_category_id', $validated['match_category_id'])
            ->where('age_category_id', $validated['age_category_id'])
            ->where('gender', $validated['gender'])
            ->pluck('id');

        if ($oldPools->isNotEmpty()) {
            \App\Models\SeniMatch::whereIn('pool_id', $oldPools)->delete();
            \App\Models\SeniPool::whereIn('id', $oldPools)->delete();
        }

        // Ambil peserta
        $participants = \App\Models\TournamentParticipant::where('tournament_id', $validated['tournament_id'])
            ->whereHas('participant', function ($q) use ($validated) {
                $q->where('match_category_id', $validated['match_category_id'])
                ->where('age_category_id',  $validated['age_category_id'])
                ->where('gender',           $validated['gender']);
            })
            ->with('participant')
            ->get()
            ->filter(fn($tp) => $tp->participant !== null)
            ->values();

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'No participants found.'], 404);
        }

        // Tipe pertandingan + ukuran tim
        [$matchType, $teamSize] = $this->resolveMatchTypeAndSize((int)$validated['match_category_id']);

        // Bentuk tim
        $teams = $this->groupIntoTeams($participants, $teamSize);
        if ($teams->isEmpty()) {
            return response()->json(['message' => 'No valid teams could be formed.'], 422);
        }

        // Pooling
        if ($validated['bracket_type'] === 'full_prestasi') {
            // Satu pool besar → nanti K = nextPow2(N)
            $pools = collect([$teams->shuffle()->values()]);
        } else {
            // Bikin beberapa pool dengan ukuran <= requested
            $requested = max(2, (int)$validated['bracket_type']);
            $pools = $teams->shuffle()->chunk($requested);
        }

        foreach ($pools as $i => $chunk) {
            $pool = \App\Models\SeniPool::create([
                'tournament_id'      => $validated['tournament_id'],
                'match_category_id'  => $validated['match_category_id'],
                'age_category_id'    => $validated['age_category_id'],
                'gender'             => $validated['gender'],
                'name'               => 'Pool ' . ($i + 1),
                'mode'               => 'battle',
                'bracket_type'       => $validated['bracket_type'],
            ]);

            $N = $chunk->count();
            if ($N === 0) continue;

            // === Tentukan K (jumlah tim target di bracket utama) ===
            if ($validated['bracket_type'] === 'full_prestasi') {
                // Pakai ceil ke pangkat 2 terdekat
                $K = max(2, $this->nextPow2($N));
            } else {
                $requested = max(2, (int)$validated['bracket_type']); // 2/4/8/16
                $K = $requested; // hormati permintaan user
            }

            // Penentuan struktur ronde 1
            $pairings = [];               // list pasangan [blueTeam|null, redTeam|null]
            $bag      = $chunk->values(); // tim acak ringan
            $battleGroup = 1;

            if ($N <= $K) {
                // ====== KASUS: N ≤ K  → Ronde 1 = pertandingan penuh dengan BYE seperlunya ======
                $byeCount     = $K - $N;               // jumlah slot kosong (setara 1 sisi pairing)
                $targetPairs  = intdiv($K, 2);         // jumlah pertandingan di ronde 1
                $byeBlueSide  = true;

                // (1) Sisipkan BYE dulu (satu sisi kosong) sebanyak byeCount
                for ($b = 0; $b < $byeCount && $bag->count() > 0; $b++) {
                    $team = $bag->shift();
                    $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
                    $byeBlueSide = !$byeBlueSide;
                }

                // (2) Sisa tim dipasangkan normal
                while (count($pairings) < $targetPairs && $bag->count() > 0) {
                    $blue = $bag->shift();
                    $red  = $bag->shift();
                    if ($blue === null && $red === null) {
                        $pairings[] = [null, null];
                    } elseif ($red === null) {
                        // ganjil → otomatis BYE di sisi lawan
                        $pairings[] = [$blue, null];
                    } else {
                        $pairings[] = [$blue, $red];
                    }
                }

                // (3) Tambal jika masih kurang (jaga2)
                while (count($pairings) < $targetPairs) {
                    $pairings[] = [null, null];
                }

                $totalRounds = (int) log($K, 2);

            } else {
                // ====== KASUS: N > K → Ada PLAY-IN di Ronde 1 ======
                // Ronde 1 terdiri dari:
                //   - P = N-K pertandingan play-in (2 tim)
                //   - (K - P) pairing BYE (1 tim vs null) → peserta langsung ke ronde berikutnya
                // Total "pairing" ronde 1 = K
                $P = $N - $K; // banyaknya play-in
                // (1) Play-in dulu
                for ($m = 0; $m < $P && $bag->count() > 0; $m++) {
                    $blue = $bag->shift();
                    $red  = $bag->shift();
                    if ($blue === null && $red === null) break;
                    if ($red === null) {
                        // kalau sisa satu, anggap BYE saja
                        $pairings[] = [$blue, null];
                    } else {
                        $pairings[] = [$blue, $red];
                    }
                }
                // (2) Sisanya jadi direct entry → pairing BYE
                $byeBlueSide = true;
                while (count($pairings) < $K && $bag->count() > 0) {
                    $team = $bag->shift();
                    $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
                    $byeBlueSide = !$byeBlueSide;
                }
                // (3) Tambal jika masih kurang
                while (count($pairings) < $K) {
                    $pairings[] = [null, null];
                }

                $totalRounds = (int) log($K, 2) + 1; // +1 karena ada ronde play-in
            }

            if (!count($pairings)) continue;

            // ====== Tulis Ronde 1 (BYE = 1 row saja + winner_corner) ======
            $round       = 1;
            $roundLabel  = $this->getRoundLabel($round, $totalRounds);
            $currentRound = []; // simpan [redMatchId|null, blueMatchId|null] per battle_group

            foreach ($pairings as [$blueTeam, $redTeam]) {
                // dua sisi null
                if ($blueTeam === null && $redTeam === null) {
                    $currentRound[] = [null, null];
                    $battleGroup++;
                    continue;
                }

                // BYE sisi BLUE
                if ($blueTeam !== null && $redTeam === null) {
                    [$b1, $b2, $b3] = $this->extractMemberIds($blueTeam, $teamSize);
                    $blueMatch = \App\Models\SeniMatch::create([
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $validated['gender'],
                        'match_category_id' => $validated['match_category_id'],
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'blue',
                        'contingent_id'     => $blueTeam['contingent_id'] ?? null,
                        'team_member_1'     => $b1,
                        'team_member_2'     => $teamSize >= 2 ? $b2 : null,
                        'team_member_3'     => $teamSize >= 3 ? $b3 : null,
                        'status'            => 'not_started',
                        'winner_corner'     => 'blue',
                    ]);
                    $currentRound[] = [null, $blueMatch->id];
                    $battleGroup++;
                    continue;
                }

                // BYE sisi RED
                if ($blueTeam === null && $redTeam !== null) {
                    [$r1, $r2, $r3] = $this->extractMemberIds($redTeam, $teamSize);
                    $redMatch = \App\Models\SeniMatch::create([
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $validated['gender'],
                        'match_category_id' => $validated['match_category_id'],
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'red',
                        'contingent_id'     => $redTeam['contingent_id'] ?? null,
                        'team_member_1'     => $r1,
                        'team_member_2'     => $teamSize >= 2 ? $r2 : null,
                        'team_member_3'     => $teamSize >= 3 ? $r3 : null,
                        'status'            => 'not_started',
                        'winner_corner'     => 'red',
                    ]);
                    $currentRound[] = [$redMatch->id, null];
                    $battleGroup++;
                    continue;
                }

                // NORMAL
                [$b1, $b2, $b3] = $this->extractMemberIds($blueTeam, $teamSize);
                [$r1, $r2, $r3] = $this->extractMemberIds($redTeam,  $teamSize);

                $blueMatch = \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => $battleGroup,
                    'battle_group'      => $battleGroup,
                    'gender'            => $validated['gender'],
                    'match_category_id' => $validated['match_category_id'],
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'blue',
                    'contingent_id'     => $blueTeam['contingent_id'] ?? null,
                    'team_member_1'     => $b1,
                    'team_member_2'     => $teamSize >= 2 ? $b2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $b3 : null,
                    'status'            => 'not_started',
                ]);

                $redMatch = \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => $battleGroup,
                    'battle_group'      => $battleGroup,
                    'gender'            => $validated['gender'],
                    'match_category_id' => $validated['match_category_id'],
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'red',
                    'contingent_id'     => $redTeam['contingent_id'] ?? null,
                    'team_member_1'     => $r1,
                    'team_member_2'     => $teamSize >= 2 ? $r2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $r3 : null,
                    'status'            => 'not_started',
                ]);

                $currentRound[] = [$redMatch->id, $blueMatch->id];
                $battleGroup++;
            }

            // ====== Ronde berikutnya + prefill jika parent BYE ======
            while (count($currentRound) > 1) {
                $round++;
                $roundLabel = $this->getRoundLabel($round, $totalRounds);
                $nextRound  = [];

                for ($j = 0; $j < count($currentRound); $j += 2) {
                    $blueParent = $currentRound[$j][1]   ?? null; // pemenang BLUE kiri
                    $redParent  = $currentRound[$j+1][0] ?? null; // pemenang RED kanan
                    if ($blueParent === null && $redParent === null) continue;

                    $blueNode = \App\Models\SeniMatch::create([
                        'pool_id'              => $pool->id,
                        'match_order'          => $battleGroup,
                        'battle_group'         => $battleGroup,
                        'gender'               => $validated['gender'],
                        'match_category_id'    => $validated['match_category_id'],
                        'match_type'           => $matchType,
                        'mode'                 => 'battle',
                        'round'                => $round,
                        'round_label'          => $roundLabel,
                        'corner'               => 'blue',
                        'parent_match_blue_id' => $blueParent,
                        'status'               => 'not_started',
                    ]);

                    $redNode = \App\Models\SeniMatch::create([
                        'pool_id'              => $pool->id,
                        'match_order'          => $battleGroup,
                        'battle_group'         => $battleGroup,
                        'gender'               => $validated['gender'],
                        'match_category_id'    => $validated['match_category_id'],
                        'match_type'           => $matchType,
                        'mode'                 => 'battle',
                        'round'                => $round,
                        'round_label'          => $roundLabel,
                        'corner'               => 'red',
                        'parent_match_red_id'  => $redParent,
                        'status'               => 'not_started',
                    ]);

                    // Prefill bila parent adalah BYE (tidak ada sibling di ronde sebelumnya)
                    $prevRound = $round - 1;

                    if ($blueParent !== null) {
                        $p = \App\Models\SeniMatch::find($blueParent);
                        if ($p) {
                            $siblingExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                                ->where('round', $prevRound)
                                ->where('battle_group', $p->battle_group)
                                ->where('corner', 'red')
                                ->exists();
                            if (!$siblingExists) {
                                $blueNode->contingent_id = $p->contingent_id;
                                $blueNode->team_member_1 = $p->team_member_1;
                                $blueNode->team_member_2 = $p->team_member_2;
                                $blueNode->team_member_3 = $p->team_member_3;
                                $blueNode->save();
                            }
                        }
                    }

                    if ($redParent !== null) {
                        $p = \App\Models\SeniMatch::find($redParent);
                        if ($p) {
                            $siblingExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                                ->where('round', $prevRound)
                                ->where('battle_group', $p->battle_group)
                                ->where('corner', 'blue')
                                ->exists();
                            if (!$siblingExists) {
                                $redNode->contingent_id = $p->contingent_id;
                                $redNode->team_member_1 = $p->team_member_1;
                                $redNode->team_member_2 = $p->team_member_2;
                                $redNode->team_member_3 = $p->team_member_3;
                                $redNode->save();
                            }
                        }
                    }

                    $nextRound[] = [$redNode->id, $blueNode->id];
                    $battleGroup++;
                }

                $currentRound = $nextRound;
            }
        }

        return response()->json(['message' => 'Battle matches generated with correct full_prestasi logic.']);
    }








    /** Power of two terdekat. Kalau jarak sama, pilih yang lebih kecil. */
    protected function nearestPow2PreferLower(int $n): int
    {
        if ($n <= 1) return 1;
        $lowExp  = (int) floor(log($n, 2));
        $highExp = (int) ceil(log($n, 2));
        $low  = 1 << $lowExp;
        $high = 1 << $highExp;
        $dl = $n - $low;
        $dh = $high - $n;
        if ($dl < $dh) return $low;
        if ($dh < $dl) return $high;
        return $low; // tie → lower
    }






    public function regenerateSamePool(Request $request)
    {
        $validated = $request->validate([
            'tournament_id'     => 'required|exists:tournaments,id',
            'match_category_id' => 'required|in:2,3,4,5',
            'age_category_id'   => 'required|exists:age_categories,id',
            'gender'            => 'required|in:male,female',
            // opsional override (kalau dikirim)
            'mode'              => 'nullable|in:default,battle',
            'bracket_type'      => 'nullable|in:2,4,8,16,full_prestasi',
        ]);

        $tournamentId    = $validated['tournament_id'];
        $matchCategoryId = $validated['match_category_id'];
        $ageCategoryId   = $validated['age_category_id'];
        $gender          = $validated['gender'];

        $requiredMembers = match ($matchCategoryId) {
            3 => 2, // Ganda
            4 => 3, // Regu
            default => 1, // Tunggal / Solo Kreatif
        };

        // Pool yang sudah ada (sticky)
        $pools = \App\Models\SeniPool::where([
            'tournament_id'     => $tournamentId,
            'match_category_id' => $matchCategoryId,
            'age_category_id'   => $ageCategoryId,
            'gender'            => $gender,
        ])->orderBy('id')->get();

        if ($pools->isEmpty()) {
            return response()->json(['message' => 'Tidak ada pool yang tersedia.'], 404);
        }

        // Helper
        $nextPow2 = function (int $n): int {
            if ($n <= 1) return 1;
            return (int) pow(2, ceil(log($n, 2)));
        };

        $matchTypeMap = [
            2 => 'seni_tunggal',
            3 => 'seni_ganda',
            4 => 'seni_regu',
            5 => 'solo_kreatif',
        ];
        $matchType = $matchTypeMap[$matchCategoryId] ?? 'seni_tunggal';

        // === Kumpulkan UNIT dari match lama per pool (sticky membership) ===
        // Format unit: ['contingent_id' => ?, 'member_ids' => [id1, id2?, id3?]]
        $poolUnits = []; // pool_id => array<unit>

        foreach ($pools as $pool) {
            // Mode pool (pakai request override kalau ada, else pakai yang tersimpan di pool)
            $poolMode = $validated['mode'] ?? ($pool->mode ?? 'battle');

            // Ambil semua match lama pool INI (jangan hapus dulu)
            $oldMatches = \App\Models\SeniMatch::where('pool_id', $pool->id)
                ->orderBy('round')
                ->orderBy('battle_group')
                ->orderByRaw("FIELD(corner, 'blue','red')")
                ->get();

            // Ambil unit
            $units = [];

            if ($poolMode === 'default') {
                // setiap row = 1 unit
                foreach ($oldMatches as $m) {
                    if (empty($m->team_member_1)) continue; // skip BYE
                    $memberIds = array_values(array_filter([
                        $m->team_member_1 ?? null,
                        $m->team_member_2 ?? null,
                        $m->team_member_3 ?? null,
                    ], fn($v) => !is_null($v)));

                    $units[] = [
                        'contingent_id' => $m->contingent_id ?? null,
                        'member_ids'    => $memberIds,
                    ];
                }

            } else { // battle
                // Ambil hanya ROUND 1 (yang punya team_member_*), tiap row = 1 unit
                $round1 = $oldMatches->where('round', 1);
                foreach ($round1 as $m) {
                    if (empty($m->team_member_1)) continue; // skip BYE
                    $memberIds = array_values(array_filter([
                        $m->team_member_1 ?? null,
                        $m->team_member_2 ?? null,
                        $m->team_member_3 ?? null,
                    ], fn($v) => !is_null($v)));

                    $units[] = [
                        'contingent_id' => $m->contingent_id ?? null,
                        'member_ids'    => $memberIds,
                    ];
                }
            }

            // Dedup unit (kalau ada duplikat row)
            $seen = [];
            $uniqueUnits = [];
            foreach ($units as $u) {
                $key = ($u['contingent_id'] ?? 'x') . ':' . implode('-', $u['member_ids']);
                if (isset($seen[$key])) continue;
                $seen[$key] = true;
                $uniqueUnits[] = $u;
            }

            $poolUnits[$pool->id] = $uniqueUnits;
        }

        // Cek ada pool yang kosong total unitnya?
        $anyUnits = collect($poolUnits)->flatten(1)->count();
        if ($anyUnits === 0) {
            return response()->json(['message' => 'Tidak ada unit yang bisa dipakai untuk pairing ulang.'], 422);
        }

        // === Baru hapus semua match lama (setelah data unit aman) ===
        $poolIds = $pools->pluck('id');
        \App\Models\SeniMatch::whereIn('pool_id', $poolIds)->delete();

        // Kalau ada override bracket_type di request dan mode battle, simpan ke pool
        if (!empty($validated['bracket_type'])) {
            foreach ($pools as $pool) {
                $pool->bracket_type = $validated['bracket_type'];
                $pool->save();
            }
        }

        // === Bangun ulang per pool (sticky) ===
        foreach ($pools as $pool) {
            $poolMode = $validated['mode'] ?? ($pool->mode ?? 'battle');
            $units    = $poolUnits[$pool->id] ?? [];

            // Kalau kosong, skip pool ini
            if (empty($units)) continue;

            if ($poolMode === 'default') {
                // Tulis ulang 1 row / unit (tanpa bracket/round)
                $order = 1;
                foreach ($units as $u) {
                    $data = [
                        'pool_id'           => $pool->id,
                        'match_order'       => $order++,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'default',
                        'contingent_id'     => $u['contingent_id'],
                        'team_member_1'     => $u['member_ids'][0] ?? null,
                        'status'            => 'not_started',
                    ];
                    if ($requiredMembers >= 2) $data['team_member_2'] = $u['member_ids'][1] ?? null;
                    if ($requiredMembers === 3) $data['team_member_3'] = $u['member_ids'][2] ?? null;

                    \App\Models\SeniMatch::create($data);
                }

            } else {
                // === BATTLE: pairing ulang di DALAM pool ini aja (adaptif) ===
                // Tentukan target size
                $poolBracket = $pool->bracket_type ?? '4';
                $teamCount   = count($units);

                if ($poolBracket === 'full_prestasi') {
                    $targetSize = max(2, $nextPow2($teamCount));
                } else {
                    $requested  = (int) $poolBracket;
                    $targetSize = max(2, min($requested, $nextPow2($teamCount)));
                }
                $maxRound = (int) log($targetSize, 2);

                // Siapkan daftar team (unit) + pad BYE (null)
                $teams = $units; // tiap item: ['contingent_id', 'member_ids'[]]
                while (count($teams) < $targetSize) {
                    $teams[] = null;
                }

                $teams = collect($teams)->shuffle()->values();

                // Pairing prioritas beda kontingen, skip BYE vs BYE
                $pairings = []; // [blueUnit|null, redUnit|null]
                while ($teams->count() > 0) {
                    $blue = $teams->shift();
                    $matched = false;

                    foreach ($teams as $idx => $red) {
                        if ($blue === null && $red === null) {
                            // BYE vs BYE → konsumsi & skip
                            $teams->forget($idx);
                            $teams = $teams->values();
                            $matched = true;
                            break;
                        }
                        if (
                            $blue === null || $red === null ||
                            (($blue['contingent_id'] ?? null) !== ($red['contingent_id'] ?? null))
                        ) {
                            $pairings[] = [$blue, $red];
                            $teams->forget($idx);
                            $teams = $teams->values();
                            $matched = true;
                            break;
                        }
                    }

                    if (!$matched) {
                        $fallback = $teams->shift();
                        if ($blue === null && $fallback === null) {
                            continue; // BYE vs BYE
                        }
                        $pairings[] = [$blue, $fallback];
                    }
                }

                if (count($pairings) === 0) {
                    continue;
                }

                // Round 1
                $round       = 1;
                $roundLabel  = $this->getRoundLabel($round, $maxRound);
                $battleGroup = 1;
                $current     = [];

                foreach ($pairings as [$blue, $red]) {
                    // BLUE row
                    $b = [
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'blue',
                        'contingent_id'     => $blue['contingent_id'] ?? null,
                        'team_member_1'     => $blue['member_ids'][0] ?? null,
                        'status'            => 'not_started',
                    ];
                    if ($requiredMembers >= 2) $b['team_member_2'] = $blue['member_ids'][1] ?? null;
                    if ($requiredMembers === 3) $b['team_member_3'] = $blue['member_ids'][2] ?? null;

                    $blueRow = \App\Models\SeniMatch::create($b);

                    // RED row
                    $r = [
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'red',
                        'contingent_id'     => $red['contingent_id'] ?? null,
                        'team_member_1'     => $red['member_ids'][0] ?? null,
                        'status'            => 'not_started',
                    ];
                    if ($requiredMembers >= 2) $r['team_member_2'] = $red['member_ids'][1] ?? null;
                    if ($requiredMembers === 3) $r['team_member_3'] = $red['member_ids'][2] ?? null;

                    $redRow = \App\Models\SeniMatch::create($r);

                    // simpan pasangan [RED_id, BLUE_id] untuk parent ronde berikutnya
                    $current[] = [$redRow->id, $blueRow->id];
                    $battleGroup++;
                }

                // Ronde berikutnya (node parent saja)
                while (count($current) > 1) {
                    $round++;
                    $roundLabel = $this->getRoundLabel($round, $maxRound);
                    $nextRound  = [];

                    for ($j = 0; $j < count($current); $j += 2) {
                        $blueParent = $current[$j][1]   ?? null;
                        $redParent  = $current[$j+1][0] ?? null;

                        if ($blueParent === null && $redParent === null) {
                            continue;
                        }

                        $blueNode = \App\Models\SeniMatch::create([
                            'pool_id'              => $pool->id,
                            'match_order'          => $battleGroup,
                            'battle_group'         => $battleGroup,
                            'gender'               => $gender,
                            'match_category_id'    => $matchCategoryId,
                            'match_type'           => $matchType,
                            'mode'                 => 'battle',
                            'round'                => $round,
                            'round_label'          => $roundLabel,
                            'corner'               => 'blue',
                            'parent_match_blue_id' => $blueParent,
                            'status'               => 'not_started',
                        ]);

                        $redNode = \App\Models\SeniMatch::create([
                            'pool_id'              => $pool->id,
                            'match_order'          => $battleGroup,
                            'battle_group'         => $battleGroup,
                            'gender'               => $gender,
                            'match_category_id'    => $matchCategoryId,
                            'match_type'           => $matchType,
                            'mode'                 => 'battle',
                            'round'                => $round,
                            'round_label'          => $roundLabel,
                            'corner'               => 'red',
                            'parent_match_red_id'  => $redParent,
                            'status'               => 'not_started',
                        ]);

                        $nextRound[] = [$redNode->id, $blueNode->id];
                        $battleGroup++;
                    }

                    $current = $nextRound;
                }
            }
        }

        return response()->json(['message' => 'Pairing ulang berhasil. Peserta tetap di pool asal.']);
    }

    public function regenerate(Request $request)
    {
        // 1) Validasi scope (tanpa input mode/pool_size/bracket_type)
        $validated = $request->validate([
            'tournament_id'     => 'required|exists:tournaments,id',
            'match_category_id' => 'required|in:2,3,4,5',
            'age_category_id'   => 'required|exists:age_categories,id',
            'gender'            => 'required|in:male,female',
        ]);

        $tournamentId    = (int) $validated['tournament_id'];
        $matchCategoryId = (int) $validated['match_category_id'];
        $ageCategoryId   = (int) $validated['age_category_id'];
        $gender          = $validated['gender'];

        // 2) Tipe & ukuran tim
        [$matchType, $teamSize] = $this->resolveMatchTypeAndSize($matchCategoryId);

        // 3) Ambil pools pada scope ini
        $pools = \App\Models\SeniPool::where([
            'tournament_id'     => $tournamentId,
            'match_category_id' => $matchCategoryId,
            'age_category_id'   => $ageCategoryId,
            'gender'            => $gender,
        ])->orderBy('id')->get();

        if ($pools->isEmpty()) {
            return response()->json(['message' => 'Tidak ada pool yang tersedia.'], 404);
        }

        // 4) Baca konfigurasi pool (mode & bracket_type) SEBELUM hapus match
        $poolConfigs = [];
        foreach ($pools as $pool) {
            $mode = $pool->mode ?? null;
            $brkt = $pool->bracket_type ?? null;

            if ($mode === null || ($mode === 'battle' && $brkt === null)) {
                $old = \App\Models\SeniMatch::where('pool_id', $pool->id)
                    ->select('mode','round')
                    ->get();

                if ($mode === null && $old->isNotEmpty()) {
                    $mode = $old->contains(fn($m) => $m->mode === 'battle') ? 'battle' : 'default';
                }
                if ($mode === 'battle' && $brkt === null) {
                    $maxRound = (int) $old->max('round');
                    if ($maxRound > 0) {
                        $pow  = 1 << $maxRound; // 2^maxRound
                        $brkt = in_array($pow, [2,4,8,16,32,64], true) ? (string)$pow : 'full_prestasi';
                    }
                }
            }

            if ($mode === null) $mode = 'default';
            if ($mode === 'battle' && $brkt === null) $brkt = 'full_prestasi';

            $poolConfigs[$pool->id] = ['mode' => $mode, 'bracket_type' => $brkt];
        }

        // 5) Hapus semua match lama (pool dipertahankan)
        $poolIds = $pools->pluck('id');
        \App\Models\SeniMatch::whereIn('pool_id', $poolIds)->delete();

        // 6) Ambil peserta valid
        $participants = \App\Models\TournamentParticipant::where('tournament_id', $tournamentId)
            ->whereHas('participant', function ($q) use ($matchCategoryId, $ageCategoryId, $gender) {
                $q->where('match_category_id', $matchCategoryId)
                ->where('age_category_id',  $ageCategoryId)
                ->where('gender',           $gender);
            })
            ->with('participant')
            ->get()
            ->filter(fn($tp) => $tp->participant !== null)
            ->values();

        if ($participants->isEmpty()) {
            return response()->json(['message' => 'Tidak ada peserta ditemukan.'], 404);
        }

        // 7) Bentuk tim (helper existing)
        $teams = $this->groupIntoTeams($participants, $teamSize);
        if ($teams->isEmpty()) {
            return response()->json(['message' => 'Tidak ada tim valid yang bisa dibentuk.'], 422);
        }

        // 8) Distribusikan tim ke pools (round-robin)
        $teamArr   = $teams->values()->all();
        $poolCount = $pools->count();
        $buckets   = array_fill(0, $poolCount, []);
        foreach ($teamArr as $idx => $team) {
            $buckets[$idx % $poolCount][] = $team;
        }

        // 9) Bangun ulang per pool sesuai config
        foreach ($pools as $i => $pool) {
            $cfg           = $poolConfigs[$pool->id] ?? ['mode' => 'default', 'bracket_type' => null];
            $poolMode      = ($cfg['mode'] === 'battle') ? 'battle' : 'default';
            $bracketType   = $cfg['bracket_type'] ?: 'full_prestasi';
            $assignedTeams = $buckets[$i] ?? [];

            if (empty($assignedTeams)) continue;

            if ($poolMode !== 'battle') {
                // =======================
                // DEFAULT / NON-BATTLE
                // =======================
                $order = 1;
                foreach ($assignedTeams as $t) {
                    [$m1, $m2, $m3] = $this->extractMemberIds($t, $teamSize);

                    $data = [
                        'pool_id'           => $pool->id,
                        'match_order'       => $order++,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'default',
                        'contingent_id'     => $t['contingent_id'] ?? null,
                        'team_member_1'     => $m1,
                        'status'            => 'not_started',
                    ];
                    if ($teamSize >= 2) $data['team_member_2'] = $m2;
                    if ($teamSize === 3) $data['team_member_3'] = $m3;

                    \App\Models\SeniMatch::create($data);
                }
                continue;
            }

            // =======================
            // BATTLE / BRACKET
            // =======================
            $bag = collect($assignedTeams)->shuffle()->values();
            $N   = $bag->count();
            if ($N === 0) continue;

            // === Tentukan K (jumlah tim target bracket) ===
            if ($bracketType === 'full_prestasi') {
                // pakai ceil ke pangkat 2 terdekat
                $K = max(2, $this->nextPow2($N));
            } else {
                $requested = max(2, (int)$bracketType); // 2/4/8/16
                $K = $requested;
            }

            $pairings = []; // list pasangan [blueTeam|null, redTeam|null]
            $battleGroup = 1;

            if ($N <= $K) {
                // ---------- N ≤ K : BYE hanya jika N < K ----------
                $byeCount    = $K - $N;          // jumlah sisi kosong
                $targetPairs = intdiv($K, 2);    // pertandingan ronde 1
                $byeBlueSide = true;

                // (1) selipkan BYE
                for ($b = 0; $b < $byeCount && $bag->count() > 0; $b++) {
                    $team = $bag->shift();
                    $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
                    $byeBlueSide = !$byeBlueSide;
                }

                // (2) sisa tim dipasangkan normal
                while (count($pairings) < $targetPairs && $bag->count() > 0) {
                    $blue = $bag->shift();
                    $red  = $bag->shift();
                    if ($blue === null && $red === null) {
                        $pairings[] = [null, null];
                    } elseif ($red === null) {
                        $pairings[] = [$blue, null];
                    } else {
                        $pairings[] = [$blue, $red];
                    }
                }

                // (3) tambal kalau kurang
                while (count($pairings) < $targetPairs) {
                    $pairings[] = [null, null];
                }

                $totalRounds = (int) log($K, 2);
            } else {
                // ---------- N > K : ada PLAY-IN ----------
                $P = $N - $K; // jumlah play-in

                // (1) play-in dulu
                for ($m = 0; $m < $P && $bag->count() > 0; $m++) {
                    $blue = $bag->shift();
                    $red  = $bag->shift();
                    if ($blue === null && $red === null) break;
                    if ($red === null) {
                        $pairings[] = [$blue, null];
                    } else {
                        $pairings[] = [$blue, $red];
                    }
                }

                // (2) sisanya jadi direct entry (BYE)
                $byeBlueSide = true;
                while (count($pairings) < $K && $bag->count() > 0) {
                    $team = $bag->shift();
                    $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
                    $byeBlueSide = !$byeBlueSide;
                }

                // (3) tambal jika kurang
                while (count($pairings) < $K) {
                    $pairings[] = [null, null];
                }

                $totalRounds = (int) log($K, 2) + 1; // +1 karena ada ronde play-in
            }

            if (!count($pairings)) continue;

            // ===== Ronde 1 — BYE = 1 row + winner_corner =====
            $round       = 1;
            $roundLabel  = $this->getRoundLabel($round, $totalRounds);
            $currentRound = []; // simpan [redMatchId|null, blueMatchId|null]

            foreach ($pairings as [$blueTeam, $redTeam]) {
                // dua sisi null
                if ($blueTeam === null && $redTeam === null) {
                    $currentRound[] = [null, null];
                    $battleGroup++;
                    continue;
                }

                // BYE sisi BLUE
                if ($blueTeam !== null && $redTeam === null) {
                    [$b1, $b2, $b3] = $this->extractMemberIds($blueTeam, $teamSize);
                    $blueMatch = \App\Models\SeniMatch::create([
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'blue',
                        'contingent_id'     => $blueTeam['contingent_id'] ?? null,
                        'team_member_1'     => $b1,
                        'team_member_2'     => $teamSize >= 2 ? $b2 : null,
                        'team_member_3'     => $teamSize >= 3 ? $b3 : null,
                        'status'            => 'not_started',
                        'winner_corner'     => 'blue',
                    ]);
                    $currentRound[] = [null, $blueMatch->id];
                    $battleGroup++;
                    continue;
                }

                // BYE sisi RED
                if ($blueTeam === null && $redTeam !== null) {
                    [$r1, $r2, $r3] = $this->extractMemberIds($redTeam, $teamSize);
                    $redMatch = \App\Models\SeniMatch::create([
                        'pool_id'           => $pool->id,
                        'match_order'       => $battleGroup,
                        'battle_group'      => $battleGroup,
                        'gender'            => $gender,
                        'match_category_id' => $matchCategoryId,
                        'match_type'        => $matchType,
                        'mode'              => 'battle',
                        'round'             => $round,
                        'round_label'       => $roundLabel,
                        'corner'            => 'red',
                        'contingent_id'     => $redTeam['contingent_id'] ?? null,
                        'team_member_1'     => $r1,
                        'team_member_2'     => $teamSize >= 2 ? $r2 : null,
                        'team_member_3'     => $teamSize >= 3 ? $r3 : null,
                        'status'            => 'not_started',
                        'winner_corner'     => 'red',
                    ]);
                    $currentRound[] = [$redMatch->id, null];
                    $battleGroup++;
                    continue;
                }

                // NORMAL
                [$b1, $b2, $b3] = $this->extractMemberIds($blueTeam, $teamSize);
                [$r1, $r2, $r3] = $this->extractMemberIds($redTeam,  $teamSize);

                $blueMatch = \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => $battleGroup,
                    'battle_group'      => $battleGroup,
                    'gender'            => $gender,
                    'match_category_id' => $matchCategoryId,
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'blue',
                    'contingent_id'     => $blueTeam['contingent_id'] ?? null,
                    'team_member_1'     => $b1,
                    'team_member_2'     => $teamSize >= 2 ? $b2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $b3 : null,
                    'status'            => 'not_started',
                ]);

                $redMatch = \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => $battleGroup,
                    'battle_group'      => $battleGroup,
                    'gender'            => $gender,
                    'match_category_id' => $matchCategoryId,
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'red',
                    'contingent_id'     => $redTeam['contingent_id'] ?? null,
                    'team_member_1'     => $r1,
                    'team_member_2'     => $teamSize >= 2 ? $r2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $r3 : null,
                    'status'            => 'not_started',
                ]);

                $currentRound[] = [$redMatch->id, $blueMatch->id];
                $battleGroup++;
            }

            // ===== Ronde berikutnya + prefill jika parent BYE =====
            while (count($currentRound) > 1) {
                $round++;
                $roundLabel = $this->getRoundLabel($round, $totalRounds);
                $nextRound  = [];

                for ($j = 0; $j < count($currentRound); $j += 2) {
                    $blueParent = $currentRound[$j][1]   ?? null; // pemenang BLUE kiri
                    $redParent  = $currentRound[$j+1][0] ?? null; // pemenang RED kanan
                    if ($blueParent === null && $redParent === null) continue;

                    $blueNode = \App\Models\SeniMatch::create([
                        'pool_id'              => $pool->id,
                        'match_order'          => $battleGroup,
                        'battle_group'         => $battleGroup,
                        'gender'               => $gender,
                        'match_category_id'    => $matchCategoryId,
                        'match_type'           => $matchType,
                        'mode'                 => 'battle',
                        'round'                => $round,
                        'round_label'          => $roundLabel,
                        'corner'               => 'blue',
                        'parent_match_blue_id' => $blueParent,
                        'status'               => 'not_started',
                    ]);

                    $redNode = \App\Models\SeniMatch::create([
                        'pool_id'              => $pool->id,
                        'match_order'          => $battleGroup,
                        'battle_group'         => $battleGroup,
                        'gender'               => $gender,
                        'match_category_id'    => $matchCategoryId,
                        'match_type'           => $matchType,
                        'mode'                 => 'battle',
                        'round'                => $round,
                        'round_label'          => $roundLabel,
                        'corner'               => 'red',
                        'parent_match_red_id'  => $redParent,
                        'status'               => 'not_started',
                    ]);

                    // Prefill bila parent adalah BYE (tak punya sibling di ronde sebelumnya)
                    $prevRound = $round - 1;

                    if ($blueParent !== null) {
                        $p = \App\Models\SeniMatch::find($blueParent);
                        if ($p) {
                            $siblingExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                                ->where('round', $prevRound)
                                ->where('battle_group', $p->battle_group)
                                ->where('corner', 'red')
                                ->exists();
                            if (!$siblingExists) {
                                $blueNode->contingent_id = $p->contingent_id;
                                $blueNode->team_member_1 = $p->team_member_1;
                                $blueNode->team_member_2 = $p->team_member_2;
                                $blueNode->team_member_3 = $p->team_member_3;
                                $blueNode->save();
                            }
                        }
                    }

                    if ($redParent !== null) {
                        $p = \App\Models\SeniMatch::find($redParent);
                        if ($p) {
                            $siblingExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                                ->where('round', $prevRound)
                                ->where('battle_group', $p->battle_group)
                                ->where('corner', 'blue')
                                ->exists();
                            if (!$siblingExists) {
                                $redNode->contingent_id = $p->contingent_id;
                                $redNode->team_member_1 = $p->team_member_1;
                                $redNode->team_member_2 = $p->team_member_2;
                                $redNode->team_member_3 = $p->team_member_3;
                                $redNode->save();
                            }
                        }
                    }

                    $nextRound[] = [$redNode->id, $blueNode->id];
                    $battleGroup++;
                }

                $currentRound = $nextRound;
            }
        }

        return response()->json([
            'message' => 'Regenerate berhasil. Mode dipertahankan, bracket konsisten, dan BYE auto-advance.',
        ]);
    }




    public function getParticipantCounts(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'match_category_id' => 'required|in:2,3,4,5',
        ]);
    
        $matchCategoryId = $request->match_category_id;
    
        $baseQuery = TournamentParticipant::where('tournament_id', $request->tournament_id)
            ->whereHas('participant', function ($q) use ($request) {
                $q->where('age_category_id', $request->age_category_id)
                  ->where('match_category_id', $request->match_category_id);
            })
            ->with('participant');
    
        $all = $baseQuery->get()->filter(fn($tp) => $tp->participant !== null);
    
        // Hitung total unit penampilan berdasarkan jenis seni
        $groupedByGender = $all->groupBy(fn($tp) => $tp->participant->gender);
    
        $result = [];
    
        foreach (['male', 'female'] as $gender) {
            $filtered = $groupedByGender[$gender] ?? collect();
    
            if ($matchCategoryId == 2 || $matchCategoryId == 5) {
                // Tunggal
                $result[$gender] = $filtered->count();
            } else {
                // Ganda / Regu → group by contingent
                $required = $matchCategoryId == 3 ? 2 : 3;
    
                $validContingents = $filtered->groupBy(fn($tp) => $tp->participant->contingent_id)
                    ->filter(fn($group) => $group->count() >= $required)
                    ->keys();
    
                $result[$gender] = $validContingents->count();
            }
        }
    
        return response()->json($result);
    }
    






}
