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
use Illuminate\Support\Facades\Schema;
use Faker\Factory as Faker;


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

    // app/Http/Controllers/SeniMatchController.php

    public function bracketByPool(Request $request)
    {
        $validated = $request->validate([
            'pool_id' => 'required|exists:seni_pools,id',
        ]);

        $poolId = (int) $validated['pool_id'];

        // Ambil semua match battle pada pool tsb
        $matches = \App\Models\SeniMatch::with([
                'contingent',
                'teamMember1',
                'teamMember2',
                'teamMember3',
            ])
            ->where('pool_id', $poolId)
            ->where('mode', 'battle')
            ->orderBy('round')
            ->orderBy('battle_group')
            ->orderByRaw("CASE WHEN corner='blue' THEN 0 ELSE 1 END")
            ->get();

        // Map id -> scheduled order (kalau sudah pernah dijadwalkan)
        $scheduleOrders = \App\Models\MatchScheduleDetail::whereIn('seni_match_id', $matches->pluck('id'))
            ->pluck('order', 'seni_match_id'); // [seni_match_id => order]

        // Helper: ambil nomor partai pemenang (kalau parent punya order schedule)
        $winnerOrderOf = function (\App\Models\SeniMatch $m, $scheduleOrders) {
            // Parent utk sisi ini (blue → parent_match_blue_id; red → parent_match_red_id)
            $parentId = $m->corner === 'blue' ? $m->parent_match_blue_id : $m->parent_match_red_id;
            if ($parentId) {
                return $scheduleOrders[$parentId] ?? null; // nomor partai dari jadwal
            }
            return null;
        };

        $payloadMatches = $matches->map(function ($m) use ($scheduleOrders, $winnerOrderOf) {
            return [
                'id'            => $m->id,
                'round'         => (int) $m->round,
                'round_label'   => $m->round_label,
                'battle_group'  => (int) $m->battle_group,
                'order'         => (int) ($m->match_order ?? $m->battle_group ?? 0), // fallback aman
                'corner'        => $m->corner,
                'mode'          => $m->mode,

                // data peserta
                'contingent'    => optional($m->contingent)?->only(['id','name']),
                'team_member1'  => optional($m->teamMember1)?->only(['id','name']),
                'team_member2'  => optional($m->teamMember2)?->only(['id','name']),
                'team_member3'  => optional($m->teamMember3)?->only(['id','name']),

                // untuk UI "Pemenang partai #X" (front-end kamu sudah support ini)
                'winner_of_order' => $winnerOrderOf($m, $scheduleOrders),

                // opsional (kalau mau tampilkan di modal)
                'match_time'    => null,
                'final_score'   => null,
            ];
        })->values();

        return response()->json([
            'data' => [
                'type'    => 1,          // biar front-end treat sebagai "normal rounds"
                'matches' => $payloadMatches,
            ],
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

    // ============ Index bantu: seni_match_id => order ============
    $orderBySeniId = [];
    foreach ($details as $d) {
        if ($d->seni_match_id && $d->order) {
            $orderBySeniId[$d->seni_match_id] = (int) $d->order;
        }
    }

    // ============ Peta generik: per KELAS → per ROUND → per BATTLE_GROUP => ORDER ============
    // KELAS = pool_name|category|gender (biar konsisten sama grouping FE)
    $ordersByClassRoundGroup = []; // [classKey][round][battle_group] = min(order)
    $minRoundByClass         = []; // [classKey] = round terkecil yg ada (earliest)
    foreach ($details as $d) {
        $m = $d->seniMatch;
        if (!$m) continue;
        if (($m->mode ?? null) !== 'battle') continue;

        $poolName  = $m->pool->name ?? 'Tanpa Pool';
        $category  = $m->matchCategory->name ?? '-';
        $gender    = $m->gender ?? '-';
        $classKey  = $poolName.'|'.$category.'|'.$gender;

        $round  = (int) ($m->round ?? 0);
        $group  = (int) ($m->battle_group ?? 0);
        $order  = (int) ($d->order ?? 0);

        if ($round <= 0 || $group <= 0 || $order <= 0) continue;

        // simpan min order untuk kombinasi tsb
        if (!isset($ordersByClassRoundGroup[$classKey][$round][$group])) {
            $ordersByClassRoundGroup[$classKey][$round][$group] = $order;
        } else {
            $ordersByClassRoundGroup[$classKey][$round][$group] = min(
                $ordersByClassRoundGroup[$classKey][$round][$group],
                $order
            );
        }

        // track earliest round per kelas
        if (!isset($minRoundByClass[$classKey])) {
            $minRoundByClass[$classKey] = $round;
        } else {
            $minRoundByClass[$classKey] = min($minRoundByClass[$classKey], $round);
        }
    }

    // ============ Build payload ============
    $grouped = [];

    foreach ($details as $detail) {
        $match = $detail->seniMatch;
        if (!$match) continue;

        $arenaName     = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $scheduledDate = $detail->schedule->scheduled_date ?? 'Tanpa Tanggal';
        $poolName      = $match->pool->name ?? 'Tanpa Pool';
        $category      = $match->matchCategory->name ?? '-';
        $gender        = $match->gender ?? '-';
        $matchType     = $match->match_type;
        $ageCategory   = optional($match->pool?->ageCategory)->name ?? '-';

        // ====== Resolve "Pemenang Partai #X" (untuk SF/Final/selanjutnya) ======
        $hasMembers = ($match->teamMember1 || $match->teamMember2 || $match->teamMember3);

        $winnerOfOrder    = null; // alias untuk corner aktif (kompatibel FE existing)
        $sourceBlueOrder  = null; // spesifik per corner
        $sourceRedOrder   = null;

        if (($match->mode ?? null) === 'battle' && !$hasMembers) {
            // 1) Explicit pointer (kalau ada di schema)
            $sourceType = $match->source_type ?? null;
            $sourceFrom = $match->source_from_seni_match_id ?? null;
            if ($sourceType === 'winner' && $sourceFrom) {
                $winnerOfOrder = $orderBySeniId[$sourceFrom] ?? null;
            }

            // 2) Fallback generik: ambil dari ROUND SEBELUMNYA via battle_group
            $classKey = $poolName.'|'.$category.'|'.$gender;
            $round    = (int) ($match->round ?? 0);
            $groupNo  = (int) ($match->battle_group ?? 0);
            $corner   = strtolower($match->corner ?? '');

            if ($round > 0 && $groupNo > 0) {
                $earliest = $minRoundByClass[$classKey] ?? 1;
                $prevR    = $round - 1;

                if ($prevR >= $earliest) {
                    $prevMap = $ordersByClassRoundGroup[$classKey][$prevR] ?? [];

                    // rumus pairing: curr group g berasal dari prev groups (2g-1, 2g)
                    $blueGroup = ($groupNo * 2) - 1;
                    $redGroup  = ($groupNo * 2);

                    $sourceBlueOrder = $prevMap[$blueGroup] ?? null;
                    $sourceRedOrder  = $prevMap[$redGroup]  ?? null;

                    // fallback lembut kalau salah satu kosong (misal BYE)
                    if (!$sourceBlueOrder && !empty($prevMap)) {
                        $sourceBlueOrder = $prevMap[min(array_keys($prevMap))] ?? null;
                    }
                    if (!$sourceRedOrder && !empty($prevMap)) {
                        $sourceRedOrder = $prevMap[max(array_keys($prevMap))] ?? null;
                    }

                    // isi alias sesuai corner biar kompatibel dgn FE lama
                    if (!$winnerOfOrder) {
                        if ($corner === 'blue')      $winnerOfOrder = $sourceBlueOrder;
                        elseif ($corner === 'red')   $winnerOfOrder = $sourceRedOrder;
                    }
                }
            }
        }

        $groupKey    = $arenaName . '||' . $scheduledDate;
        $categoryKey = $category . '|' . $gender . '|' . $ageCategory;

        // Label kelas (biar rapi)
        $genderLabel = ($gender === 'male') ? 'PUTRA' : (($gender === 'female') ? 'PUTRI' : strtoupper($gender));
        $classLabel  = strtoupper(trim(($category ? $category.' ' : '').($ageCategory ? $ageCategory.' ' : '').$genderLabel));

        $matchData = [
            'id'              => $match->id,
            'match_order'     => $detail->order,
            'match_time'      => $detail->start_time,
            'mode'            => $match->mode,
            'corner'          => $match->corner,
            'round_label'     => $detail->round_label,
            'battle_group'    => $match->battle_group,
            'contingent'      => optional($match->contingent)?->only(['id', 'name']),
            'team_member1'    => optional($match->teamMember1)?->only(['id', 'name']),
            'team_member2'    => optional($match->teamMember2)?->only(['id', 'name']),
            'team_member3'    => optional($match->teamMember3)?->only(['id', 'name']),
            'match_type'      => $matchType,
            'scheduled_date'  => $scheduledDate,
            'tournament_name' => $tournamentName,
            'arena_name'      => $arenaName,
            'pool'            => [
                'name'         => $poolName,
                'age_category' => ['name' => $ageCategory],
            ],

            // extra utk FE
            'gender'         => $gender,
            'category_name'  => $category,
            'class_label'    => $classLabel,

            // pointer winner-of
            'source_type'       => $match->source_type ?? null,
            'source_from_order' => $winnerOfOrder,     // alias lama
            'winner_of_order'   => $winnerOfOrder,     // alias lama

            // per-corner source (baru, recommended dipakai FE)
            'source_blue_order' => $sourceBlueOrder,
            'source_red_order'  => $sourceRedOrder,
        ];

        $grouped[$groupKey]['arena_name']      = $arenaName;
        $grouped[$groupKey]['scheduled_date']  = $scheduledDate;
        $grouped[$groupKey]['tournament_name'] = $tournamentName;

        $grouped[$groupKey]['groups'][$categoryKey]['category']     = $category;
        $grouped[$groupKey]['groups'][$categoryKey]['gender']       = $gender;
        $grouped[$groupKey]['groups'][$categoryKey]['age_category'] = $ageCategory;

        $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['name'] = $poolName;
        $grouped[$groupKey]['groups'][$categoryKey]['pools'][$poolName]['matches'][] = $matchData;
    }

    // Format akhir sesuai struktur lama
    $result = [];
    foreach ($grouped as $entry) {
        $groups = [];
        foreach ($entry['groups'] as $group) {
            $pools = [];
            foreach ($group['pools'] as $pool) {
                $pools[] = [
                    'name'    => $pool['name'],
                    'matches' => $pool['matches'],
                ];
            }
            $groups[] = [
                'category'      => $group['category'],
                'gender'        => $group['gender'],
                'age_category'  => $group['age_category'],
                'pools'         => $pools,
            ];
        }
        $result[] = [
            'arena_name'      => $entry['arena_name'],
            'scheduled_date'  => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'groups'          => $groups,
        ];
    }

    return response()->json(['data' => $result]);
}


   public function getSchedules_backup($slug)
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

        // ================== PATCH: index bantu ==================
        // Map: seni_match_id => order (untuk resolve winner_of_order via pointer)
        $orderBySeniId = [];
        foreach ($details as $d) {
            if ($d->seni_match_id && $d->order) {
                $orderBySeniId[$d->seni_match_id] = (int) $d->order;
            }
        }

        // Fallback FINAL ← SEMIFINAL (tanpa pointer):
        // key: pool|category|gender => [ battle_group => minOrder ]
        $semiOrdersByClass = [];
        foreach ($details as $d) {
            $m = $d->seniMatch;
            if (!$m) continue;
            if (($m->mode ?? null) !== 'battle') continue;

            $roundLabel = $d->round_label;
            if (!is_string($roundLabel)) $roundLabel = (string)$roundLabel;

            // deteksi semifinal (case-insensitive)
            if (mb_strtolower($roundLabel) !== 'semifinal') continue;

            $poolName    = $m->pool->name ?? 'Tanpa Pool';
            $category    = $m->matchCategory->name ?? '-';
            $gender      = $m->gender ?? '-';
            $classKey    = $poolName.'|'.$category.'|'.$gender;
            $groupNo     = (int)($m->battle_group ?? 0);
            $order       = (int)($d->order ?? 0);

            if ($groupNo <= 0 || $order <= 0) continue;

            if (!isset($semiOrdersByClass[$classKey])) {
                $semiOrdersByClass[$classKey] = [];
            }
            // Simpan order terkecil per battle_group (dua sisi blue/red akan merge jadi satu)
            if (!isset($semiOrdersByClass[$classKey][$groupNo])) {
                $semiOrdersByClass[$classKey][$groupNo] = $order;
            } else {
                $semiOrdersByClass[$classKey][$groupNo] = min($semiOrdersByClass[$classKey][$groupNo], $order);
            }
        }
        // ================== /PATCH index bantu ==================

        $grouped = [];

        foreach ($details as $detail) {
            $match = $detail->seniMatch;
            if (!$match) continue;

            $arenaName     = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $scheduledDate = $detail->schedule->scheduled_date ?? 'Tanpa Tanggal';
            $poolName      = $match->pool->name ?? 'Tanpa Pool';
            $category      = $match->matchCategory->name ?? '-';
            $gender        = $match->gender ?? '-';
            $matchType     = $match->match_type;
            $ageCategory   = optional($match->pool?->ageCategory)->name ?? '-';

            // ================== PATCH: winner_of_order resolve ==================
            $hasMembers = ($match->teamMember1 || $match->teamMember2 || $match->teamMember3);
            $winnerOfOrder = null;

            if (($match->mode ?? null) === 'battle' && !$hasMembers) {
                // 1) Prefer pointer eksplisit (jika ada kolomnya)
                $sourceType = $match->source_type ?? null;                         // pastikan kolom ini ada di schema
                $sourceFrom = $match->source_from_seni_match_id ?? null;           // pastikan kolom ini ada di schema
                if ($sourceType === 'winner' && $sourceFrom) {
                    $winnerOfOrder = $orderBySeniId[$sourceFrom] ?? null;
                }

                // 2) Fallback FINAL ← SEMIFINAL berdasar battle_group (kalau pointer tidak ada)
                if (!$winnerOfOrder) {
                    $roundLabel = $detail->round_label;
                    if (!is_string($roundLabel)) $roundLabel = (string)$roundLabel;

                    if (mb_strtolower($roundLabel) === 'final') {
                        $classKey = $poolName.'|'.$category.'|'.$gender;
                        $groups   = $semiOrdersByClass[$classKey] ?? [];

                        if (!empty($groups)) {
                            // pilih group terkecil utk BIRU, terbesar utk MERAH
                            $minGroup = min(array_keys($groups));
                            $maxGroup = max(array_keys($groups));
                            $corner   = strtolower($match->corner ?? '');

                            if ($corner === 'blue') {
                                $winnerOfOrder = $groups[$minGroup] ?? null;
                            } elseif ($corner === 'red') {
                                $winnerOfOrder = $groups[$maxGroup] ?? null;
                            }
                        }
                    }
                }
            }
            // ================== /PATCH: winner_of_order resolve ==================

            $groupKey    = $arenaName . '||' . $scheduledDate;
            $categoryKey = $category . '|' . $gender . '|' . $ageCategory;

            // ================== PATCH: tambah gender, category_name, class_label di payload ==================
            $genderLabel = ($gender === 'male') ? 'PUTRA' : (($gender === 'female') ? 'PUTRI' : strtoupper($gender));
            $classLabel  = strtoupper(trim(($category ? $category.' ' : '').($ageCategory ? $ageCategory.' ' : '').$genderLabel));
            // ================================================================================================

            $matchData = [
                'id'            => $match->id,
                'match_order'   => $detail->order,
                'match_time'    => $detail->start_time,
                'mode'          => $match->mode,
                'corner'        => $match->corner,
                'round_label'   => $detail->round_label,
                'battle_group'  => $match->battle_group,
                'contingent'    => optional($match->contingent)?->only(['id', 'name']),
                'team_member1'  => optional($match->teamMember1)?->only(['id', 'name']),
                'team_member2'  => optional($match->teamMember2)?->only(['id', 'name']),
                'team_member3'  => optional($match->teamMember3)?->only(['id', 'name']),
                'match_type'    => $matchType,
                'scheduled_date'=> $scheduledDate,
                'tournament_name'=> $tournamentName,
                'arena_name'    => $arenaName,
                'pool'          => [
                    'name'          => $poolName,
                    'age_category'  => ['name' => $ageCategory],
                ],

                // ====== extra untuk FE ======
                'gender'          => $gender,
                'category_name'   => $category,
                'class_label'     => $classLabel,

                // ====== winner-of (untuk render "Pemenang partai #X") ======
                'source_type'       => $match->source_type ?? null,
                'source_from_order' => $winnerOfOrder,   // alias
                'winner_of_order'   => $winnerOfOrder,
            ];

            $grouped[$groupKey]['arena_name']      = $arenaName;
            $grouped[$groupKey]['scheduled_date']  = $scheduledDate;
            $grouped[$groupKey]['tournament_name'] = $tournamentName;

            $grouped[$groupKey]['groups'][$categoryKey]['category']     = $category;
            $grouped[$groupKey]['groups'][$categoryKey]['gender']       = $gender;
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
                        'name'    => $pool['name'],
                        'matches' => $pool['matches'],
                    ];
                }

                $groups[] = [
                    'category'      => $group['category'],
                    'gender'        => $group['gender'],
                    'age_category'  => $group['age_category'],
                    'pools'         => $pools,
                ];
            }

            $result[] = [
                'arena_name'      => $entry['arena_name'],
                'scheduled_date'  => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'groups'          => $groups,
            ];
        }

        return response()->json(['data' => $result]);
    }


    public function getAvailableRounds(Request $request, $tournamentId)
    {
        $mode            = $request->query('mode', 'battle');
        $matchCategoryId = $request->query('match_category_id');
        $ageCategoryId   = $request->query('age_category_id');
        $gender          = $request->query('gender');
        $poolFilter      = $request->query('pool'); // string atau array

        // Normalisasi pool filter menjadi array of names
        $poolNames = [];
        if (is_array($poolFilter)) {
            $poolNames = array_filter(array_map('strval', $poolFilter));
        } elseif (is_string($poolFilter) && trim($poolFilter) !== '') {
            $poolNames = [trim($poolFilter)];
        }

        // Join ke pools biar bisa filter tournament/age/gender
        $q = SeniMatch::query()
            ->select('seni_matches.round', 'seni_matches.round_label')
            ->join('seni_pools as p', 'p.id', '=', 'seni_matches.pool_id')
            ->where('p.tournament_id', $tournamentId);

        // Mode (default ke battle)
        if ($mode) {
            $q->where('seni_matches.mode', $mode);
        }

        // Filter opsional
        if (!empty($matchCategoryId)) {
            $q->where('seni_matches.match_category_id', (int) $matchCategoryId);
        }
        if (!empty($ageCategoryId)) {
            $q->where('p.age_category_id', (int) $ageCategoryId);
        }
        if (!empty($gender)) {
            $q->where('p.gender', $gender);
        }
        if (!empty($poolNames)) {
            $q->whereIn('p.name', $poolNames);
        }

        // Ambil distinct pasangan (round, round_label)
        $rows = $q->whereNotNull('seni_matches.round')
                  ->distinct()
                  ->orderBy('seni_matches.round')
                  ->get();

        // Map label: pakai round_label kalau ada, fallback "Babak {round}"
        $labels = $rows->map(function ($r) {
                        $label = $this->fallbackRoundLabel($r->round_label, $r->round);
                        return [
                            'round' => (int) $r->round,
                            'label' => $label,
                        ];
                    })
                    // unik by label supaya gak dobel (misal banyak pool)
                    ->unique(fn($x) => $x['label'])
                    // sort lagi by round asc (kalau ada campuran)
                    ->sortBy('round')
                    ->values();

        // Balikin dalam bentuk object mapping, cocok sama front-end kamu
        $mapping = [];
        foreach ($labels as $it) {
            $mapping[$it['label']] = $it['label'];
        }

        return response()->json([
            'rounds' => $mapping,
            // bonus: ordered list kalau suatu saat mau dipakai
            'ordered' => $labels,
        ]);
    }

    private function fallbackRoundLabel(?string $label, ?int $round): string
    {
        $label = is_string($label) ? trim($label) : '';
        if ($label !== '') return $label;
        return $round ? "Babak {$round}" : "Babak";
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
    $tournamentId     = $request->query('tournament_id');
    $includeScheduled = $request->boolean('include_scheduled');
    $mode             = $request->query('mode'); // optional

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

    // Exclude yang sudah dijadwalkan (kecuali mode edit)
    if (!$includeScheduled) {
        $query->whereNotExists(function ($sub) {
            $sub->select(DB::raw(1))
                ->from('match_schedule_details')
                ->whereColumn('match_schedule_details.seni_match_id', 'seni_matches.id');
        });
    }

    // Filter tournament
    if ($tournamentId) {
        $query->whereHas('pool', function ($q) use ($tournamentId) {
            $q->where('tournament_id', $tournamentId);
        });
    }

    // (opsional) batasi mode
    if ($mode) {
        $query->where('mode', $mode);
    }

    $matches = $query->get();

    /**
     * ====== HIDE BYE HANYA DI BABAK AWAL PER POOL (MODE BATTLE) ======
     * - Tentukan earliest round per pool (mis. 1, bisa >1 kalau bracket tertentu)
     * - Untuk setiap battle_group, cek jumlah entri di earliest round pool tsb.
     * - Jika jumlah entri < 2 ⇒ itu BYE babak awal ⇒ sembunyikan entri2 di earliest round itu.
     * - Babak selain earliest round TIDAK DISENTUH (tetap muncul meski masih TBD/menunggu pemenang).
     */
    $battle = $matches->where('mode', 'battle');

    if ($battle->isNotEmpty()) {
        // Earliest round per pool (khusus battle)
        $minRoundByPool = $battle
            ->groupBy('pool_id')
            ->map(fn($col) => (int) $col->min('round'));

        // Kelompokkan per battle_group (abaikan yang battle_group null/kosong)
        $groups = $battle
            ->filter(fn($m) => !empty($m->battle_group))
            ->groupBy('battle_group');

        $idsToHide = collect();

        foreach ($groups as $groupMatches) {
            /** @var \App\Models\SeniMatch $first */
            $first = $groupMatches->first();
            $poolId = $first->pool_id ?? null;
            if (!$poolId) continue;

            $earliestRound = $minRoundByPool[$poolId] ?? 1;

            // entri babak awal untuk group ini (di pool yg sama)
            $firstRoundEntries = $groupMatches->filter(fn($m) => (int)$m->round === $earliestRound);

            // kalau hanya 1 entri ⇒ ini BYE babak awal ⇒ hide entri babak awal tersebut
            if ($firstRoundEntries->count() < 2) {
                $idsToHide = $idsToHide->merge($firstRoundEntries->pluck('id'));
            }
        }

        if ($idsToHide->isNotEmpty()) {
            $matches = $matches->reject(fn($m) => $idsToHide->contains($m->id))->values();
        }
    }

    // Group by age_category + match category + gender (struktur tetap)
    $grouped = $matches->groupBy(fn($match) =>
        $match->pool->ageCategory->name . '|' .
        $match->matchCategory->name . '|' .
        $match->gender
    )
    ->map(function ($matchesByGroup, $key) {
        [$ageCategory, $category, $gender] = explode('|', $key);

        return [
            'age_category' => $ageCategory,
            'category'     => $category,
            'gender'       => $gender,
            'pools'        => $matchesByGroup->groupBy(fn($match) => $match->pool->name)
                ->map(function ($poolMatches, $poolName) {
                    return [
                        'name'    => $poolName,
                        'matches' => $poolMatches->values(),
                    ];
                })->values(),
        ];
    })->values();

    return response()->json($grouped);
}



    public function matchList_udah_battle(Request $request)
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
            $rules['bracket_type'] = 'required|in:2,4,6,8,16,full_prestasi';
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

        // Ambil peserta (TP + relasi team_member sebagai 'participant')
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
            // tetap lanjut: kita akan bikin full dummy kalau mode-nya full_prestasi
            if (($validated['bracket_type'] ?? null) !== 'full_prestasi') {
                return response()->json(['message' => 'No participants found.'], 404);
            }
        }

        // Tipe pertandingan + ukuran tim
        [$matchType, $teamSize] = $this->resolveMatchTypeAndSize((int)$validated['match_category_id']);

        // === SPECIAL: Full Prestasi → wajib minimal 6 tim (SENI)
        if (($validated['bracket_type'] ?? null) === 'full_prestasi') {
            // Bentuk tim dari peserta awal
            $teams = $this->groupIntoTeams($participants, $teamSize);
            $currentTeamCount = $teams->count();

            if ($currentTeamCount < 6) {
                $neededTeams = 6 - $currentTeamCount;

                // Pastikan kontingen dummy 310–315 ada (hindari FK error)
                $this->ensureContingentsExist([310,311,312,313,314,315], $validated['tournament_id']);

                // Cari template (kalau ada peserta asli)
                $templateMember = optional($participants->first())->participant;
                if (!$templateMember) {
                    // fallback template dari turnamen ini (kriteria sama)
                    $templateMember = TeamMember::query()
                        ->where('match_category_id', $validated['match_category_id'])
                        ->where('age_category_id',  $validated['age_category_id'])
                        ->where('gender',           $validated['gender'])
                        ->first();
                }

                // Buat dummy TEAMS sesuai teamSize
                for ($i = 0; $i < $neededTeams; $i++) {
                    $contingentId = [310,311,312,313,314,315][$i % 6];
                    $this->createDummySeniTeam(
                        $teamSize,
                        $contingentId,
                        $templateMember,
                        $validated
                    );
                }

                // Re-load participants (sudah termasuk dummy)
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
            }

            // Rebuild teams final
            $teams = $this->groupIntoTeams($participants, $teamSize);

            // Satu pool besar → K = nextPow2(N)
            $pools = collect([$teams->shuffle()->values()]);
        } else {
            // Non full_prestasi: flow lama
            $teams = $this->groupIntoTeams($participants, $teamSize);
            if ($teams->isEmpty()) {
                return response()->json(['message' => 'No valid teams could be formed.'], 422);
            }

            $requested = max(2, (int)($validated['bracket_type'] ?? 2));
            $pools = $teams->shuffle()->chunk($requested);
        }

        // ==== lanjut: sama seperti kode lu (pembuatan pool & match) ====
        foreach ($pools as $i => $chunk) {
            $pool = \App\Models\SeniPool::create([
                'tournament_id'      => $validated['tournament_id'],
                'match_category_id'  => $validated['match_category_id'],
                'age_category_id'    => $validated['age_category_id'],
                'gender'             => $validated['gender'],
                'name'               => 'Pool ' . ($i + 1),
                'mode'               => 'battle',
                'bracket_type'       => $validated['bracket_type'] ?? null,
            ]);

            $N = $chunk->count();
            if ($N === 0) continue;

            // ===== Hanya 1 tim → Final Only (1 row sisi BLUE) =====
            if ($N === 1) {
                $onlyTeam = $chunk->values()->first();
                [$m1, $m2, $m3] = $this->extractMemberIds($onlyTeam, $teamSize);

                $round       = 1;
                $totalRounds = 1;
                $roundLabel  = $this->getRoundLabel($round, $totalRounds);

                \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => 1,
                    'battle_group'      => 1,
                    'gender'            => $validated['gender'],
                    'match_category_id' => $validated['match_category_id'],
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'blue',
                    'contingent_id'     => $onlyTeam['contingent_id'] ?? null,
                    'team_member_1'     => $m1,
                    'team_member_2'     => $teamSize >= 2 ? $m2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $m3 : null,
                    'status'            => 'not_started',
                    'winner_corner'     => 'blue',
                ]);
                continue;
            }

            // ===== NORMALISASI K =====
            $K = max(2, $this->nextPow2($N));

            // ===== Ronde 1: susun pasangan & BYE =====
            $pairings    = [];
            $bag         = $chunk->values();
            $battleGroup = 1;

            $byeCount    = $K - $N;
            $targetPairs = intdiv($K, 2);
            $byeBlueSide = true;

            // (1) Sisipkan BYE dulu
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
                    $pairings[] = [$blue, null];
                } else {
                    $pairings[] = [$blue, $red];
                }
            }

            // (3) Tambal kalau masih kurang
            while (count($pairings) < $targetPairs) {
                $pairings[] = [null, null];
            }

            $totalRounds = (int) log($K, 2);

            // ===== Tulis Ronde 1 =====
            $round       = 1;
            $roundLabel  = $this->getRoundLabel($round, $totalRounds);
            $currentRound = []; // setiap entry: [redMatchId|null, blueMatchId|null]

            foreach ($pairings as [$blueTeam, $redTeam]) {
                // spacer
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

            // ===== Ronde berikutnya =====
            while (count($currentRound) > 1) {
                $round++;
                $roundLabel = $this->getRoundLabel($round, $totalRounds);
                $nextRound  = [];

                for ($j = 0; $j < count($currentRound); $j += 2) {
                    $left  = $currentRound[$j]   ?? [null, null];
                    $right = $currentRound[$j+1] ?? [null, null];

                    $blueParent = $left[1]  ?? null;
                    $redParent  = $right[0] ?? null;

                    if ($blueParent === null && $redParent === null) {
                        continue;
                    }

                    if ($blueParent !== null && $redParent === null) {
                        $nextRound[] = [null, $blueParent];
                        continue;
                    }
                    if ($blueParent === null && $redParent !== null) {
                        $nextRound[] = [$redParent, null];
                        continue;
                    }

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

                    // Prefill jika parent BYE
                    $prevRound = $round - 1;

                    $pBlue = \App\Models\SeniMatch::find($blueParent);
                    if ($pBlue) {
                        $siblingBlueExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                            ->where('round', $prevRound)
                            ->where('battle_group', $pBlue->battle_group)
                            ->where('corner', 'red')
                            ->exists();
                        if (!$siblingBlueExists) {
                            $blueNode->contingent_id = $pBlue->contingent_id;
                            $blueNode->team_member_1 = $pBlue->team_member_1;
                            $blueNode->team_member_2 = $pBlue->team_member_2;
                            $blueNode->team_member_3 = $pBlue->team_member_3;
                            $blueNode->save();
                        }
                    }

                    $pRed = \App\Models\SeniMatch::find($redParent);
                    if ($pRed) {
                        $siblingRedExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                            ->where('round', $prevRound)
                            ->where('battle_group', $pRed->battle_group)
                            ->where('corner', 'blue')
                            ->exists();
                        if (!$siblingRedExists) {
                            $redNode->contingent_id = $pRed->contingent_id;
                            $redNode->team_member_1 = $pRed->team_member_1;
                            $redNode->team_member_2 = $pRed->team_member_2;
                            $redNode->team_member_3 = $pRed->team_member_3;
                            $redNode->save();
                        }
                    }

                    $nextRound[] = [$redNode->id, $blueNode->id];
                    $battleGroup++;
                }

                $currentRound = $nextRound;
            }
        }

        return response()->json(['message' => 'Battle matches generated (power-of-two K with BYEs).']);
    }

    // Buat satu TIM dummy (isi teamSize anggota). Tidak assign ke pool apa pun (Seni pakai SeniPool).
    private function createDummySeniTeam(int $teamSize, int $contingentId, ?TeamMember $templateMember, array $validated): void
    {
        for ($i = 0; $i < $teamSize; $i++) {
            $payload = $this->buildDummyTeamMemberPayload(
                $templateMember,
                $contingentId,
                $validated['gender'] ?? null,
                [
                    'match_category_id' => $validated['match_category_id'],
                    'age_category_id'   => $validated['age_category_id'],
                    // kalau punya kolom championship_category_id khusus seni, bisa ikutkan dari template
                    // 'championship_category_id' => $templateMember->championship_category_id ?? null,
                ]
            );

            $tmId = DB::table('team_members')->insertGetId($payload);

            TournamentParticipant::create([
                'tournament_id'  => $validated['tournament_id'],
                'team_member_id' => $tmId,
                // tidak ada pool_id untuk seni di tahap ini
            ]);
        }
    }

    // Payload dummy TeamMember (Faker id_ID) + perisai kolom NOT NULL
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
            'match_category_id'        => $overrides['match_category_id'] ?? ($original->match_category_id ?? null),
            'age_category_id'          => $overrides['age_category_id']    ?? ($original->age_category_id ?? null),
            'category_class_id'        => $original->category_class_id ?? null, // biasanya tidak dipakai di seni
            'registration_status'      => 'approved',
            'is_dummy'                 => true,
            'created_at'               => now(),
            'updated_at'               => now(),
        ];

        $payload = array_merge($payload, $overrides);

        return $this->withTeamMemberDefaults($payload);
    }

    // Pastikan kontingen (310–315) ada → hindari FK error
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

    // Perisai khusus team_members
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

        // Helper bikin team key (contingent + sorted member ids)
        $makeTeamKey = function ($contingentId, array $memberIds): string {
            $ids = array_values(array_filter(array_map('intval', $memberIds), fn($v) => $v > 0));
            sort($ids, SORT_NUMERIC);
            return (string)($contingentId ?? 0) . ':' . implode('-', $ids);
        };

        // 4) Baca konfigurasi pool & ASSIGNMENT LAMA (SEBELUM hapus match)
        $poolConfigs         = [];
        $existingAssignments = []; // pool_id => array<teamKey>
        $hasFullPrestasiBattle = false;

        foreach ($pools as $pool) {
            $existingAssignments[$pool->id] = [];

            $mode = $pool->mode ?? null;
            $brkt = $pool->bracket_type ?? null;

            $old = \App\Models\SeniMatch::where('pool_id', $pool->id)->get();

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
            if ($mode === null) $mode = 'default';
            if ($mode === 'battle' && $brkt === null) $brkt = 'full_prestasi';

            if ($mode === 'battle' && $brkt === 'full_prestasi') {
                $hasFullPrestasiBattle = true;
            }

            // Kumpulkan assignment lama (pakai match round=1 untuk battle, semua baris untuk default)
            $rowsForKey = $mode === 'battle' ? $old->where('round', 1) : $old;
            $seen = [];
            foreach ($rowsForKey as $m) {
                $mem = [
                    $m->team_member_1,
                    $m->team_member_2,
                    $m->team_member_3,
                ];
                $key = $makeTeamKey($m->contingent_id, $mem);
                if ($key !== '0:' && !isset($seen[$key])) {
                    $existingAssignments[$pool->id][] = $key;
                    $seen[$key] = true;
                }
            }

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

        if ($participants->isEmpty() && !$hasFullPrestasiBattle) {
            return response()->json(['message' => 'Tidak ada peserta ditemukan.'], 404);
        }

        // 7) Bentuk tim awal (boleh kosong kalau nanti full_prestasi kita isi dummy)
        $teams = $this->groupIntoTeams($participants, $teamSize);
        if ($teams->isEmpty() && !$hasFullPrestasiBattle) {
            return response()->json(['message' => 'Tidak ada tim valid yang bisa dibentuk.'], 422);
        }

        // 8) Rekonstruksi bucket pool: JANGAN PINDAH POOL
        $lookup = []; // teamKey => team
        foreach ($teams as $t) {
            $memberIds = $t['members']->pluck('id')->take($teamSize)->filter()->values()->all();
            if (empty($memberIds)) continue; // skip invalid
            $key = $makeTeamKey($t['contingent_id'] ?? null, $memberIds);
            $lookup[$key] = $t;
        }

        $poolIndexById = [];
        $buckets       = [];
        foreach ($pools as $idx => $pool) {
            $poolIndexById[$pool->id] = $idx;
            $buckets[$idx] = [];
        }

        // Assign tim lama ke pool yang sama
        foreach ($pools as $idx => $pool) {
            foreach ($existingAssignments[$pool->id] as $key) {
                if (isset($lookup[$key])) {
                    $buckets[$idx][] = $lookup[$key];
                    unset($lookup[$key]);
                }
            }
        }

        // Tim baru → pool dengan isi paling sedikit
        $remaining = array_values($lookup);
        foreach ($remaining as $t) {
            $minIdx = 0;
            $minCnt = PHP_INT_MAX;
            foreach ($buckets as $i => $arr) {
                $cnt = count($arr);
                if ($cnt < $minCnt) { $minCnt = $cnt; $minIdx = $i; }
            }
            $buckets[$minIdx][] = $t;
        }

        // 8B) ⬅️ Tambahan: FULL PRESTASI (BATTLE) → MINIMAL 6 TIM PER POOL
        //     Jika kurang dari 6, buat dummy tim (kontingen 310–315) & taruh di bucket pool tsb.
        if ($hasFullPrestasiBattle) {
            // pastikan kontingen dummy ada (hindari FK)
            $this->ensureContingentsExist([310,311,312,313,314,315], $tournamentId);

            // Cari template member (kalau ada real participant)
            $templateMember = optional($participants->first())->participant;
            if (!$templateMember) {
                $templateMember = \App\Models\TeamMember::query()
                    ->where('match_category_id', $matchCategoryId)
                    ->where('age_category_id',  $ageCategoryId)
                    ->where('gender',           $gender)
                    ->first();
            }

            foreach ($pools as $i => $pool) {
                $cfg = $poolConfigs[$pool->id] ?? ['mode' => 'default', 'bracket_type' => null];
                if (($cfg['mode'] ?? null) !== 'battle' || ($cfg['bracket_type'] ?? null) !== 'full_prestasi') {
                    continue;
                }

                $currentCount = count($buckets[$i] ?? []);
                if ($currentCount >= 6) continue;

                $need = 6 - $currentCount;
                for ($d = 0; $d < $need; $d++) {
                    $contingentId = [310,311,312,313,314,315][$d % 6];

                    // bikin 1 tim dummy (teamSize anggota), DAFTARKAN ke tournament_participants
                    $team = $this->createDummySeniTeamAndReturnTeam(
                        $teamSize,
                        $contingentId,
                        $templateMember,
                        [
                            'tournament_id'     => $tournamentId,
                            'match_category_id' => $matchCategoryId,
                            'age_category_id'   => $ageCategoryId,
                            'gender'            => $gender,
                        ]
                    );

                    // masukkan langsung ke bucket pool ini
                    $buckets[$i][] = $team;
                }
            }
        }

        // 9) Bangun ulang per pool (acak POSISI di dalam pool saja)
        foreach ($pools as $i => $pool) {
            $cfg           = $poolConfigs[$pool->id] ?? ['mode' => 'default', 'bracket_type' => null];
            $poolMode      = ($cfg['mode'] === 'battle') ? 'battle' : 'default';
            $assignedTeams = $buckets[$i] ?? [];

            if (empty($assignedTeams)) continue;

            if ($poolMode !== 'battle') {
                // =======================
                // DEFAULT / NON-BATTLE
                // =======================
                $bag   = collect($assignedTeams)->shuffle()->values();
                $order = 1;
                foreach ($bag as $t) {
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

            // N=1 → langsung Final Only
            if ($N === 1) {
                $onlyTeam = $bag->first();
                [$m1, $m2, $m3] = $this->extractMemberIds($onlyTeam, $teamSize);

                $round       = 1;
                $totalRounds = 1;
                $roundLabel  = $this->getRoundLabel($round, $totalRounds);

                \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => 1,
                    'battle_group'      => 1,
                    'gender'            => $gender,
                    'match_category_id' => $matchCategoryId,
                    'match_type'        => $matchType,
                    'mode'              => 'battle',
                    'round'             => $round,
                    'round_label'       => $roundLabel,
                    'corner'            => 'blue',
                    'contingent_id'     => $onlyTeam['contingent_id'] ?? null,
                    'team_member_1'     => $m1,
                    'team_member_2'     => $teamSize >= 2 ? $m2 : null,
                    'team_member_3'     => $teamSize >= 3 ? $m3 : null,
                    'status'            => 'not_started',
                    'winner_corner'     => 'blue',
                ]);
                continue;
            }

            // === K di-normalisasi dari N (power of two, ≥ N)
            $K = max(2, $this->nextPow2($N));
            $totalRounds = (int) log($K, 2);

            // Ronde 1: sisipkan BYE dulu, lalu pairing normal
            $pairings    = []; // [blueTeam|null, redTeam|null]
            $battleGroup = 1;

            $byeCount    = $K - $N;
            $targetPairs = intdiv($K, 2);
            $byeBlueSide = true;

            // (1) BYE dulu
            for ($b = 0; $b < $byeCount && $bag->count() > 0; $b++) {
                $team = $bag->shift();
                $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
                $byeBlueSide = !$byeBlueSide;
            }
            // (2) Pairing sisa
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
            // (3) Tambal jika kurang
            while (count($pairings) < $targetPairs) {
                $pairings[] = [null, null];
            }

            // Tulis Ronde 1 (BYE = 1 row + winner_corner)
            $round        = 1;
            $roundLabel   = $this->getRoundLabel($round, $totalRounds);
            // simpan tiap entry: [RED_id|null, BLUE_id|null]
            $currentRound = [];

            foreach ($pairings as [$blueTeam, $redTeam]) {
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

                // Normal
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

            // ===== Ronde berikutnya: carry-forward jika 1 parent; buat node kalau 2 parent =====
            while (count($currentRound) > 1) {
                $round++;
                $roundLabel = $this->getRoundLabel($round, $totalRounds);
                $nextRound  = [];

                for ($j = 0; $j < count($currentRound); $j += 2) {
                    // format: [redMatchId|null, blueMatchId|null]
                    $left  = $currentRound[$j]   ?? [null, null];
                    $right = $currentRound[$j+1] ?? [null, null];

                    $blueParent = $left[1]  ?? null; // winner BLUE kiri
                    $redParent  = $right[0] ?? null; // winner RED  kanan

                    if ($blueParent === null && $redParent === null) {
                        continue;
                    }

                    if ($blueParent !== null && $redParent === null) {
                        $nextRound[] = [null, $blueParent];
                        continue;
                    }
                    if ($blueParent === null && $redParent !== null) {
                        $nextRound[] = [$redParent, null];
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

                    // Prefill bila parent BYE (tak ada sibling di ronde sebelumnya)
                    $prevRound = $round - 1;

                    $pBlue = \App\Models\SeniMatch::find($blueParent);
                    if ($pBlue) {
                        $siblingBlueExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                            ->where('round', $prevRound)
                            ->where('battle_group', $pBlue->battle_group)
                            ->where('corner', 'red')
                            ->exists();
                        if (!$siblingBlueExists) {
                            $blueNode->contingent_id = $pBlue->contingent_id;
                            $blueNode->team_member_1 = $pBlue->team_member_1;
                            $blueNode->team_member_2 = $pBlue->team_member_2;
                            $blueNode->team_member_3 = $pBlue->team_member_3;
                            $blueNode->save();
                        }
                    }

                    $pRed = \App\Models\SeniMatch::find($redParent);
                    if ($pRed) {
                        $siblingRedExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                            ->where('round', $prevRound)
                            ->where('battle_group', $pRed->battle_group)
                            ->where('corner', 'blue')
                            ->exists();
                        if (!$siblingRedExists) {
                            $redNode->contingent_id = $pRed->contingent_id;
                            $redNode->team_member_1 = $pRed->team_member_1;
                            $redNode->team_member_2 = $pRed->team_member_2;
                            $redNode->team_member_3 = $pRed->team_member_3;
                            $redNode->save();
                        }
                    }

                    $nextRound[] = [$redNode->id, $blueNode->id];
                    $battleGroup++;
                }

                $currentRound = $nextRound;
            }
        }

        return response()->json([
            'message' => 'Regenerate berhasil',
        ]);
    }



    public function regeneratePool(Request $request, \App\Models\SeniPool $pool = null)
{
    // Bisa via path param {pool} atau body pool_id
    if (!$pool) {
        $data = $request->validate([
            'pool_id' => 'required|exists:seni_pools,id',
        ]);
        $pool = \App\Models\SeniPool::findOrFail($data['pool_id']);
    }

    // Scope dari pool
    $tournamentId    = (int) $pool->tournament_id;
    $matchCategoryId = (int) $pool->match_category_id;
    $ageCategoryId   = (int) $pool->age_category_id;
    $gender          = (string) $pool->gender;

    // Helper yang sudah ada di controller
    [$matchType, $teamSize] = $this->resolveMatchTypeAndSize($matchCategoryId);

    DB::beginTransaction();
    try {
        // Ambil semua match lama di pool ini
        $oldMatches = \App\Models\SeniMatch::where('pool_id', $pool->id)->get();

        if ($oldMatches->isEmpty()) {
            DB::rollBack();
            return response()->json([
                'message' => 'Pool belum memiliki match — tidak ada yang perlu diregenerasi.',
            ], 404);
        }

        // Mode & bracket_type (pakai setting pool, fallback dari match lama)
        $mode        = $pool->mode;
        $bracketType = $pool->bracket_type;

        if ($mode === null) {
            $mode = $oldMatches->contains(fn($m) => $m->mode === 'battle') ? 'battle' : 'default';
        }
        if ($mode === 'battle' && $bracketType === null) {
            $maxRound = (int) $oldMatches->max('round');
            if ($maxRound > 0) {
                $pow = 1 << $maxRound; // 2^maxRound
                $bracketType = in_array($pow, [2,4,8,16,32,64], true) ? (string)$pow : 'full_prestasi';
            } else {
                $bracketType = 'full_prestasi';
            }
        }
        if ($mode === null) $mode = 'default';

        // --- Kumpulkan tim yang memang ada di pool ini ---
        $makeTeamKey = function (?int $contingentId, array $memberIds): string {
            $ids = array_values(array_filter(array_map('intval', $memberIds), fn($v) => $v > 0));
            sort($ids, SORT_NUMERIC);
            return (string)($contingentId ?? 0) . ':' . implode('-', $ids);
        };

        $teams = []; // array<teamKey => ['contingent_id','m1','m2','m3']>
        $addTeam = function (\App\Models\SeniMatch $m) use (&$teams, $teamSize, $makeTeamKey) {
            // skip baris kosong total
            if (empty($m->contingent_id) && empty($m->team_member_1) && empty($m->team_member_2) && empty($m->team_member_3)) {
                return;
            }
            $members = [];
            if (!empty($m->team_member_1)) $members[] = (int) $m->team_member_1;
            if ($teamSize >= 2 && !empty($m->team_member_2)) $members[] = (int) $m->team_member_2;
            if ($teamSize >= 3 && !empty($m->team_member_3)) $members[] = (int) $m->team_member_3;

            $key = $makeTeamKey($m->contingent_id, $members);
            if (!isset($teams[$key])) {
                $teams[$key] = [
                    'contingent_id' => $m->contingent_id,
                    'm1'            => $m->team_member_1,
                    'm2'            => $teamSize >= 2 ? $m->team_member_2 : null,
                    'm3'            => $teamSize >= 3 ? $m->team_member_3 : null,
                ];
            }
        };

        if ($mode === 'battle') {
            // Battle: ambil tim dari ROUND 1 saja (tiap sisi)
            $r1 = $oldMatches->where('round', 1);
            foreach ($r1 as $m) $addTeam($m);
        } else {
            // Non-battle: tiap baris = satu tim
            foreach ($oldMatches as $m) $addTeam($m);
        }

        // Guard: untuk battle + full_prestasi, minimal 6 tim (tidak bikin dummy di sini)
        if ($mode === 'battle' && $bracketType === 'full_prestasi') {
            $currentTeams = count($teams);
            if ($currentTeams < 6) {
                DB::rollBack();
                return response()->json([
                    'message' => "Pool full_prestasi ini berisi {$currentTeams} tim (< 6). "
                               . "Dummy hanya dibuat saat generate/regenerate global. "
                               . "Silakan jalankan generate/regenerate untuk kategori ini agar kuota minimal terpenuhi.",
                ], 422);
            }
        }

        if (empty($teams)) {
            DB::rollBack();
            return response()->json([
                'message' => 'Tidak menemukan tim valid di pool ini untuk diregenerasi.',
            ], 422);
        }

        // Hapus schedule detail & match lama untuk pool ini
        $oldIds = $oldMatches->pluck('id')->all();
        \App\Models\MatchScheduleDetail::whereIn('seni_match_id', $oldIds)->delete();
        \App\Models\SeniMatch::whereIn('id', $oldIds)->delete();

        // Bangun ulang dari tim yang ada (acak posisi di dalam pool)
        $bag = collect(array_values($teams))->shuffle()->values();

        if ($mode !== 'battle') {
            // ======================= DEFAULT =======================
            $order = 1;
            foreach ($bag as $t) {
                \App\Models\SeniMatch::create([
                    'pool_id'           => $pool->id,
                    'match_order'       => $order++,
                    'gender'            => $gender,
                    'match_category_id' => $matchCategoryId,
                    'match_type'        => $matchType,
                    'mode'              => 'default',
                    'contingent_id'     => $t['contingent_id'] ?? null,
                    'team_member_1'     => $t['m1'] ?? null,
                    'team_member_2'     => $teamSize >= 2 ? ($t['m2'] ?? null) : null,
                    'team_member_3'     => $teamSize >= 3 ? ($t['m3'] ?? null) : null,
                    'status'            => 'not_started',
                ]);
            }

            DB::commit();
            return response()->json([
                'message' => 'Regenerate pool (non-battle) berhasil.',
                'pool_id' => $pool->id,
                'mode'    => 'default',
                'count'   => $bag->count(),
            ]);
        }

        // ======================= BATTLE / BRACKET =======================
        $N = $bag->count();

        // N=1 → Final only
        if ($N === 1) {
            $only = $bag->first();
            $round       = 1;
            $totalRounds = 1;
            $roundLabel  = $this->getRoundLabel($round, $totalRounds);

            \App\Models\SeniMatch::create([
                'pool_id'           => $pool->id,
                'match_order'       => 1,
                'battle_group'      => 1,
                'gender'            => $gender,
                'match_category_id' => $matchCategoryId,
                'match_type'        => $matchType,
                'mode'              => 'battle',
                'round'             => $round,
                'round_label'       => $roundLabel,
                'corner'            => 'blue',
                'contingent_id'     => $only['contingent_id'] ?? null,
                'team_member_1'     => $only['m1'] ?? null,
                'team_member_2'     => $teamSize >= 2 ? ($only['m2'] ?? null) : null,
                'team_member_3'     => $teamSize >= 3 ? ($only['m3'] ?? null) : null,
                'status'            => 'not_started',
                'winner_corner'     => 'blue',
            ]);

            DB::commit();
            return response()->json([
                'message' => 'Regenerate pool (battle, final-only) berhasil.',
                'pool_id' => $pool->id,
                'mode'    => 'battle',
                'count'   => 1,
            ]);
        }

        // K = nextPow2(N) (power-of-two, ≥ N)
        $K = max(2, $this->nextPow2($N));
        $totalRounds = (int) log($K, 2);

        // Ronde 1: sisipkan BYE dulu, lalu pairing normal
        $pairings    = []; // [blueTeam|null, redTeam|null]
        $battleGroup = 1;

        $byeCount    = $K - $N;
        $targetPairs = intdiv($K, 2);
        $byeBlueSide = true;

        // (1) BYE dulu
        for ($b = 0; $b < $byeCount && $bag->count() > 0; $b++) {
            $team = $bag->shift();
            $pairings[] = $byeBlueSide ? [$team, null] : [null, $team];
            $byeBlueSide = !$byeBlueSide;
        }
        // (2) Pairing sisa
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
        // (3) Tambal jika kurang
        while (count($pairings) < $targetPairs) {
            $pairings[] = [null, null];
        }

        // Tulis Ronde 1 (BYE = 1 row + winner_corner)
        $round        = 1;
        $roundLabel   = $this->getRoundLabel($round, $totalRounds);
        // simpan tiap entry: [RED_id|null, BLUE_id|null]
        $currentRound = [];

        foreach ($pairings as [$blueTeam, $redTeam]) {
            if ($blueTeam === null && $redTeam === null) {
                $currentRound[] = [null, null];
                $battleGroup++;
                continue;
            }

            // BYE sisi BLUE
            if ($blueTeam !== null && $redTeam === null) {
                $blue = \App\Models\SeniMatch::create([
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
                    'team_member_1'     => $blueTeam['m1'] ?? null,
                    'team_member_2'     => $teamSize >= 2 ? ($blueTeam['m2'] ?? null) : null,
                    'team_member_3'     => $teamSize >= 3 ? ($blueTeam['m3'] ?? null) : null,
                    'status'            => 'not_started',
                    'winner_corner'     => 'blue',
                ]);
                $currentRound[] = [null, $blue->id];
                $battleGroup++;
                continue;
            }

            // BYE sisi RED
            if ($blueTeam === null && $redTeam !== null) {
                $red = \App\Models\SeniMatch::create([
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
                    'team_member_1'     => $redTeam['m1'] ?? null,
                    'team_member_2'     => $teamSize >= 2 ? ($redTeam['m2'] ?? null) : null,
                    'team_member_3'     => $teamSize >= 3 ? ($redTeam['m3'] ?? null) : null,
                    'status'            => 'not_started',
                    'winner_corner'     => 'red',
                ]);
                $currentRound[] = [$red->id, null];
                $battleGroup++;
                continue;
            }

            // Normal
            $blue = \App\Models\SeniMatch::create([
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
                'team_member_1'     => $blueTeam['m1'] ?? null,
                'team_member_2'     => $teamSize >= 2 ? ($blueTeam['m2'] ?? null) : null,
                'team_member_3'     => $teamSize >= 3 ? ($blueTeam['m3'] ?? null) : null,
                'status'            => 'not_started',
            ]);
            $red  = \App\Models\SeniMatch::create([
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
                'team_member_1'     => $redTeam['m1'] ?? null,
                'team_member_2'     => $teamSize >= 2 ? ($redTeam['m2'] ?? null) : null,
                'team_member_3'     => $teamSize >= 3 ? ($redTeam['m3'] ?? null) : null,
                'status'            => 'not_started',
            ]);

            $currentRound[] = [$red->id, $blue->id];
            $battleGroup++;
        }

        // Ronde berikutnya: carry-forward jika 1 parent; buat node + prefill jika 2 parent
        while (count($currentRound) > 1) {
            $round++;
            $roundLabel = $this->getRoundLabel($round, $totalRounds);
            $nextRound  = [];

            for ($j = 0; $j < count($currentRound); $j += 2) {
                // format entry: [redMatchId|null, blueMatchId|null]
                $left  = $currentRound[$j]   ?? [null, null];
                $right = $currentRound[$j+1] ?? [null, null];

                $blueParent = $left[1]  ?? null; // winner BLUE dari kiri
                $redParent  = $right[0] ?? null; // winner RED  dari kanan

                if ($blueParent === null && $redParent === null) {
                    continue;
                }

                // Hanya satu parent → carry-forward
                if ($blueParent !== null && $redParent === null) {
                    $nextRound[] = [null, $blueParent];
                    continue;
                }
                if ($blueParent === null && $redParent !== null) {
                    $nextRound[] = [$redParent, null];
                    continue;
                }

                // Dua parent → buat node normal
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

                // Prefill bila parent BYE (tak punya sibling di ronde sebelumnya)
                $prevRound = $round - 1;

                $pBlue = \App\Models\SeniMatch::find($blueParent);
                if ($pBlue) {
                    $siblingBlueExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                        ->where('round', $prevRound)
                        ->where('battle_group', $pBlue->battle_group)
                        ->where('corner', 'red')
                        ->exists();
                    if (!$siblingBlueExists) {
                        $blueNode->contingent_id = $pBlue->contingent_id;
                        $blueNode->team_member_1 = $pBlue->team_member_1;
                        $blueNode->team_member_2 = $pBlue->team_member_2;
                        $blueNode->team_member_3 = $pBlue->team_member_3;
                        $blueNode->save();
                    }
                }

                $pRed = \App\Models\SeniMatch::find($redParent);
                if ($pRed) {
                    $siblingRedExists = \App\Models\SeniMatch::where('pool_id', $pool->id)
                        ->where('round', $prevRound)
                        ->where('battle_group', $pRed->battle_group)
                        ->where('corner', 'blue')
                        ->exists();
                    if (!$siblingRedExists) {
                        $redNode->contingent_id = $pRed->contingent_id;
                        $redNode->team_member_1 = $pRed->team_member_1;
                        $redNode->team_member_2 = $pRed->team_member_2;
                        $redNode->team_member_3 = $pRed->team_member_3;
                        $redNode->save();
                    }
                }

                $nextRound[] = [$redNode->id, $blueNode->id];
                $battleGroup++;
            }

            $currentRound = $nextRound;
        }

        DB::commit();

        return response()->json([
            'message' => 'Regenerate pool (battle) berhasil.',
            'pool_id' => $pool->id,
            'mode'    => 'battle',
        ]);

    } catch (\Throwable $e) {
        DB::rollBack();
        return response()->json([
            'message' => 'Gagal meregenerasi pool.',
            'error'   => $e->getMessage(),
        ], 500);
    }
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
