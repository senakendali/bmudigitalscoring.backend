<?php

namespace App\Http\Controllers;

use App\Models\TournamentParticipant;
use App\Models\SeniPool;
use App\Models\SeniMatch;
use App\Models\TeamMember;
use App\Models\Tournament;
use App\Models\MatchSchedule;
use App\Models\MatchScheduleDetail;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;


class SeniMatchController extends Controller
{
    public function index_()
    {
        $matches = SeniMatch::with([
            'matchCategory',
            'contingent',
            'teamMember1',
            'teamMember2',
            'teamMember3',
            'pool.ageCategory', // ✅ tambahkan ini
        ])
        ->orderBy('pool_id')
        ->orderBy('match_order')
        ->get();

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

    public function index(Request $request)
    {
        $tournamentId = $request->query('tournament_id');

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

        // ⬇️ Filter berdasarkan tournament_id kalau dikirim
        if ($tournamentId) {
            $query->whereHas('pool', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            });
        }

        $matches = $query->get();

        $ageOrder = [
            'Usia Dini 1' => 1,
            'Usia Dini 2' => 2,
            'Pra Remaja' => 3,
            'Remaja' => 4,
            'Dewasa' => 5,
        ];


        $grouped = $matches->groupBy(fn($match) => $match->matchCategory->name . '|' . $match->gender . '|' . $match->pool->ageCategory->name)
            ->map(function ($matchesByCategory, $key) {
                [$category, $gender, $ageCategory] = explode('|', $key);

                return [
                    'age_category' => $ageCategory,
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
            })
            ->sortBy(fn($item) => $ageOrder[$item['age_category']] ?? 99) // sort by defined order
            ->values();



        return response()->json($grouped);
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
        $validated = $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|in:2,3,4',
            'age_category_id' => 'required|exists:age_categories,id',
            'gender' => 'required|in:male,female',
            'pool_size' => 'required|integer|min:1',
        ]);

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

        if ($matchCategory === 2) {
            // TUNGGAL
            $units = $participants->shuffle()->values();
        } else {
            // GANDA / REGU
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
                if ($matchCategory === 2) {
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

        return response()->json(['message' => 'Seni matches created or updated successfully.']);
    }

    public function getParticipantCounts(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'match_category_id' => 'required|in:2,3,4',
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
    
            if ($matchCategoryId == 2) {
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
