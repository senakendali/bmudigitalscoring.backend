<?php

namespace App\Http\Controllers;

use App\Models\MatchSchedule;
use App\Models\MatchScheduleDetail;
use App\Models\Tournament;
use App\Models\TournamentMatch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Barryvdh\DomPDF\Facade\Pdf;

class MatchScheduleController extends Controller
{
   
    public function index(Request $request)
    {
        try {
            $schedules = MatchSchedule::with([
                'arena',
                'tournament',
                'ageCategory',
                'details.tournamentMatch.pool.matchCategory',
                'details.tournamentMatch.pool.ageCategory',
                'details.seniMatch.matchCategory',
                'details.seniMatch.pool.ageCategory', // ✅ pool dan age category seni
            ])
            ->paginate($request->get('per_page', 10));


            return response()->json($schedules, 200);
        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Unable to fetch match schedules',
                'message' => $e->getMessage()
            ], 500);
        }
    }



    public function getSchedules_hampir($slug)
{
    $tournament = Tournament::where('slug', $slug)->firstOrFail();

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch');

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
        $query->whereHas('tournamentMatch.pool', function ($q) {
            $q->where('name', request()->pool_name);
        });
    }

    $details = $query->get();

    $tournamentName = $tournament->name ?? 'Tanpa Turnamen';
    $grouped = [];

    foreach ($details as $detail) {
        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $pool = $detail->tournamentMatch->pool;
        $poolName = $pool->name ?? 'Tanpa Pool';
        $round = $detail->tournamentMatch->round ?? 0;

        // Ambil total match dari pool tersebut
        $totalMatchInPool = \App\Models\TournamentMatch::where('pool_id', $pool->id)->count();
        $totalRounds = (int) ceil(log($totalMatchInPool + 1, 2));

        // Override jika usia dini (langsung final)
        $ageCategoryId = optional($pool->ageCategory)->id;
        if ($ageCategoryId == 1) {
            $roundLabel = 'Final';
        } else {
            $roundLabel = $this->getRoundLabel($round, $totalRounds);
        }

        // Info kelas dan usia
        $categoryClass = optional($pool->categoryClass);
        $ageCategory = optional($pool->ageCategory);
        $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
        $className = $ageCategoryName . ' ' . ($categoryClass->name ?? 'Tanpa Kelas');
        $minWeight = $categoryClass->weight_min ?? null;
        $maxWeight = $categoryClass->weight_max ?? null;

        $matchData = [
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => optional($detail->tournamentMatch->participantOne)->name,
            'participant_two' => optional($detail->tournamentMatch->participantTwo)->name,
            'contingent_one' => optional(optional($detail->tournamentMatch->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($detail->tournamentMatch->participantTwo)->contingent)->name,
            'class_name' => $className . ' (' . $minWeight . ' KG - ' . $maxWeight . ' KG)',
        ];

        $groupKey = $arenaName . '||' . $date;
        $grouped[$groupKey]['arena_name'] = $arenaName;
        $grouped[$groupKey]['scheduled_date'] = $date;
        $grouped[$groupKey]['tournament_name'] = $tournamentName;
        $grouped[$groupKey]['pools'][$poolName]['pool_name'] = $poolName;
        $grouped[$groupKey]['pools'][$poolName]['rounds'][$roundLabel][] = $matchData;
    }

    $result = [];
    foreach ($grouped as $entry) {
        $pools = [];
        foreach ($entry['pools'] as $pool) {
            $rounds = [];
            foreach ($pool['rounds'] as $roundLabel => $matches) {
                $rounds[] = [
                    'round_label' => $roundLabel,
                    'matches' => $matches,
                ];
            }
            $pools[] = [
                'pool_name' => $pool['pool_name'],
                'rounds' => $rounds,
            ];
        }

        $result[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'pools' => $pools,
        ];
    }

    return response()->json(['data' => $result]);
}

public function getSchedules__LIVEVEV($slug)
{
    $tournament = Tournament::where('slug', $slug)->firstOrFail();

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches' => function ($q) {
            $q->with('winner');
        },
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('tournament_matches.match_number')
    ->select('match_schedule_details.*');

    if (request()->filled('arena_name')) {
        $query->whereHas('schedule.arena', fn($q) =>
            $q->where('name', request()->arena_name));
    }

    if (request()->filled('scheduled_date')) {
        $query->whereHas('schedule', fn($q) =>
            $q->where('scheduled_date', request()->scheduled_date));
    }

    if (request()->filled('pool_name')) {
        $query->whereHas('tournamentMatch.pool', fn($q) =>
            $q->where('name', request()->pool_name));
    }

    $details = $query->get();

    $result = [];

     foreach ($details as $detail) {
        $match = $detail->tournamentMatch;

        // 🚫 Skip jika match adalah BYE:
        $isByeMatch = (
            ($match->participant_1 === null || $match->participant_2 === null)
            && $match->winner_id !== null
            && $match->next_match_id !== null
        );
        if ($isByeMatch) continue;

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $pool = $match->pool;
        $round = $match->round ?? 0;

        $categoryClass = optional($pool->categoryClass);
        $ageCategory = optional($pool->ageCategory);
        $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
        $className = $ageCategoryName . ' ' . ($categoryClass->name ?? 'Tanpa Kelas');
        $minWeight = $categoryClass->weight_min ?? null;
        $maxWeight = $categoryClass->weight_max ?? null;
        
        $genderRaw = $categoryClass->gender ?? null;
        $gender = $genderRaw === 'male' ? 'Putra' : ($genderRaw === 'female' ? 'Putri' : '-');


        // Logic fallback: tampilkan "Pemenang dari Partai #X" kalau peserta belum ada
        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

      if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;

            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;

            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }




        $matchData = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? 'Tanpa Pool',
            'round' => $round,
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className . ' (' . $gender . ' )',
            'age_category_name' => $ageCategoryName,
            'gender' => optional($match->participantOne)->gender ?? '-', // ⬅️ Tambahan ini
        ];

        $groupKey = $arenaName . '||' . ($ageCategory->id ?? 0) . '||' . $date;
        //$groupKey = $arenaName . '||' . ($ageCategory->id ?? 0) . '||' . $date . '||' . ($pool->id ?? 0);


        $result[$groupKey]['arena_name'] = $arenaName;
        $result[$groupKey]['scheduled_date'] = $date;
        $result[$groupKey]['age_category_id'] = $ageCategory->id ?? 0;
        $result[$groupKey]['age_category_name'] = $ageCategoryName;
        $result[$groupKey]['tournament_name'] = $tournament->name;
        $result[$groupKey]['matches'][] = $matchData;
    }

    $final = [];

    foreach ($result as $entry) {
        $matches = collect($entry['matches'])
            ->sortBy([
                ['round', 'asc'],
                ['match_number', 'asc'],
            ])
            ->values();

        $globalMaxRound = $matches->max('round');

        $matches = $matches->map(function ($match) use ($globalMaxRound) {
            if ($match['round'] == $globalMaxRound) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $globalMaxRound).$match['round'].' - '.$globalMaxRound;
            }
            return $match;
        })->toArray();

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'age_category_id' => $entry['age_category_id'],
            'age_category_name' => $entry['age_category_name'],
            'tournament_name' => $entry['tournament_name'],
            'matches' => $matches,
        ];
    }

    $final = collect($final)
        ->sortBy([
            ['arena_name', 'asc'],
            ['age_category_id', 'asc'],
            ['scheduled_date', 'asc'],
        ])
        ->values()
        ->toArray();

    return response()->json(['data' => $final]);
}

    public function getSchedules_udah_hampir_bener($slug)
    {
        $tournament = Tournament::where('slug', $slug)->firstOrFail();

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'tournamentMatch.participantOne.contingent',
            'tournamentMatch.participantTwo.contingent',
            'tournamentMatch.pool.categoryClass',
            'tournamentMatch.pool.ageCategory',
            'tournamentMatch.pool',
        ])
            ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
            ->whereHas('tournamentMatch')
            ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
            ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
            ->orderBy('match_schedule_details.order')
            ->select('match_schedule_details.*');

        if (request()->filled('arena_name')) {
            $query->whereHas('schedule.arena', fn($q) =>
                $q->where('name', request()->arena_name));
        }

        if (request()->filled('scheduled_date')) {
            $query->whereHas('schedule', fn($q) =>
                $q->where('scheduled_date', request()->scheduled_date));
        }

        if (request()->filled('pool_name')) {
            $query->whereHas('tournamentMatch.pool', fn($q) =>
                $q->where('name', request()->pool_name));
        }

        $details = $query->get();
        $groupedByArenaDate = [];

        foreach ($details as $detail) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategory = optional($pool->ageCategory);
            $categoryClass = optional($pool->categoryClass);

            // Default peserta
            $participantOneName = optional($match->participantOne)->name;
            $participantTwoName = optional($match->participantTwo)->name;

            // ✅ Ambil label dari parent BLUE untuk participant_1
            if (!$participantOneName && $match->parent_match_blue_id) {
                $orderLabel = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                $participantOneName = ($orderLabel && is_numeric($orderLabel))
                    ? 'Pemenang dari Partai #' . $orderLabel
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            // ✅ Ambil label dari parent RED untuk participant_2
            if (!$participantTwoName && $match->parent_match_red_id) {
                $orderLabel = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                    ? 'Pemenang dari Partai #' . $orderLabel
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $date = $detail->schedule->scheduled_date;
            $groupKey = $arenaName . '||' . $date;

            $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
            $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
            $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

            $gender = $categoryClass->gender === 'male' ? 'Putra' : ($categoryClass->gender === 'female' ? 'Putri' : '-');
            $className = ($ageCategory->name ?? '-') . ' ' . ($categoryClass->name ?? '-') . ' (' . $gender . ' )';

            $groupedByArenaDate[$groupKey]['matches'][] = [
                'pool_id' => $pool->id ?? null,
                'pool_name' => $pool->name ?? '-',
                'age_category_id' => $ageCategory->id ?? null,
                'round_label' => $detail->round_label ?? '-',
                'match_number' => $detail->order,
                'match_order' => $detail->order,
                'match_time' => $detail->start_time,
                'participant_one' => $participantOneName,
                'participant_two' => $participantTwoName,
                'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
                'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
                'class_name' => $className,
                'age_category_name' => $ageCategory->name ?? '-',
                'gender' => $gender,
            ];
        }

        $final = [];

        foreach ($groupedByArenaDate as $entry) {
            $matches = collect($entry['matches']);

            $grouped = $matches
                ->sortBy('match_order')
                ->groupBy('age_category_id')
                ->sortKeys()
                ->map(function ($ageMatches) {
                    return [
                        'age_category_id' => $ageMatches->first()['age_category_id'] ?? null,
                        'rounds' => $ageMatches
                            ->groupBy('round_label')
                            ->map(function ($roundMatches, $roundLabel) {
                                return [
                                    'round_label' => $roundLabel,
                                    'matches' => $roundMatches->sortBy('match_order')->values(),
                                ];
                            })
                            ->values(),
                    ];
                })
                ->values();

            $final[] = [
                'arena_name' => $entry['arena_name'],
                'scheduled_date' => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'age_category_rounds' => $grouped,
            ];
        }

        return response()->json(['data' => $final]);
    }

    public function getSchedules($slug)
    {
        $tournament = Tournament::where('slug', $slug)->firstOrFail();

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'tournamentMatch.participantOne.contingent',
            'tournamentMatch.participantTwo.contingent',
            'tournamentMatch.pool.categoryClass',
            'tournamentMatch.pool.ageCategory',
            'tournamentMatch.pool',
        ])
            ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
            ->whereHas('tournamentMatch')
            ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
            ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
            ->orderBy('match_schedule_details.order')
            ->select('match_schedule_details.*');

        if (request()->filled('arena_name')) {
            $query->whereHas('schedule.arena', fn($q) =>
                $q->where('name', request()->arena_name));
        }

        if (request()->filled('scheduled_date')) {
            $query->whereHas('schedule', fn($q) =>
                $q->where('scheduled_date', request()->scheduled_date));
        }

        if (request()->filled('pool_name')) {
            $query->whereHas('tournamentMatch.pool', fn($q) =>
                $q->where('name', request()->pool_name));
        }

        $details = $query->get();
        $groupedByArenaDate = [];

        foreach ($details as $detail) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategory = optional($pool->ageCategory);
            $categoryClass = optional($pool->categoryClass);

            // Default peserta
            $participantOneName = optional($match->participantOne)->name;
            $participantTwoName = optional($match->participantTwo)->name;

            // ✅ Ambil label dari parent BLUE untuk participant_1
            if (!$participantOneName && $match->parent_match_blue_id) {
                $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');

                // Kalau parent BLUE tidak ada di jadwal (BYE), cari dari parent RED
                if (!$blueParentOrder && $match->parent_match_red_id) {
                    $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                }

                $participantOneName = $blueParentOrder
                    ? 'Pemenang dari Partai #' . $blueParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            // ✅ Ambil label dari parent RED untuk participant_2
            if (!$participantTwoName && $match->parent_match_red_id) {
                $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');

                // Kalau parent RED tidak ada di jadwal (BYE), cari dari parent BLUE
                if (!$redParentOrder && $match->parent_match_blue_id) {
                    $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                }

                $participantTwoName = $redParentOrder
                    ? 'Pemenang dari Partai #' . $redParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }





            $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
            $date = $detail->schedule->scheduled_date;
            $groupKey = $arenaName . '||' . $date;

            $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
            $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
            $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

            $gender = $categoryClass->gender === 'male' ? 'Putra' : ($categoryClass->gender === 'female' ? 'Putri' : '-');
            $className = ($ageCategory->name ?? '-') . ' ' . ($categoryClass->name ?? '-') . ' (' . $gender . ' )';

            $groupedByArenaDate[$groupKey]['matches'][] = [
                'pool_id' => $pool->id ?? null,
                'pool_name' => $pool->name ?? '-',
                'age_category_id' => $ageCategory->id ?? null,
                'round_label' => $detail->round_label ?? '-',
                'match_number' => $detail->order,
                'match_order' => $detail->order,
                'match_time' => $detail->start_time,
                'participant_one' => $participantOneName,
                'participant_two' => $participantTwoName,
                'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
                'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
                'class_name' => $className,
                'age_category_name' => $ageCategory->name ?? '-',
                'gender' => $gender,
            ];
        }

        $final = [];

        foreach ($groupedByArenaDate as $entry) {
            $matches = collect($entry['matches']);

            $grouped = $matches
                ->sortBy('match_order')
                ->groupBy('age_category_id')
                ->sortKeys()
                ->map(function ($ageMatches) {
                    return [
                        'age_category_id' => $ageMatches->first()['age_category_id'] ?? null,
                        'rounds' => $ageMatches
                            ->groupBy('round_label')
                            ->map(function ($roundMatches, $roundLabel) {
                                return [
                                    'round_label' => $roundLabel,
                                    'matches' => $roundMatches->sortBy('match_order')->values(),
                                ];
                            })
                            ->values(),
                    ];
                })
                ->values();

            $final[] = [
                'arena_name' => $entry['arena_name'],
                'scheduled_date' => $entry['scheduled_date'],
                'tournament_name' => $entry['tournament_name'],
                'age_category_rounds' => $grouped,
            ];
        }

        return response()->json(['data' => $final]);
    }








public function getSchedules_yang_dipakai($slug)
{
    $tournament = Tournament::where('slug', $slug)->firstOrFail();

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*');

    if (request()->filled('arena_name')) {
        $query->whereHas('schedule.arena', fn($q) =>
            $q->where('name', request()->arena_name));
    }

    if (request()->filled('scheduled_date')) {
        $query->whereHas('schedule', fn($q) =>
            $q->where('scheduled_date', request()->scheduled_date));
    }

    if (request()->filled('pool_name')) {
        $query->whereHas('tournamentMatch.pool', fn($q) =>
            $q->where('name', request()->pool_name));
    }

    $details = $query->get();

    $groupedByArenaDate = [];

    foreach ($details as $detail) {
        $match = $detail->tournamentMatch;
        $pool = $match->pool;
        $ageCategory = optional($pool->ageCategory);
        $categoryClass = optional($pool->categoryClass);

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;

        $groupKey = $arenaName . '||' . $date;

        $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
        $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
        $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

        $gender = $categoryClass->gender === 'male' ? 'Putra' : ($categoryClass->gender === 'female' ? 'Putri' : '-');
        $className = ($ageCategory->name ?? '-') . ' ' . ($categoryClass->name ?? '-') . ' (' . $gender . ' )';

        $groupedByArenaDate[$groupKey]['matches'][] = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? '-',
            'age_category_id' => $ageCategory->id ?? null,
            'round_label' => $detail->round_label ?? '-', // ✅ ambil langsung dari detail
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className,
            'age_category_name' => $ageCategory->name ?? '-',
            'gender' => $gender,
        ];
    }

    $final = [];

    foreach ($groupedByArenaDate as $entry) {
        $matches = collect($entry['matches']);

        $matches = $matches->map(function ($match) {
            if ($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == '1/4 Final') {
                $match['participant_two'] = 'Jadwal BYE Semi';
            } elseif ($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == 'Semifinal') {
                $match['participant_two'] = 'Jadwal BYE Final';
            }

            return $match;
        });

        $grouped = $matches
            ->sortBy('match_order')
            ->groupBy('age_category_id')
            ->sortKeys()
            ->map(function ($ageMatches) {
                return [
                    'age_category_id' => $ageMatches->first()['age_category_id'] ?? null,
                    'rounds' => $ageMatches
                        ->groupBy('round_label')
                        ->map(function ($roundMatches, $roundLabel) {
                            return [
                                'round_label' => $roundLabel,
                                'matches' => $roundMatches->sortBy('match_order')->values(),
                            ];
                        })
                        ->values(),
                ];
            })
            ->values();

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'age_category_rounds' => $grouped,
        ];
    }

    return response()->json(['data' => $final]);
}


public function getSchedules_oke($slug)
{
    $tournament = Tournament::where('slug', $slug)->firstOrFail();

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*');

    if (request()->filled('arena_name')) {
        $query->whereHas('schedule.arena', fn($q) =>
            $q->where('name', request()->arena_name));
    }

    if (request()->filled('scheduled_date')) {
        $query->whereHas('schedule', fn($q) =>
            $q->where('scheduled_date', request()->scheduled_date));
    }

    if (request()->filled('pool_name')) {
        $query->whereHas('tournamentMatch.pool', fn($q) =>
            $q->where('name', request()->pool_name));
    }

    $details = $query->get();

    $groupedByArenaDate = [];

    foreach ($details as $detail) {
        $match = $detail->tournamentMatch;
        $pool = $match->pool;
        $ageCategory = optional($pool->ageCategory);
        $categoryClass = optional($pool->categoryClass);

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;

        $groupKey = $arenaName . '||' . $date;

        $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
        $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
        $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

        $gender = $categoryClass->gender === 'male' ? 'Putra' : ($categoryClass->gender === 'female' ? 'Putri' : '-');
        $className = ($ageCategory->name ?? '-') . ' ' . ($categoryClass->name ?? '-') . ' (' . $gender . ' )';

        $groupedByArenaDate[$groupKey]['matches'][] = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? '-',
            'age_category_id' => $ageCategory->id ?? null,
            'round' => $match->round ?? 0,
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className,
            'age_category_name' => $ageCategory->name ?? '-',
            'gender' => $gender,
        ];
    }

    // Urutan babak
    $roundOrder = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    // Final hasil terstruktur
    $final = [];

    foreach ($groupedByArenaDate as $entry) {
        $matches = collect($entry['matches']);

        $roundMap = $this->getMaxRoundByPool($matches);

        $matches = $matches->map(function ($match) use ($roundMap) {
            $poolId = $match['pool_id'] ?? null;
            $maxRound = $roundMap[$poolId] ?? 1;

            if ($match['age_category_id'] === 1) {
                $match['round_label'] = 'Final';
            } elseif (
                (is_null($match['participant_one']) || is_null($match['participant_two'])) &&
                !is_null($match['match_order'])
            ) {
                $match['round_label'] = 'BYE';
            } elseif ($match['round'] == $maxRound) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $maxRound);
            }

            if($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == '1/4 Final')
            {
                $match['participant_two'] = 'Jadwal BYE Semi';
            }else{
                 $match['participant_two'] = $match['participant_two'];
            }

             if($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == 'Semifinal')
            {
                $match['participant_two'] = 'Jadwal BYE Final';
            }else{
                 $match['participant_two'] = $match['participant_two'];
            }

            return $match;
        });

        // ✅ Grouping: per usia > per babak
        $grouped = $matches
            ->sortBy('match_order')
            ->groupBy('age_category_id')
            ->sortKeys()
            ->map(function ($ageMatches) use ($roundOrder) {
                return [
                    'age_category_id' => $ageMatches->first()['age_category_id'] ?? null,
                    'rounds' => $ageMatches
                        ->groupBy('round_label')
                        ->map(function ($roundMatches, $roundLabel) use ($roundOrder) {
                            return [
                                'round_label' => $roundLabel,
                                'round_order' => $roundOrder[$roundLabel] ?? 99,
                                'matches' => $roundMatches->sortBy('match_order')->values(),
                            ];
                        })
                        ->sortBy('round_order')
                        ->values(),
                ];
            })
            ->values();

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'age_category_rounds' => $grouped,
        ];
    }

    return response()->json(['data' => $final]);
}

public function resetMatchOrderBasedOnGetSchedules($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrder = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*')
    ->get();

    $groupedByArenaDate = [];

    foreach ($query as $detail) {
        $match = $detail->tournamentMatch;
        $pool = $match->pool;
        $ageCategory = optional($pool->ageCategory);
        $categoryClass = optional($pool->categoryClass);

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $groupKey = $arenaName . '||' . $date;

        $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
        $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
        $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

        $groupedByArenaDate[$groupKey]['matches'][] = [
            'detail_id' => $detail->id,
            'pool_id' => $pool->id ?? null,
            'age_category_id' => $ageCategory->id ?? null,
            'round' => $match->round ?? 0,
            'match_order' => $detail->order,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
        ];
    }

    foreach ($groupedByArenaDate as $entry) {
        $matches = collect($entry['matches']);

        $roundMap = $matches->groupBy('pool_id')->map(fn($items) => $items->max('round'));

        $matches = $matches->map(function ($match) use ($roundMap) {
            $poolId = $match['pool_id'];
            $maxRound = $roundMap[$poolId] ?? 1;

            if ($match['age_category_id'] === 1) {
                $match['round_label'] = 'Final';
            } elseif ((is_null($match['participant_one']) || is_null($match['participant_two'])) && !is_null($match['match_order'])) {
                $match['round_label'] = 'BYE';
            } elseif ($match['round'] == $maxRound) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $maxRound);
            }

            return $match;
        });

        $sorted = $matches
            ->sortBy(['age_category_id', 'round_label', 'match_order'])
            ->groupBy('age_category_id')
            ->sortKeys()
            ->flatMap(function ($ageMatches) use ($roundOrder) {
                return $ageMatches
                    ->groupBy('round_label')
                    ->sortBy(fn($v, $k) => $roundOrder[$k] ?? 99)
                    ->flatMap(fn($items) => $items->values());
            })
            ->values();

        $counter = 1;
        foreach ($sorted as $item) {
            MatchScheduleDetail::where('id', $item['detail_id'])->update([
                'order' => $counter++,
                'round_label' => $item['round_label'], // ✅ Update label babak
            ]);
        }
    }

    return response()->json(['message' => '✅ Match order & round_label berhasil direset berdasarkan struktur getSchedules.']);
}


public function resetMatchOrderBasedOnGetSchedules_ajib($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrder = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*')
    ->get();

    $groupedByArenaDate = [];

    foreach ($query as $detail) {
        $match = $detail->tournamentMatch;
        $pool = $match->pool;
        $ageCategory = optional($pool->ageCategory);
        $categoryClass = optional($pool->categoryClass);

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;

        $groupKey = $arenaName . '||' . $date;

        $groupedByArenaDate[$groupKey]['arena_name'] = $arenaName;
        $groupedByArenaDate[$groupKey]['scheduled_date'] = $date;
        $groupedByArenaDate[$groupKey]['tournament_name'] = $tournament->name;

        $gender = $categoryClass->gender === 'male' ? 'Putra' : ($categoryClass->gender === 'female' ? 'Putri' : '-');

        $groupedByArenaDate[$groupKey]['matches'][] = [
            'detail_id' => $detail->id,
            'pool_id' => $pool->id ?? null,
            'age_category_id' => $ageCategory->id ?? null,
            'round' => $match->round ?? 0,
            'match_order' => $detail->order,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
        ];
    }

    foreach ($groupedByArenaDate as $entry) {
        $matches = collect($entry['matches']);

        $roundMap = $matches->groupBy('pool_id')->map(fn($items) => $items->max('round'));

        $matches = $matches->map(function ($match) use ($roundMap) {
            $poolId = $match['pool_id'];
            $maxRound = $roundMap[$poolId] ?? 1;

            if ($match['age_category_id'] === 1) {
                $match['round_label'] = 'Final';
            } elseif ((is_null($match['participant_one']) || is_null($match['participant_two'])) && !is_null($match['match_order'])) {
                $match['round_label'] = 'BYE';
            } elseif ($match['round'] == $maxRound) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $maxRound);
            }

            return $match;
        });

        $sorted = $matches
            ->sortBy(['age_category_id', 'round_label', 'match_order'])
            ->groupBy('age_category_id')
            ->sortKeys()
            ->flatMap(function ($ageMatches) use ($roundOrder) {
                return $ageMatches
                    ->groupBy('round_label')
                    ->sortBy(fn($v, $k) => $roundOrder[$k] ?? 99)
                    ->flatMap(fn($items) => $items->values());
            })
            ->values();

        $counter = 1;
        foreach ($sorted as $item) {
            MatchScheduleDetail::where('id', $item['detail_id'])->update(['order' => $counter++]);
        }
    }

    return response()->json(['message' => 'Match order berhasil direset ulang by tournament ID.']);
}

public function BresetScheduleMatchOrder($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrderMap = [
        'BYE' => 0,
        'Penyisihan' => 1,
        'Perdelapan Final' => 2,
        'Perempat Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournamentId))
    ->whereHas('tournamentMatch')
    ->get();

    $grouped = $details->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($grouped as $arenaName => $arenaDetails) {
        $matchData = $arenaDetails->map(function ($detail) use ($roundOrderMap) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategoryId = optional($pool->ageCategory)->id ?? 0;
            $round = $match->round ?? 0;

            // Ambil max round via query langsung
            $maxRound = \App\Models\TournamentMatch::where('pool_id', $pool?->id)->max('round') ?? 1;

            if ($ageCategoryId === 1) {
                $roundLabel = 'Final';
            } elseif ((is_null($match->participantOne) || is_null($match->participantTwo)) && !is_null($detail->order)) {
                $roundLabel = 'BYE';
            } elseif ($round == $maxRound) {
                $roundLabel = 'Final';
            } else {
                $roundLabel = $this->getRoundLabel($round, $maxRound);
            }

            return [
                'detail' => $detail,
                'age_category_id' => $ageCategoryId,
                'round_label' => $roundLabel,
                'round_order' => $roundOrderMap[$roundLabel] ?? 99,
                'match_order' => $detail->order ?? 9999,
            ];
        });

        // Urutkan berdasarkan kategori usia, babak, dan urutan match
        $sorted = $matchData->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
            ['match_order', 'asc'],
        ])->values();

        // Apply urutan baru
        foreach ($sorted as $index => $item) {
            $item['detail']->order = $index + 1;
            $item['detail']->save();
        }
    }

    return response()->json(['message' => 'Urutan pertandingan berhasil direset berdasarkan arena, kategori usia, dan babak.']);
}


public function resetScheduleMatchOrder($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrderMap = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournamentId))
    ->whereHas('tournamentMatch')
    ->get();

    $grouped = $details->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($grouped as $arenaName => $arenaDetails) {
        $matchData = $arenaDetails->map(function ($detail) use ($roundOrderMap, $arenaDetails) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategoryId = optional($pool->ageCategory)->id ?? 0;
            $poolId = $pool->id ?? null;

            $maxRound = $arenaDetails
                ->filter(fn($d) => optional($d->tournamentMatch)->pool_id === $poolId)
                ->max(fn($d) => $d->tournamentMatch->round ?? 1) ?? 1;

            $round = $match->round ?? 0;

            if ($ageCategoryId === 1) {
                $roundLabel = 'Final';
            } elseif ((is_null($match->participantOne) || is_null($match->participantTwo)) && !is_null($detail->order)) {
                $roundLabel = 'BYE';
            } else {
                $roundLabel = $this->getRoundLabel($round, $maxRound);
            }

            return [
                'detail' => $detail,
                'age_category_id' => $ageCategoryId,
                'round_label' => $roundLabel,
                'round_order' => $roundOrderMap[$roundLabel] ?? 99,
                'match_order' => $detail->order ?? 9999,
            ];
        });

        // Urutkan berdasarkan usia, babak, order lama
        $sorted = $matchData->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
            ['match_order', 'asc'],
        ])->values();

        foreach ($sorted as $index => $item) {
            $item['detail']->order = $index + 1;
            $item['detail']->round_label = $item['round_label']; // ✅ UPDATE LABEL
            $item['detail']->save();
        }
    }

    return response()->json(['message' => '✅ Urutan & babak pertandingan berhasil direset berdasarkan arena, usia, dan babak.']);
}


public function resetScheduleMatchOrder_ajib($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrderMap = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournamentId))
    ->whereHas('tournamentMatch')
    ->get();

    $grouped = $details->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($grouped as $arenaName => $arenaDetails) {
        $matchData = $arenaDetails->map(function ($detail) use ($roundOrderMap, $arenaDetails) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategoryId = optional($pool->ageCategory)->id ?? 0;
            $poolId = $pool->id ?? null;

            $maxRound = $arenaDetails
                ->filter(fn($d) => optional($d->tournamentMatch)->pool_id === $poolId)
                ->max(fn($d) => $d->tournamentMatch->round ?? 1) ?? 1;

            $round = $match->round ?? 0;

            if ($ageCategoryId === 1) {
                $roundLabel = 'Final';
            } elseif ((is_null($match->participantOne) || is_null($match->participantTwo)) && !is_null($detail->order)) {
                $roundLabel = 'BYE';
            } else {
                $roundLabel = $this->getRoundLabel($round, $maxRound);
            }

            return [
                'detail' => $detail,
                'age_category_id' => $ageCategoryId,
                'round_label' => $roundLabel,
                'round_order' => $roundOrderMap[$roundLabel] ?? 99,
                'match_order' => $detail->order ?? 9999,
            ];
        });

        // Urutkan berdasarkan usia, round, lalu order lama
        $sorted = $matchData->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
            ['match_order', 'asc'],
        ])->values();

        foreach ($sorted as $index => $item) {
            $item['detail']->order = $index + 1;
            $item['detail']->save();
        }
    }

    return response()->json(['message' => '✅ Urutan pertandingan berhasil direset berdasarkan arena, kategori usia, dan babak.']);
}


public function resetScheduleMatchOrderAgain($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrderMap = [
        'BYE' => 0,
        //'Penyisihan' => 1,
        '1/8 Final' => 1,
        '1/4 Final' => 2,
        'Semifinal' => 3,
        'Final' => 4,
    ];

    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournamentId))
    ->whereHas('tournamentMatch')
    ->get();

    $grouped = $details->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($grouped as $arenaName => $arenaDetails) {
        // Semua pertandingan dalam satu arena
        $matchData = $arenaDetails->map(function ($detail) use ($roundOrderMap) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $round = $match->round ?? 0;

            $maxRound = \App\Models\TournamentMatch::where('pool_id', $pool?->id)->max('round') ?? 1;

            // Label babak berdasarkan round
            if ((is_null($match->participantOne) || is_null($match->participantTwo))) {
                $roundLabel = 'BYE';
            } elseif ($round == $maxRound) {
                $roundLabel = 'Final';
            } elseif ($round == $maxRound - 1) {
                $roundLabel = 'Semifinal';
            } elseif ($round == $maxRound - 2) {
                $roundLabel = '1/4 Final';
            } elseif ($round == $maxRound - 3) {
                $roundLabel = '1/8 Final';
            } else {
                $roundLabel = 'Penyisihan';
            }

            return [
                'detail' => $detail,
                'age_category_id' => $pool?->age_category_id ?? 0,
                'round_order' => $roundOrderMap[$roundLabel] ?? 99,
                'round_label' => $roundLabel,
            ];
        });

        // Urutkan berdasarkan kategori usia, lalu babak
        $sorted = $matchData->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
        ])->values();

        // Reset urutan match_order global dalam arena
        foreach ($sorted as $index => $item) {
            $item['detail']->order = $index + 1;
            $item['detail']->save();
        }
    }

    return response()->json(['message' => '✅ Urutan pertandingan berhasil diperbarui per arena, usia, dan babak.']);
}

private function getRoundLabelFromRound($round, $maxRound): string
{
    if ($round == $maxRound) return 'Final';
    if ($round == $maxRound - 1) return 'Semifinal';
    if ($round == $maxRound - 2) return '1/4 Final';
    if ($round == $maxRound - 3) return '1/8 Final';
    return 'Penyisihan';
}

public function resetScheduleMatchOrderTwo($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundOrderMap = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournamentId))
    ->whereHas('tournamentMatch')
    ->get();

    // Group per arena
    $grouped = $details->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($grouped as $arenaName => $arenaDetails) {
        $matchData = $arenaDetails->map(function ($detail) use ($roundOrderMap, $arenaDetails) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategoryId = optional($pool?->ageCategory)->id ?? 0;

            $maxRound = $arenaDetails
                ->filter(fn($d) => optional($d->tournamentMatch)->pool_id === $pool?->id)
                ->max(fn($d) => $d->tournamentMatch->round ?? 1) ?? 1;

            $round = $match->round ?? 0;

            if ((is_null($match->participantOne) || is_null($match->participantTwo))) {
                $roundLabel = 'BYE';
            } else {
                $roundLabel = $this->getRoundLabelFromRound($round, $maxRound);
            }

            return [
                'detail' => $detail,
                'age_category_id' => $ageCategoryId,
                'round_label' => $roundLabel,
                'round_order' => $roundOrderMap[$roundLabel] ?? 99,
            ];
        });

        // Sort by age_category_id dan round_order
        $sorted = $matchData->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
        ])->values();

        // Apply global order dalam satu arena
        foreach ($sorted as $index => $item) {
            $item['detail']->order = $index + 1;
            $item['detail']->save();
        }
    }

    return response()->json(['message' => '✅ Jadwal berhasil diurut ulang per arena, kategori usia, dan babak.']);
}






public function resetScheduleMatchOrderBOBO($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    // Ambil semua schedule detail lengkap
    $details = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->get()
    ->groupBy(fn($d) => $d->schedule->arena->name ?? 'Tanpa Arena');

    foreach ($details as $arenaName => $matches) {

        // Ambil max round per pool
        $maxRounds = $matches
            ->pluck('tournamentMatch')
            ->groupBy('pool_id')
            ->map(fn($ms) => $ms->max('round') ?? 1);

        // Transform dan beri label
        $mapped = $matches->map(function ($detail) use ($maxRounds) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $ageCategoryId = optional($pool->ageCategory)->id ?? 0;

            $maxRound = $maxRounds[$match->pool_id] ?? 1;
            $round = $match->round ?? 0;

            // Tentukan label babak
            if ($ageCategoryId === 1) {
                $roundLabel = 'Final';
            } elseif (
                (is_null($match->participantOne) || is_null($match->participantTwo)) &&
                !is_null($detail->order)
            ) {
                $roundLabel = 'BYE';
            } elseif ($round == $maxRound) {
                $roundLabel = 'Final';
            } else {
                $roundLabel = $this->getRoundLabel($round, $maxRound);
            }

            return [
                'detail' => $detail,
                'age_category_id' => $ageCategoryId,
                'round_label' => $roundLabel,
                'round_order' => $this->roundLabelOrder($roundLabel),
                'match_order' => $detail->order ?? 9999,
            ];
        });

        // Urutkan berdasarkan age_category_id, round, order
        $sorted = $mapped->sortBy([
            ['age_category_id', 'asc'],
            ['round_order', 'asc'],
            ['match_order', 'asc'],
        ]);

        // Reset order mulai dari 1
        $counter = 1;
        foreach ($sorted as $item) {
            $item['detail']->order = $counter++;
            $item['detail']->save();
        }
    }

    return response()->json([
        'message' => 'Match order berhasil direset ulang per arena dan urut berdasarkan usia & babak.'
    ]);
}




private function roundLabelOrder($label)
{
    $orderMap = [
        'BYE' => 0,
        'Penyisihan' => 1,
        'Perdelapan Final' => 2,
        'Perempat Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    return $orderMap[$label] ?? 99;
}





public function getSchedules_keep($slug)
{
    $tournament = Tournament::where('slug', $slug)->firstOrFail();

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
        'tournamentMatch.previousMatches' => function ($q) {
            $q->with('winner');
        },
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*');

    if (request()->filled('arena_name')) {
        $query->whereHas('schedule.arena', fn($q) =>
            $q->where('name', request()->arena_name));
    }

    if (request()->filled('scheduled_date')) {
        $query->whereHas('schedule', fn($q) =>
            $q->where('scheduled_date', request()->scheduled_date));
    }

    if (request()->filled('pool_name')) {
        $query->whereHas('tournamentMatch.pool', fn($q) =>
            $q->where('name', request()->pool_name));
    }

    $details = $query->get();

    $result = [];

    foreach ($details as $detail) {
        $match = $detail->tournamentMatch;

        $arenaName = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $pool = $match->pool;
        $round = $match->round ?? 0;

        $categoryClass = optional($pool->categoryClass);
        $ageCategory = optional($pool->ageCategory);
        $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
        $className = $ageCategoryName . ' ' . ($categoryClass->name ?? 'Tanpa Kelas');
        $minWeight = $categoryClass->weight_min ?? null;
        $maxWeight = $categoryClass->weight_max ?? null;

        $genderRaw = $categoryClass->gender ?? null;
        $gender = $genderRaw === 'male' ? 'Putra' : ($genderRaw === 'female' ? 'Putri' : '-');

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName) {
            $fromMatch = $match->previousMatches->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantOneName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName) {
            $fromMatch = $match->previousMatches->skip(1)->first();
            $orderLabel = optional($fromMatch?->scheduleDetail)->order;
            $participantTwoName = ($orderLabel && is_numeric($orderLabel))
                ? 'Pemenang dari Partai #' . $orderLabel
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        $matchData = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? 'Tanpa Pool',
            'age_category_id' => $ageCategory->id ?? null,
            'round' => $round,
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className . ' (' . $gender . ' )',
            'age_category_name' => $ageCategoryName,
            'gender' => optional($match->participantOne)->gender ?? '-',
        ];

        $groupKey = $arenaName . '||' . ($ageCategory->id ?? 0) . '||' . $date;

        $result[$groupKey]['arena_name'] = $arenaName;
        $result[$groupKey]['scheduled_date'] = $date;
        $result[$groupKey]['age_category_id'] = $ageCategory->id ?? 0;
        $result[$groupKey]['age_category_name'] = $ageCategoryName;
        $result[$groupKey]['tournament_name'] = $tournament->name;
        $result[$groupKey]['matches'][] = $matchData;
    }

    $final = [];

    foreach ($result as $entry) {
        $matches = collect($entry['matches']);

        $roundMap = $this->getMaxRoundByPool($matches);

        $matches = $matches->map(function ($match) use ($roundMap) {
            $poolId = $match['pool_id'] ?? null;
            $maxRoundInThisPool = $roundMap[$poolId] ?? 1;

            if (($match['age_category_id'] ?? null) == 1) {
                $match['round_label'] = 'Final';
            } elseif (
                (is_null($match['participant_one']) || is_null($match['participant_two'])) &&
                !is_null($match['match_order'])
            ) {
                $match['round_label'] = 'BYE';
            } elseif ($match['round'] == $maxRoundInThisPool) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $maxRoundInThisPool);
            }

            return $match;
        });

        // 🔁 Urutkan berdasarkan match_order yang sudah diregenerate
        $matches = $matches->sortBy('match_order')->values()->toArray();

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'age_category_id' => $entry['age_category_id'],
            'age_category_name' => $entry['age_category_name'],
            'tournament_name' => $entry['tournament_name'],
            'matches' => $matches,
        ];
    }

    $final = collect($final)
        ->sortBy([['arena_name', 'asc'], ['age_category_id', 'asc'], ['scheduled_date', 'asc']])
        ->values()
        ->toArray();

    return response()->json(['data' => $final]);
}


public function regenerateMatchNumberAndSave($tournamentId)
{
    $tournament = Tournament::findOrFail($tournamentId);

    $roundPriority = [
        '1/64 Final'     => 1,
        '1/32 Final'     => 2,
        '1/16 Final'     => 3,
        '1/8 Final'      => 4,
        'Perempat Final' => 5,
        'Semifinal'      => 6,
        'Final'          => 7,
        'BYE'            => 8,
    ];

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'tournamentMatch.pool.matches',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.participantOne',
        'tournamentMatch.participantTwo',
        'tournamentMatch.previousMatches.scheduleDetail',
    ])
    ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
    ->whereHas('tournamentMatch')
    ->get();

    $grouped = $query->groupBy(fn($detail) => $detail->schedule->arena->name ?? 'Tanpa Arena');

    DB::beginTransaction();
    try {
        foreach ($grouped as $group) {
            // 🎯 Hitung round_label per item
            foreach ($group as $detail) {
                $match = $detail->tournamentMatch;
                $pool = $match->pool;
                $round = $match->round;
                $maxRound = $pool->matches->max('round') ?? 1;

                if (($pool->age_category_id ?? null) == 1) {
                    $label = 'Final';
                } elseif (
                    (is_null($match->participant_1) || is_null($match->participant_2)) &&
                    $match->winner_id !== null &&
                    $match->next_match_id !== null
                ) {
                    $label = 'BYE';
                } elseif ($round == $maxRound) {
                    $label = 'Final';
                } else {
                    $label = $this->getRoundLabel($round, $maxRound);
                }

                $detail->round_label = $label;
            }

            // ✅ Sort berdasarkan: usia → kelas → babak → id
            $sorted = collect($group)->sortBy([
                fn($a, $b) => ($a->tournamentMatch->pool->age_category_id ?? 99) <=> ($b->tournamentMatch->pool->age_category_id ?? 99),
                fn($a, $b) => ($a->tournamentMatch->pool->category_class_id ?? 99) <=> ($b->tournamentMatch->pool->category_class_id ?? 99),
                fn($a, $b) => ($roundPriority[$a->round_label] ?? 99) <=> ($roundPriority[$b->round_label] ?? 99),
                fn($a, $b) => $a->id <=> $b->id
            ])->values();

            // 🔁 Reset ulang order
            foreach ($sorted as $i => $detail) {
                $detail->order = $i + 1;
                $detail->save();
            }
        }

        DB::commit();
        return response()->json(['message' => '✅ Order berhasil direset ulang per arena, urut berdasarkan usia, kelas, dan babak.']);
    } catch (\Throwable $th) {
        DB::rollBack();
        return response()->json([
            'message' => '❌ Gagal regenerate order',
            'error' => $th->getMessage()
        ], 500);
    }
}

public function export(Request $request)
{
    $arenaName = $request->query('arena_name');
    $scheduledDate = $request->query('scheduled_date');

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
    ])
    ->whereHas('schedule', function ($q) use ($scheduledDate, $arenaName) {
        $q->when($scheduledDate, fn($q) => $q->where('scheduled_date', $scheduledDate))
          ->whereHas('arena', fn($q) => $q->where('name', $arenaName));
    })
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*');

    $details = $query->get();

    if ($details->isEmpty()) {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.tanding-schedule', ['data' => []]);
        return $pdf->download("Jadwal_{$arenaName}_{$scheduledDate}_Kosong.pdf");
    }

    $tournament = $details->first()?->schedule?->tournament;
    $result = [];

    foreach ($details as $detail) {
        $match = $detail->tournamentMatch;

        $isByeMatch = (
            ($match->participant_1 === null || $match->participant_2 === null)
            && $match->winner_id !== null
            && $match->next_match_id !== null
        );
        if ($isByeMatch) continue;

        $arena = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $groupKey = $arena . '||' . $date;

        $pool = $match->pool;
        $round = $match->round ?? 0;
        $roundLabel = $detail->round_label ?? '-';

        $categoryClass = optional($pool->categoryClass);
        $ageCategory = optional($pool->ageCategory);
        $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
        $className = $ageCategoryName . ' ' . ($categoryClass->name ?? 'Tanpa Kelas');

        $genderRaw = $categoryClass->gender ?? null;
        $gender = $genderRaw === 'male' ? 'Putra' : ($genderRaw === 'female' ? 'Putri' : '-');

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName && $match->parent_match_blue_id) {
            $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order')
                ?? MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');

            $participantOneName = $blueParentOrder
                ? 'Pemenang dari Partai #' . $blueParentOrder
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName && $match->parent_match_red_id) {
            $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order')
                ?? MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');

            $participantTwoName = $redParentOrder
                ? 'Pemenang dari Partai #' . $redParentOrder
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!isset($result[$groupKey])) {
            $result[$groupKey] = [
                'arena_name' => $arena,
                'scheduled_date' => $date,
                'tournament_name' => $tournament->name ?? '-',
                'matches' => [],
            ];
        }

        // Atur label peserta untuk BYE Semi/Final jika perlu
        if ($participantTwoName == 'Pemenang dari Pertandingan Sebelumnya' && $roundLabel == '1/4 Final') {
            $participantTwoName = 'Jadwal BYE Semi';
        }

        if ($participantTwoName == 'Pemenang dari Pertandingan Sebelumnya' && $roundLabel == 'Semifinal') {
            $participantTwoName = 'Jadwal BYE Final';
        }

        $result[$groupKey]['matches'][] = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? 'Tanpa Pool',
            'round' => $round,
            'round_label' => $roundLabel,
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className . ' (' . $gender . ' )',
            'age_category_id' => $ageCategory->id ?? 0,
            'age_category_name' => $ageCategoryName,
            'gender' => $gender,
        ];
    }

    // Buat final array yang siap dikirim ke view
    $final = [];

    foreach ($result as $entry) {
        $matches = collect($entry['matches'])->sortBy('match_order')->values();

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'matches' => $matches,
        ];
    }

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.tanding-schedule', ['data' => $final]);
    return $pdf->download("Jadwal_{$arenaName}_{$scheduledDate}.pdf");
}


public function export_asli(Request $request)
{
    $arenaName = $request->query('arena_name');
    $scheduledDate = $request->query('scheduled_date');

    $query = MatchScheduleDetail::with([
        'schedule.arena',
        'schedule.tournament',
        'tournamentMatch.participantOne.contingent',
        'tournamentMatch.participantTwo.contingent',
        'tournamentMatch.pool.categoryClass',
        'tournamentMatch.pool.ageCategory',
        'tournamentMatch.pool',
    ])
    ->whereHas('schedule', function ($q) use ($scheduledDate, $arenaName) {
        $q->when($scheduledDate, fn($q) => $q->where('scheduled_date', $scheduledDate))
          ->whereHas('arena', fn($q) => $q->where('name', $arenaName));
    })
    ->whereHas('tournamentMatch')
    ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
    ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
    ->orderBy('match_schedule_details.order')
    ->select('match_schedule_details.*');

    $details = $query->get();

    if ($details->isEmpty()) {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.tanding-schedule', ['data' => []]);
        return $pdf->download("Jadwal_{$arenaName}_{$scheduledDate}_Kosong.pdf");
    }

    $tournament = $details->first()?->schedule?->tournament;
    $result = [];

    foreach ($details as $detail) {
        $match = $detail->tournamentMatch;

        $isByeMatch = (
            ($match->participant_1 === null || $match->participant_2 === null)
            && $match->winner_id !== null
            && $match->next_match_id !== null
        );
        if ($isByeMatch) continue;

        $arena = $detail->schedule->arena->name ?? 'Tanpa Arena';
        $date = $detail->schedule->scheduled_date;
        $groupKey = $arena . '||' . $date;

        $pool = $match->pool;
        $round = $match->round ?? 0;

        $categoryClass = optional($pool->categoryClass);
        $ageCategory = optional($pool->ageCategory);
        $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
        $className = $ageCategoryName . ' ' . ($categoryClass->name ?? 'Tanpa Kelas');

        $genderRaw = $categoryClass->gender ?? null;
        $gender = $genderRaw === 'male' ? 'Putra' : ($genderRaw === 'female' ? 'Putri' : '-');

        $participantOneName = optional($match->participantOne)->name;
        $participantTwoName = optional($match->participantTwo)->name;

        if (!$participantOneName && $match->parent_match_blue_id) {
            $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order')
                ?? MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');

            $participantOneName = $blueParentOrder
                ? 'Pemenang dari Partai #' . $blueParentOrder
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!$participantTwoName && $match->parent_match_red_id) {
            $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order')
                ?? MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');

            $participantTwoName = $redParentOrder
                ? 'Pemenang dari Partai #' . $redParentOrder
                : 'Pemenang dari Pertandingan Sebelumnya';
        }

        if (!isset($result[$groupKey])) {
            $result[$groupKey] = [
                'arena_name' => $arena,
                'scheduled_date' => $date,
                'tournament_name' => $tournament->name ?? '-',
                'matches' => [],
            ];
        }

        $result[$groupKey]['matches'][] = [
            'pool_id' => $pool->id ?? null,
            'pool_name' => $pool->name ?? 'Tanpa Pool',
            'round' => $round,
            'match_number' => $detail->order,
            'match_order' => $detail->order,
            'match_time' => $detail->start_time,
            'participant_one' => $participantOneName,
            'participant_two' => $participantTwoName,
            'contingent_one' => optional(optional($match->participantOne)->contingent)->name,
            'contingent_two' => optional(optional($match->participantTwo)->contingent)->name,
            'class_name' => $className . ' (' . $gender . ' )',
            'age_category_id' => $ageCategory->id ?? 0,
            'age_category_name' => $ageCategoryName,
            'gender' => $gender,
        ];
    }

    $roundOrder = [
        'BYE' => 0,
        'Penyisihan' => 1,
        '1/8 Final' => 2,
        '1/4 Final' => 3,
        'Semifinal' => 4,
        'Final' => 5,
    ];

    $final = [];

    foreach ($result as $entry) {
        $matches = collect($entry['matches'])->sortBy('match_order')->values();
        $roundMap = $this->getMaxRoundByPool($matches);

        $matches = $matches->map(function ($match) use ($roundMap) {
            $poolId = $match['pool_id'] ?? null;
            $maxRound = $roundMap[$poolId] ?? 1;

            if ($match['age_category_id'] === 1) {
                $match['round_label'] = 'Final';
            } elseif ((is_null($match['participant_one']) || is_null($match['participant_two'])) && !is_null($match['match_order'])) {
                $match['round_label'] = 'BYE';
            } elseif ($match['round'] == $maxRound) {
                $match['round_label'] = 'Final';
            } else {
                $match['round_label'] = $this->getRoundLabel($match['round'], $maxRound);
            }

            if ($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == '1/4 Final') {
                $match['participant_two'] = 'Jadwal BYE Semi';
            }

            if ($match['participant_two'] == 'Pemenang dari Pertandingan Sebelumnya' && $match['round_label'] == 'Semifinal') {
                $match['participant_two'] = 'Jadwal BYE Final';
            }

            return $match;
        });

        $final[] = [
            'arena_name' => $entry['arena_name'],
            'scheduled_date' => $entry['scheduled_date'],
            'tournament_name' => $entry['tournament_name'],
            'matches' => $matches,
        ];
    }

    $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('exports.tanding-schedule', ['data' => $final]);
    return $pdf->download("Jadwal_{$arenaName}_{$scheduledDate}.pdf");
}



    private function getMaxRoundByPool($matches)
    {
        return $matches->groupBy('pool_id')->map(function ($poolMatches) {
            // Hitung jumlah pertandingan = jumlah peserta / 2
            // Dari jumlah pertandingan, kita bisa balik ke jumlah peserta ideal
            $totalMatch = $poolMatches->count();

            // Estimasi jumlah peserta
            $estimatedParticipants = $totalMatch + 1;

            // Hitung berapa banyak ronde dari jumlah peserta ini (log2 dibulatkan ke atas)
            return (int) ceil(log($estimatedParticipants, 2));
        })->toArray();
    }





    private function getRoundLabel($round, $totalRounds)
    {
        // Kalau cuma 1 ronde, langsung Final
        if ($totalRounds === 1) {
            return 'Final';
        }

        $offset = $totalRounds - $round;

        return match (true) {
            $offset === 0 => 'Final',
            $offset === 1 => 'Semifinal',
            $offset === 2 => '1/4 Final',
            $offset === 3 => '1/8 Final',
            $offset === 4 => '1/16 Final',
            default => "Babak {$round}",
        };
    }





    // Tahap 1 untuk reorder match
    public function resetMatchNumber($tournamentId)
    {
        if (!$tournamentId) {
            return response()->json(['message' => '❌ tournament_id wajib dikirim'], 400);
        }

        DB::statement('SET @current_arena := NULL');
        DB::statement('SET @match_number := 0');

        DB::update("
            UPDATE tournament_matches AS tm
            JOIN (
                SELECT tm.id,
                    @match_number := IF(@current_arena = ms.tournament_arena_id, @match_number + 1, 1) AS new_match_number,
                    @current_arena := ms.tournament_arena_id
                FROM tournament_matches tm
                JOIN pools p ON tm.pool_id = p.id
                JOIN match_schedule_details msd ON msd.tournament_match_id = tm.id
                JOIN match_schedules ms ON ms.id = msd.match_schedule_id
                WHERE p.tournament_id = ?
                AND NOT (
                    (tm.participant_1 IS NULL OR tm.participant_2 IS NULL)
                    AND tm.winner_id IS NOT NULL
                    AND tm.next_match_id IS NOT NULL
                )
                ORDER BY 
                    ms.tournament_arena_id ASC,
                    tm.round ASC,
                    tm.id ASC
            ) AS ordered ON tm.id = ordered.id
            SET tm.match_number = ordered.new_match_number
        ", [$tournamentId]);

        return response()->json([
            'message' => '✅ Match number berhasil direset berdasarkan tournament_arena_id dan round ASC.'
        ]);
    }






// Tahap 2 untuk reorder urutan di schedule
    public function resetScheduleOrder($id)
    {
        $tournament = Tournament::where('id', $id)->firstOrFail();

        $query = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'tournamentMatch.participantOne.contingent',
            'tournamentMatch.participantTwo.contingent',
            'tournamentMatch.pool.categoryClass',
            'tournamentMatch.pool.ageCategory',
            'tournamentMatch.pool',
            'tournamentMatch.previousMatches' => function ($q) {
                $q->with('winner');
            },
        ])
        ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
        ->whereHas('tournamentMatch')
        ->join('tournament_matches', 'match_schedule_details.tournament_match_id', '=', 'tournament_matches.id')
        ->join('pools', 'tournament_matches.pool_id', '=', 'pools.id')
        ->join('match_schedules', 'match_schedule_details.match_schedule_id', '=', 'match_schedules.id')
        ->join('tournament_arena', 'match_schedules.tournament_arena_id', '=', 'tournament_arena.id')
        ->orderBy('tournament_arena.name')
        ->orderBy('pools.age_category_id')
        ->orderBy('match_schedules.scheduled_date')
        ->orderBy('tournament_matches.round')
        ->orderBy('match_schedule_details.order')
        ->select(
            'match_schedule_details.*',
            'tournament_matches.round',
            'tournament_matches.pool_id',
            'tournament_matches.participant_1',
            'tournament_matches.participant_2',
            'tournament_matches.winner_id',
            'tournament_matches.next_match_id',
            'tournament_arena.name as arena_name',
            'pools.age_category_id',
            'match_schedules.scheduled_date'
        );

        $details = $query->get();

        DB::beginTransaction();
        try {
            // 🔥 Hapus jadwal BYE
            $byeIds = $details->filter(function ($d) {
                return (
                    ($d->participant_1 === null || $d->participant_2 === null)
                    && $d->winner_id !== null
                    && $d->next_match_id !== null
                );
            })->pluck('id');

            if ($byeIds->count()) {
                MatchScheduleDetail::whereIn('id', $byeIds)->delete();
            }

            // ✅ Filter ulang & hitung round tertinggi per pool
            $filtered = $details->reject(fn($d) => $byeIds->contains($d->id));
            $roundMap = $filtered->groupBy('pool_id')->map->max('round');
            $matchCountPerPool = $filtered->groupBy('pool_id')->map->count();

            // Grouping per arena → tanggal → pool
            $grouped = $filtered->groupBy([
                fn($item) => $item->arena_name,
                fn($item) => $item->scheduled_date,
                fn($item) => $item->pool_id,
            ]);

            foreach ($grouped as $arenaGroup) {
                foreach ($arenaGroup as $dateGroup) {
                    foreach ($dateGroup as $poolId => $matches) {
                        $maxRound = $roundMap[$poolId] ?? 1;
                        $onlyOneMatch = ($matchCountPerPool[$poolId] ?? 0) === 1;

                        // Urutkan dari round terkecil ke terbesar
                        $sorted = $matches->sortBy('round')->values();

                        foreach ($sorted as $i => $detail) {
                            $detail->order = $i + 1;

                            // Tentukan round_label
                            if ($onlyOneMatch) {
                                $detail->round_label = 'Final';
                            } else {
                                if ($detail->round == $maxRound) {
                                    $detail->round_label = 'Final';
                                } elseif ($detail->round == ($maxRound - 1)) {
                                    $detail->round_label = 'Semifinal';
                                } elseif ($detail->round == ($maxRound - 2)) {
                                    $detail->round_label = 'Perempat Final';
                                } else {
                                    $detail->round_label = '1/' . (2 ** ($maxRound - $detail->round + 1)) . ' Final';
                                }
                            }

                            $detail->save();
                        }
                    }
                }
            }

            DB::commit();
            return response()->json(['message' => '✅ Order dan round_label berhasil diurut ulang.']);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => '❌ Gagal reset urutan.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $schedule = MatchSchedule::with([
                'arena',
                'tournament',
                'details' => function ($q) {
                    $q->orderBy('order');
                },
            ])->findOrFail($id);

            // Ambil semua tournament_match_id dari detail
            $matchIds = $schedule->details->pluck('tournament_match_id')->filter();

            // Ambil data match (tanpa relasi)
            $matches = \App\Models\TournamentMatch::whereIn('id', $matchIds)
                ->with(['participantOne.contingent', 'participantTwo.contingent'])
                ->get()
                ->keyBy('id');

            // Masukkan match ke dalam masing-masing detail
            $schedule->details->transform(function ($detail) use ($matches) {
                $detail->tournament_match = $matches->get($detail->tournament_match_id); // bisa null kalau hilang
                return $detail;
            });

            return response()->json(['data' => $schedule], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Match schedule not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'An error occurred', 'message' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'tournament_id'                 => 'required|exists:tournaments,id',
            'tournament_arena_id'           => 'required|exists:tournament_arena,id',
            'match_category_id'             => 'nullable|exists:match_categories,id',
            'scheduled_date'                => 'required|date',
            'age_category_id'               => 'nullable|exists:age_categories,id',
            'round'                         => 'nullable|string',
            'start_time'                    => 'required',
            'end_time'                      => 'nullable',
            'note'                          => 'nullable|string',
            'mode'                          => 'nullable|in:default,battle',

            'matches'                       => 'required|array|min:1',
            'matches.*.note'                => 'nullable|string',
            'matches.*.tournament_match_id' => 'nullable|exists:tournament_matches,id',
            'matches.*.seni_match_id'       => 'nullable|exists:seni_matches,id',
            'matches.*.start_time'          => 'nullable',
            'matches.*.round_label'         => 'nullable|string|max:50',
        ]);

        // Cek duplikasi tanding (tetap)
        $tandingIds = collect($request->matches)->pluck('tournament_match_id')->filter()->toArray();
        if (!empty($tandingIds)) {
            $exists = \App\Models\MatchScheduleDetail::whereIn('tournament_match_id', $tandingIds)
                ->whereHas('schedule', function ($q) use ($request) {
                    $q->where('tournament_id', $request->tournament_id)
                    ->whereDate('scheduled_date', $request->scheduled_date);
                })->exists();
            if ($exists) {
                return response()->json(['error' => 'Some matches are already scheduled on this date'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $items       = collect($request->matches);
            $tandingList = $items->filter(fn($m) => !empty($m['tournament_match_id']))->values();
            $seniList    = $items->filter(fn($m) => !empty($m['seni_match_id']))->values();

            // Auto isi age_category_id dari match seni pertama kalau kosong
            if ($first = $seniList->first()) {
                $seniFirst = \App\Models\SeniMatch::find($first['seni_match_id']);
                if ($seniFirst && empty($request->age_category_id)) {
                    $request->merge(['age_category_id' => $seniFirst->age_category_id]);
                }
            }

            // ======================
            // PRELOAD DATA TANDING:
            // - Ambil tournament_matches (round, pool_id)
            // - Hitung max(round) per pool → untuk getRoundLabel
            // ======================
            $tmById = collect();
            $maxRoundByPool = collect();
            if ($tandingList->isNotEmpty()) {
                $tmById = \App\Models\TournamentMatch::whereIn('id', $tandingList->pluck('tournament_match_id'))
                    ->get(['id','pool_id','round'])
                    ->keyBy('id');

                $maxRoundByPool = \App\Models\TournamentMatch::whereIn('pool_id', $tmById->pluck('pool_id')->unique())
                    ->select('pool_id', DB::raw('MAX(`round`) as max_round'))
                    ->groupBy('pool_id')
                    ->pluck('max_round','pool_id');
            }

            // ===> TENTUKAN HEADER round_label <===
            $scheduleRoundLabel = null;

            if ($seniList->isNotEmpty()) {
                // SENI — logika lama
                $reportedMode = $request->input('mode');
                $seniModels   = \App\Models\SeniMatch::whereIn('id', $seniList->pluck('seni_match_id')->unique())
                                ->get(['id','mode','pool_id','round','battle_group']);
                $hasBattle    = $reportedMode === 'battle' || $seniModels->contains(fn($m) => $m->mode === 'battle');

                if (!$hasBattle) {
                    $scheduleRoundLabel = 'Final';
                } else {
                    $labels = $seniList->map(fn($r) => trim((string)($r['round_label'] ?? '')))
                                    ->filter()->unique()->values();

                    if ($labels->count() >= 1) {
                        $scheduleRoundLabel = $labels->first();
                    } else {
                        $maxRoundByPoolSeni = \App\Models\SeniMatch::whereIn('pool_id', $seniModels->pluck('pool_id')->unique())
                            ->select('pool_id', DB::raw('MAX(`round`) as max_round'))
                            ->groupBy('pool_id')
                            ->pluck('max_round','pool_id');

                        $any = $seniModels->first();
                        if ($any) {
                            $maxR = (int) ($maxRoundByPoolSeni[$any->pool_id] ?? $any->round);
                            $diff = max(0, $maxR - (int)$any->round);
                            $map  = [0=>'Final',1=>'Semifinal',2=>'1/4 Final',3=>'1/8 Final',4=>'1/16 Final',5=>'1/32 Final'];
                            $scheduleRoundLabel = $map[$diff] ?? 'Penyisihan';
                        }
                    }
                }
            } elseif ($tandingList->isNotEmpty()) {
                // TANDING SAJA → ambil label dari round match (kalau seragam)
                $labels = $tandingList->map(function ($row) use ($tmById, $maxRoundByPool) {
                    $explicit = trim((string)($row['round_label'] ?? ''));
                    if ($explicit !== '') return $explicit;

                    $tm = $tmById[$row['tournament_match_id']] ?? null;
                    if (!$tm) return null;
                    $maxR = (int) ($maxRoundByPool[$tm->pool_id] ?? $tm->round);
                    return $this->getRoundLabel((int)$tm->round, (int)$maxR);
                })->filter()->unique()->values();

                // Kalau semua tanding di schedule ini 1 label (misal semua Perempat Final), set ke header
                if ($labels->count() === 1) {
                    $scheduleRoundLabel = $labels->first();
                }
            }
            // <=== END tentukan header round_label

            // Buat header schedule DENGAN round_label
            $schedule = \App\Models\MatchSchedule::create([
                'tournament_id'       => $request->tournament_id,
                'tournament_arena_id' => $request->tournament_arena_id,
                'scheduled_date'      => $request->scheduled_date,
                'start_time'          => $request->start_time,
                'end_time'            => $request->end_time,
                'note'                => $request->note,
                'age_category_id'     => $request->age_category_id,
                'round_label'         => $scheduleRoundLabel,
            ]);

            // Ambil "last order" global di arena tsb
            $lastOrderGlobal = \App\Models\MatchScheduleDetail::whereHas('schedule', function ($q) use ($request) {
                $q->where('tournament_id', $request->tournament_id)
                ->where('tournament_arena_id', $request->tournament_arena_id);
            })->max('order') ?? 0;

            // ======================
            // 1) TANDING (FIX round_label)
            // ======================
            foreach ($tandingList as $row) {
                $explicitLabel = isset($row['round_label']) && trim((string)$row['round_label']) !== ''
                    ? trim((string)$row['round_label'])
                    : null;

                $computedLabel = null;
                if (!$explicitLabel) {
                    $tm = $tmById[$row['tournament_match_id']] ?? null;
                    if ($tm) {
                        $maxR = (int) ($maxRoundByPool[$tm->pool_id] ?? $tm->round);
                        $computedLabel = $this->getRoundLabel((int)$tm->round, (int)$maxR);
                    }
                }

                $schedule->details()->create([
                    'tournament_match_id' => $row['tournament_match_id'],
                    'order'               => ++$lastOrderGlobal,
                    'start_time'          => $row['start_time'] ?? null,
                    'note'                => $row['note'] ?? null,
                    'match_category_id'   => $request->match_category_id,
                    'round_label'         => $explicitLabel ?? $computedLabel, // ⬅️ SELALU terisi
                ]);
            }

            // ======================
            // 2) SENI (seperti sebelumnya)
            // ======================
            if ($seniList->isNotEmpty()) {
                $seniModels = \App\Models\SeniMatch::whereIn('id', $seniList->pluck('seni_match_id')->unique())
                    ->get(['id','pool_id','mode','match_category_id','round','battle_group'])
                    ->keyBy('id');

                $battleGroups = [];
                $seniSingles  = [];
                foreach ($seniList as $row) {
                    $m = $seniModels[$row['seni_match_id']] ?? null;
                    if (!$m) continue;

                    if ($m->mode === 'battle') {
                        $key = $m->pool_id.'-'.$m->round.'-'.$m->battle_group;
                        $battleGroups[$key][] = ['input' => $row, 'seni' => $m];
                    } else {
                        $seniSingles[] = ['input' => $row, 'seni' => $m];
                    }
                }

                // Non-battle details
                foreach ($seniSingles as $it) {
                    $m  = $it['seni'];
                    $in = $it['input'];
                    $schedule->details()->create([
                        'seni_match_id'     => $m->id,
                        'match_category_id' => $m->match_category_id,
                        'order'             => ++$lastOrderGlobal,
                        'start_time'        => $in['start_time'] ?? null,
                        'note'              => $in['note'] ?? null,
                        'round_label'       => 'Final',
                    ]);
                }

                // Battle details (blue+red share order)
                foreach ($battleGroups as $list) {
                    $groupOrder = ++$lastOrderGlobal;
                    foreach ($list as $it) {
                        $m  = $it['seni'];
                        $in = $it['input'];
                        $schedule->details()->create([
                            'seni_match_id'     => $m->id,
                            'match_category_id' => $m->match_category_id,
                            'order'             => $groupOrder,
                            'start_time'        => $in['start_time'] ?? null,
                            'note'              => $in['note'] ?? null,
                            'round_label'       => isset($in['round_label']) && trim($in['round_label']) !== '' ? trim($in['round_label']) : null,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Match schedule created successfully',
                'data'    => $schedule->load('details')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Failed to create match schedule',
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function store_mantep(Request $request)
    {
        $request->validate([
            'tournament_id'                 => 'required|exists:tournaments,id',
            'tournament_arena_id'           => 'required|exists:tournament_arena,id',
            'match_category_id'             => 'nullable|exists:match_categories,id',
            'scheduled_date'                => 'required|date',
            'age_category_id'               => 'nullable|exists:age_categories,id',
            'round'                         => 'nullable|string',
            'start_time'                    => 'required',
            'end_time'                      => 'nullable',
            'note'                          => 'nullable|string',
            'mode'                          => 'nullable|in:default,battle', // ⬅️ biar aman

            'matches'                       => 'required|array|min:1',
            'matches.*.note'                => 'nullable|string',
            'matches.*.tournament_match_id' => 'nullable|exists:tournament_matches,id',
            'matches.*.seni_match_id'       => 'nullable|exists:seni_matches,id',
            'matches.*.start_time'          => 'nullable',
            'matches.*.round_label'         => 'nullable|string|max:50',
        ]);

        // Cek duplikasi tanding (tetap)
        $tandingIds = collect($request->matches)->pluck('tournament_match_id')->filter()->toArray();
        if (!empty($tandingIds)) {
            $exists = \App\Models\MatchScheduleDetail::whereIn('tournament_match_id', $tandingIds)
                ->whereHas('schedule', function ($q) use ($request) {
                    $q->where('tournament_id', $request->tournament_id)
                    ->whereDate('scheduled_date', $request->scheduled_date);
                })->exists();
            if ($exists) {
                return response()->json(['error' => 'Some matches are already scheduled on this date'], 422);
            }
        }

        DB::beginTransaction();
        try {
            $items       = collect($request->matches);
            $tandingList = $items->filter(fn($m) => !empty($m['tournament_match_id']))->values();
            $seniList    = $items->filter(fn($m) => !empty($m['seni_match_id']))->values();

            // Auto isi age_category_id dari match seni pertama kalau kosong
            if ($first = $seniList->first()) {
                $seniFirst = \App\Models\SeniMatch::find($first['seni_match_id']);
                if ($seniFirst && empty($request->age_category_id)) {
                    $request->merge(['age_category_id' => $seniFirst->age_category_id]);
                }
            }

            // ===> TENTUKAN HEADER round_label <===
            $scheduleRoundLabel = null;
            if ($seniList->isNotEmpty()) {
                // cari tau konteks battle/non-battle
                $reportedMode = $request->input('mode'); // FE bisa kirim "battle" / "default"
                $seniModels   = \App\Models\SeniMatch::whereIn('id', $seniList->pluck('seni_match_id')->unique())
                                ->get(['id','mode','pool_id','round','battle_group']);
                $hasBattle    = $reportedMode === 'battle' || $seniModels->contains(fn($m) => $m->mode === 'battle');

                if (!$hasBattle) {
                    // SENI Non-battle => Final
                    $scheduleRoundLabel = 'Final';
                } else {
                    // SENI Battle => ambil dari FE (biasanya uniform krn lu filter per babak)
                    $labels = $seniList->map(fn($r) => trim((string)($r['round_label'] ?? '')))
                                    ->filter()->unique()->values();

                    if ($labels->count() >= 1) {
                        // kalau beda2, ambil saja yang pertama (umumnya sama semua)
                        $scheduleRoundLabel = $labels->first();
                    } else {
                        // fallback sederhana (opsional): infer dari round vs maxRound per pool
                        $maxRoundByPool = \App\Models\SeniMatch::whereIn('pool_id', $seniModels->pluck('pool_id')->unique())
                            ->select('pool_id', DB::raw('MAX(`round`) as max_round'))
                            ->groupBy('pool_id')
                            ->pluck('max_round','pool_id');

                        // pakai salah satu match untuk infer
                        $any = $seniModels->first();
                        if ($any) {
                            $maxR = (int) ($maxRoundByPool[$any->pool_id] ?? $any->round);
                            $diff = max(0, $maxR - (int)$any->round);
                            $map  = [0=>'Final',1=>'Semifinal',2=>'1/4 Final',3=>'1/8 Final',4=>'1/16 Final',5=>'1/32 Final'];
                            $scheduleRoundLabel = $map[$diff] ?? 'Penyisihan';
                        }
                    }
                }
            }
            // <=== END tentukan header round_label

            // Buat header schedule DENGAN round_label
            $schedule = \App\Models\MatchSchedule::create([
                'tournament_id'       => $request->tournament_id,
                'tournament_arena_id' => $request->tournament_arena_id,
                'scheduled_date'      => $request->scheduled_date,
                'start_time'          => $request->start_time,
                'end_time'            => $request->end_time,
                'note'                => $request->note,
                'age_category_id'     => $request->age_category_id,
                'round_label'         => $scheduleRoundLabel, // ⬅️ disimpan ke HEADER
            ]);

            // Ambil "last order" global di arena tsb
            $lastOrderGlobal = \App\Models\MatchScheduleDetail::whereHas('schedule', function ($q) use ($request) {
                $q->where('tournament_id', $request->tournament_id)
                ->where('tournament_arena_id', $request->tournament_arena_id);
            })->max('order') ?? 0;

            // 1) TANDING
            foreach ($tandingList as $row) {
                $schedule->details()->create([
                    'tournament_match_id' => $row['tournament_match_id'],
                    'order'               => ++$lastOrderGlobal,
                    'start_time'          => $row['start_time'] ?? null,
                    'note'                => $row['note'] ?? null,
                    'match_category_id'   => $request->match_category_id,
                    'round_label'         => $row['round_label'] ?? null,
                ]);
            }

            // 2) SENI (detail sama seperti sebelumnya—boleh biarin; fokus kita header)
            if ($seniList->isNotEmpty()) {
                $seniModels = \App\Models\SeniMatch::whereIn('id', $seniList->pluck('seni_match_id')->unique())
                    ->get(['id','pool_id','mode','match_category_id','round','battle_group'])
                    ->keyBy('id');

                $battleGroups = [];
                $seniSingles  = [];
                foreach ($seniList as $row) {
                    $m = $seniModels[$row['seni_match_id']] ?? null;
                    if (!$m) continue;

                    if ($m->mode === 'battle') {
                        $key = $m->pool_id.'-'.$m->round.'-'.$m->battle_group;
                        $battleGroups[$key][] = ['input' => $row, 'seni' => $m];
                    } else {
                        $seniSingles[] = ['input' => $row, 'seni' => $m];
                    }
                }

                // Non-battle details
                foreach ($seniSingles as $it) {
                    $m  = $it['seni'];
                    $in = $it['input'];
                    $schedule->details()->create([
                        'seni_match_id'     => $m->id,
                        'match_category_id' => $m->match_category_id,
                        'order'             => ++$lastOrderGlobal,
                        'start_time'        => $in['start_time'] ?? null,
                        'note'              => $in['note'] ?? null,
                        'round_label'       => 'Final',
                    ]);
                }

                // Battle details (blue+red share order)
                foreach ($battleGroups as $list) {
                    $groupOrder = ++$lastOrderGlobal;
                    foreach ($list as $it) {
                        $m  = $it['seni'];
                        $in = $it['input'];
                        $schedule->details()->create([
                            'seni_match_id'     => $m->id,
                            'match_category_id' => $m->match_category_id,
                            'order'             => $groupOrder,
                            'start_time'        => $in['start_time'] ?? null,
                            'note'              => $in['note'] ?? null,
                            'round_label'       => isset($in['round_label']) && trim($in['round_label']) !== '' ? trim($in['round_label']) : null,
                        ]);
                    }
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Match schedule created successfully',
                'data'    => $schedule->load('details')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error'   => 'Failed to create match schedule',
                'message' => $e->getMessage()
            ], 500);
        }
    }






    public function update(Request $request, $id)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'tournament_arena_id' => 'required|exists:tournament_arena,id',
            'scheduled_date' => 'required|date',
            'start_time' => 'required',
            'end_time' => 'nullable',
            'note' => 'nullable|string',
            'matches' => 'required|array|min:1',
            'matches.*.note' => 'nullable|string',

            // Tanding
            'matches.*.tournament_match_id' => 'nullable|exists:tournament_matches,id',

            // Seni
            'matches.*.seni_match_id' => 'nullable|exists:seni_matches,id',

            'matches.*.order' => 'nullable|integer',
            'matches.*.start_time' => 'nullable',
        ]);

        $schedule = \App\Models\MatchSchedule::findOrFail($id);

        DB::beginTransaction();

        try {
            $schedule->update([
                'tournament_id' => $request->tournament_id,
                'tournament_arena_id' => $request->tournament_arena_id,
                'scheduled_date' => $request->scheduled_date,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'note' => $request->note,
            ]);

            // Hapus detail lama
            $schedule->details()->delete();

            $orderCounter = 1;
            foreach ($request->matches as $match) {
                $data = [
                    'note' => $match['note'] ?? null,
                    'order' => $match['order'] ?? $orderCounter++,
                    'start_time' => $match['start_time'] ?? null,
                ];

                if (!empty($match['tournament_match_id'])) {
                    $data['tournament_match_id'] = $match['tournament_match_id'];
                    $data['match_category_id'] = $request->match_category_id;
                }

                if (!empty($match['seni_match_id'])) {
                    $data['seni_match_id'] = $match['seni_match_id'];
                }

                $schedule->details()->create($data);
            }

            DB::commit();

            return response()->json([
                'message' => 'Match schedule updated successfully',
                'data' => $schedule->load('details')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update match schedule',
                'message' => $e->getMessage()
            ], 500);
        }
    }




    public function destroy($id)
    {
        try {
            $schedule = MatchSchedule::findOrFail($id);
            $schedule->details()->delete();
            $schedule->delete();

            return response()->json(['message' => 'Match schedule deleted successfully'], 200);
        } catch (ModelNotFoundException $e) {
            return response()->json(['error' => 'Match schedule not found'], 404);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to delete match schedule', 'message' => $e->getMessage()], 500);
        }
    }

}