<?php

namespace App\Http\Controllers;

use App\Models\TournamentMatch;
use App\Models\Tournament;
use App\Models\MatchScheduleDetail;
use App\Models\MatchSchedule;
use App\Models\SeniMatch;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function matches_backup(Request $request)
    {
        $tournamentSlug = $request->query('tournament');
        $tournament = Tournament::where('slug', $tournamentSlug)->firstOrFail();

        $matches = TournamentMatch::with([
            'pool.tournament',
            'pool.categoryClass',
            'pool.ageCategory',
            'scheduleDetail.schedule.arena',
            'participantOne.contingent',
            'participantTwo.contingent',
            'previousMatches.scheduleDetail'
        ])
        ->whereHas('scheduleDetail.schedule')
        ->whereHas('pool.tournament', function ($q) use ($tournamentSlug) {
            $q->where('slug', $tournamentSlug);
        })
        ->orderBy('round')
        ->orderBy('match_number')
        ->get();

        // Mapping untuk parent
        $parentMap = [];
        foreach ($matches as $match) {
            if ($match->next_match_id) {
                if (!isset($parentMap[$match->next_match_id])) {
                    $parentMap[$match->next_match_id] = [];
                }
                $parentMap[$match->next_match_id][] = $match->id;
            }
        }

        // Hitung round tertinggi per pool
        $roundMap = $matches->groupBy(fn($m) => $m->pool_id)
            ->map(fn($group) => $group->max('round'));

        // Susun hasil
        $result = $matches->map(function ($match) use ($tournament, $parentMap, $roundMap) {
            $isBye = (
                ($match->participant_1 === null || $match->participant_2 === null)
                && $match->winner_id !== null
                && $match->next_match_id !== null
            );
            if ($isBye) return null;

            $pool = $match->pool;
            $round = $match->round ?? 0;

            $arena = optional($match->scheduleDetail?->schedule?->arena)->name;
            $date = optional($match->scheduleDetail?->schedule)->scheduled_date;
            $start = optional($match->scheduleDetail?->schedule)->start_time;

            $categoryClass = optional($pool->categoryClass);
            $ageCategory = optional($pool->ageCategory);
            $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
            $className = $categoryClass->name ?? 'Tanpa Kelas';
            $gender = optional($match->participantOne)->gender == 'male' ? 'Putra' : 'Putri';

            $parents = $parentMap[$match->id] ?? [];

            return [
                'tournament_name' => $tournament->name,
                'match_id' => $match->id,
                'arena_name' => $arena,
                'scheduled_date' => $date,
                'start_time' => $start,
                'pool_name' => $pool->name,
                'class_name' => $ageCategoryName . ' ' . $className . ' (' . $gender . ')',
                'age_category_name' => $ageCategoryName,
                'gender' => optional($match->participantOne)->gender ?? '-',
                'match_number' => $match->match_number,
                'match_order' => $match->match_number,
                'round_level' => $round,
                'round_label' => ($round == ($roundMap[$pool->id] ?? 1)) ? 'Final' : $this->getRoundLabel($round, $roundMap[$pool->id] ?? 1),
                'round_duration' => $pool->match_duration ?? 0,

                'blue_id' => $match->participantOne?->id,
                'blue_name' => $match->participantOne?->name ?? 'TBD',
                'blue_contingent' => $match->participantOne?->contingent?->name ?? 'TBD',

                'red_id' => $match->participantTwo?->id,
                'red_name' => $match->participantTwo?->name ?? 'TBD',
                'red_contingent' => $match->participantTwo?->contingent?->name ?? 'TBD',

                'parent_match_red_id' => $parents[0] ?? null,
                'parent_match_blue_id' => $parents[1] ?? null,
            ];
        })->filter()->values(); // Buang yang null (BYE)

        return response()->json($result);
    }

    public function matches(Request $request)
    {
        $tournamentSlug = $request->query('tournament');
        $tournament = Tournament::where('slug', $tournamentSlug)->firstOrFail();

        $details = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'tournamentMatch.pool.categoryClass',
            'tournamentMatch.pool.ageCategory',
            'tournamentMatch.pool',
            'tournamentMatch.participantOne.contingent',
            'tournamentMatch.participantTwo.contingent',
            'tournamentMatch.previousMatches.scheduleDetail',
        ])
            ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
            ->whereHas('tournamentMatch')
            ->orderBy('order')
            ->get();

        // Peta parent: next_match_id => [list of child match ids]
        $parentMap = [];
        foreach ($details as $detail) {
            $match = $detail->tournamentMatch;
            if ($match && $match->next_match_id) {
                $parentMap[$match->next_match_id][] = $match->id;
            }
        }

        // Sort by arena name (stabil)
        $sorted = $details->sortBy([
            fn($a, $b) => ($a->schedule->arena->name ?? '') <=> ($b->schedule->arena->name ?? '')
        ])->values();

        $result = $sorted->map(function ($detail) use ($tournament, $parentMap) {
            $match  = $detail->tournamentMatch;
            $pool   = $match->pool ?? null;

            $arena  = optional($detail->schedule?->arena)->name;
            $date   = optional($detail->schedule)->scheduled_date;
            $start  = $detail->start_time ?? null;

            $categoryClass     = optional($pool?->categoryClass);
            $ageCategory       = optional($pool?->ageCategory);
            $ageCategoryName   = $ageCategory->name ?? 'Tanpa Usia';
            $className         = $categoryClass->name ?? 'Tanpa Kelas';

            // ==== FIX GENDER ====
            // Ambil gender dari pool terlebih dahulu agar konsisten antar babak,
            // fallback ke participantOne lalu participantTwo jika pool null.
            $rawGender = $pool->gender
                ?? optional($match->participantOne)->gender
                ?? optional($match->participantTwo)->gender;

            $raw = strtolower((string) $rawGender);
            if (in_array($raw, ['male', 'putra', 'laki-laki', 'l'])) {
                $gender = 'Putra';
            } elseif (in_array($raw, ['female', 'putri', 'perempuan', 'p'])) {
                $gender = 'Putri';
            } else {
                // Fallback terakhir: pilih salah satu sesuai kebutuhan UI
                $gender = 'Putra';
            }

            // ==== Nama peserta / placeholder pemenang parent ====
            $participantOneName = optional($match->participantOne)->name;
            $participantTwoName = optional($match->participantTwo)->name;

            if (!$participantOneName && ($match->parent_match_blue_id || $match->parent_match_red_id)) {
                // Cari order parent biru dulu, kalau kosong coba parent merah
                $blueParentOrder = null;
                if ($match->parent_match_blue_id) {
                    $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                }
                if (!$blueParentOrder && $match->parent_match_red_id) {
                    $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                }
                $participantOneName = $blueParentOrder
                    ? 'Pemenang dari Partai #' . $blueParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            if (!$participantTwoName && ($match->parent_match_red_id || $match->parent_match_blue_id)) {
                // Cari order parent merah dulu, kalau kosong coba parent biru
                $redParentOrder = null;
                if ($match->parent_match_red_id) {
                    $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                }
                if (!$redParentOrder && $match->parent_match_blue_id) {
                    $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                }
                $participantTwoName = $redParentOrder
                    ? 'Pemenang dari Partai #' . $redParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            // Ambil daftar parent (anak-anak dari match ini) untuk reference di UI
            $parents = $parentMap[$match->id] ?? [];

            return [
                'tournament_name'     => $tournament->name,
                'match_id'            => $match->id,
                'arena_name'          => $arena,
                'scheduled_date'      => $date,
                'start_time'          => $start,

                'pool_name'           => $pool->name ?? '-',
                'class_name'          => "$ageCategoryName $className ($gender)",
                'age_category_name'   => $ageCategoryName,
                'gender'              => $gender,

                'match_number'        => $detail->order,
                'match_order'         => $detail->order,
                'round_level'         => $match->round,
                'round_label'         => $detail->round_label,
                'round_duration'      => $pool->match_duration ?? 0,

                'blue_id'             => $match->participantOne?->id,
                'blue_name'           => $participantOneName ?? 'TBD',
                'blue_contingent'     => $match->participantOne?->contingent?->name ?? 'TBD',

                'red_id'              => $match->participantTwo?->id,
                'red_name'            => $participantTwoName ?? 'TBD',
                'red_contingent'      => $match->participantTwo?->contingent?->name ?? 'TBD',

                // Nilai ini sebelumnya dipakai untuk referensi parent; tetap pertahankan
                'parent_match_red_id'  => $parents[0] ?? null,
                'parent_match_blue_id' => $parents[1] ?? null,
            ];
        });

        return response()->json($result);
    }


    public function matches_lss(Request $request)
    {
        $tournamentSlug = $request->query('tournament');
        $tournament = Tournament::where('slug', $tournamentSlug)->firstOrFail();

            $details = MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'tournamentMatch.pool.categoryClass',
            'tournamentMatch.pool.ageCategory',
            'tournamentMatch.pool',
            'tournamentMatch.participantOne.contingent',
            'tournamentMatch.participantTwo.contingent',
            'tournamentMatch.previousMatches.scheduleDetail',
        ])
            ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
            ->whereHas('tournamentMatch')
            ->orderBy('order')
            ->get();

        $parentMap = [];
        foreach ($details as $detail) {
            $match = $detail->tournamentMatch;
            if ($match->next_match_id) {
                $parentMap[$match->next_match_id][] = $match->id;
            }
        }

        $sorted = $details->sortBy([fn($a, $b) =>
            ($a->schedule->arena->name ?? '') <=> ($b->schedule->arena->name ?? '')
        ])->values();

        $result = $sorted->map(function ($detail) use ($tournament, $parentMap) {
            $match = $detail->tournamentMatch;
            $pool = $match->pool;
            $arena = optional($detail->schedule?->arena)->name;
            $date = optional($detail->schedule)->scheduled_date;
            $start = $detail->start_time ?? null;

            $categoryClass = optional($pool->categoryClass);
            $ageCategory = optional($pool->ageCategory);
            $ageCategoryName = $ageCategory->name ?? 'Tanpa Usia';
            $className = $categoryClass->name ?? 'Tanpa Kelas';
            $gender = optional($match->participantOne)->gender === 'male' ? 'Putra' : 'Putri';

            $participantOneName = optional($match->participantOne)->name;
            $participantTwoName = optional($match->participantTwo)->name;

            if (!$participantOneName && $match->parent_match_blue_id) {
                $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                if (!$blueParentOrder && $match->parent_match_red_id) {
                    $blueParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                }
                $participantOneName = $blueParentOrder
                    ? 'Pemenang dari Partai #' . $blueParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            if (!$participantTwoName && $match->parent_match_red_id) {
                $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_red_id)->value('order');
                if (!$redParentOrder && $match->parent_match_blue_id) {
                    $redParentOrder = MatchScheduleDetail::where('tournament_match_id', $match->parent_match_blue_id)->value('order');
                }
                $participantTwoName = $redParentOrder
                    ? 'Pemenang dari Partai #' . $redParentOrder
                    : 'Pemenang dari Pertandingan Sebelumnya';
            }

            $parents = $parentMap[$match->id] ?? [];

            return [
                'tournament_name' => $tournament->name,
                'match_id' => $match->id,
                'arena_name' => $arena,
                'scheduled_date' => $date,
                'start_time' => $start,
                'pool_name' => $pool->name,
                'class_name' => "$ageCategoryName $className ($gender)",
                'age_category_name' => $ageCategoryName,
                'gender' => $gender,
                'match_number' => $detail->order,
                'match_order' => $detail->order,
                'round_level' => $match->round,
                'round_label' => $detail->round_label,
                'round_duration' => $pool->match_duration ?? 0,
                'blue_id' => $match->participantOne?->id,
                'blue_name' => $participantOneName ?? 'TBD',
                'blue_contingent' => $match->participantOne?->contingent?->name ?? 'TBD',
                'red_id' => $match->participantTwo?->id,
                'red_name' => $participantTwoName ?? 'TBD',
                'red_contingent' => $match->participantTwo?->contingent?->name ?? 'TBD',
                'parent_match_red_id' => $parents[0] ?? null,
                'parent_match_blue_id' => $parents[1] ?? null,
            ];
        });

        return response()->json($result);
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

    public function seniMatches(Request $request)
    {
        $tournamentSlug = $request->query('tournament');
        $tournament = \App\Models\Tournament::where('slug', $tournamentSlug)->firstOrFail();

        // Fallback mapper priority berdasarkan label (kalau DB belum punya round_priority)
        $priorityMap = [
            'final'       => 1000,
            'semifinal'   => 900,
            '1/2 final'   => 900,  // jaga-jaga variasi penulisan
            '1/4 final'   => 800,
            '1/8 final'   => 700,
            '1/16 final'  => 600,
            '1/32 final'  => 500,
            'penyisihan'  => 100,
        ];

        $details = \App\Models\MatchScheduleDetail::with([
            'schedule.arena',
            'schedule.tournament',
            'seniMatch.contingent',
            'seniMatch.teamMember1',
            'seniMatch.teamMember2',
            'seniMatch.teamMember3',
            'seniMatch.pool.ageCategory',
            'seniMatch.pool.tournament',
            'seniMatch.matchCategory',
        ])
        ->whereHas('schedule', fn($q) => $q->where('tournament_id', $tournament->id))
        ->whereHas('seniMatch') // hanya seni
        ->get();

        $result = $details->map(function ($detail) use ($priorityMap) {
            $match = $detail->seniMatch;
            if (!$match) return null;

            // round_label: ambil dari JADWAL (sesuai requirement)
            $roundLabel = $detail->round_label ?: $match->round_label; // fallback ke match kalau perlu
            $roundLabelNorm = $roundLabel ? strtolower(trim($roundLabel)) : null;

            // round_priority: pakai dari DB kalau ada; kalau tidak, fallback dari map di atas
            $roundPriority = $match->round_priority ?? ($roundLabelNorm ? ($priorityMap[$roundLabelNorm] ?? null) : null);

            return [
                // ====== IDs & mapping remote ======
                'remote_match_id'       => $match->id,
                'remote_schedule_id'    => $detail->match_schedule_id,
                'remote_schedule_det_id'=> $detail->id,
                'remote_contingent_id'  => $match->contingent_id,
                'remote_team_member_1'  => $match->team_member_1,
                'remote_team_member_2'  => $match->team_member_2,
                'remote_team_member_3'  => $match->team_member_3,

                // ====== Context jadwal ======
                'tournament_name'       => $match->pool->tournament->name ?? '-',
                'arena_name'            => $detail->schedule->arena->name ?? '-',
                'match_date'            => $detail->schedule->scheduled_date,
                'match_time'            => $detail->schedule->start_time,

                // ====== Pool & kategori ======
                'pool_id'               => $match->pool_id,
                'pool_name'             => $match->pool->name ?? '-',
                'age_category_id'       => $match->pool->ageCategory->id ?? null,
                'age_category'          => $match->pool->ageCategory->name ?? '-',
                'match_category_id'     => $match->match_category_id,
                'category'              => match ($match->match_category_id) {
                    2 => 'Tunggal',
                    3 => 'Ganda',
                    4 => 'Regu',
                    5 => 'Solo Kreatif',
                    default => 'Unknown',
                },
                'match_type'            => match ($match->match_category_id) {
                    2 => 'seni_tunggal',
                    3 => 'seni_ganda',
                    4 => 'seni_regu',
                    5 => 'solo_kreatif',
                    default => 'unknown',
                },
                'gender'                => $match->gender,

                // ====== Peserta (display) ======
                'contingent_name'       => $match->contingent?->name ?? 'TBD',
                'participant_1'         => $match->teamMember1?->name ?? null,
                'participant_2'         => $match->teamMember2?->name ?? null,
                'participant_3'         => $match->teamMember3?->name ?? null,

                // ====== Ordering / nomor partai ======
                'match_number'          => $detail->order,
                'match_order'           => $detail->order,

                // ====== BATTLE FIELDS untuk sinkron lokal ======
                'mode'                  => $match->mode ?? 'default',
                'battle_group'          => $match->battle_group,      // group pasangan (red/blue) per round
                'round'                 => $match->round,
                'round_label'           => $roundLabel,               // DARI JADWAL
                'round_priority'        => $roundPriority,            // dari DB / fallback map
                'corner'                => $match->corner,            // 'red' | 'blue' | null (non-battle)
                'winner_corner'         => $match->winner_corner,     // 'red' | 'blue' | null
                'parent_match_red_id'   => $match->parent_match_red_id,
                'parent_match_blue_id'  => $match->parent_match_blue_id,

                // ====== Nilai akhir kalau ada ======
                'final_score'           => null,
            ];
        })->filter()->values();

        return response()->json($result);
    }




   public function seniMatches__(Request $request) 
    {
        $tournamentSlug = $request->query('tournament');

        $matches = \App\Models\SeniMatch::with([
                'pool.tournament',
                'pool.ageCategory',
                'scheduleDetail.schedule.arena',
                'contingent',
            ])
            ->whereHas('pool.tournament', function ($q) use ($tournamentSlug) {
                $q->where('slug', $tournamentSlug);
            })
            ->get();

        $result = $matches->map(function ($match) {
            $nama1 = \App\Models\TeamMember::find($match->team_member_1)?->name;
            $nama2 = \App\Models\TeamMember::find($match->team_member_2)?->name;
            $nama3 = \App\Models\TeamMember::find($match->team_member_3)?->name;

            return [
                'remote_match_id' => $match->id,
                'remote_contingent_id' => $match->contingent_id,
                'remote_team_member_1' => $match->team_member_1,
                'remote_team_member_2' => $match->team_member_2,
                'remote_team_member_3' => $match->team_member_3,

                'tournament_name' => $match->pool->tournament->name,
                'arena_name' => $match->scheduleDetail?->schedule?->arena?->name,
                'match_date' => $match->scheduleDetail?->schedule?->scheduled_date,
                'match_time' => $match->scheduleDetail?->schedule?->start_time,
                'pool_name' => $match->pool->name,

                // ✅ Ambil match_number dari order di schedule detail
                'match_number' => $match->scheduleDetail?->order,
                'match_order' => $match->scheduleDetail?->order,

                'category' => match ($match->match_category_id) {
                    2 => 'Tunggal',
                    3 => 'Ganda',
                    4 => 'Regu',
                    5 => 'Solo Kreatif',
                    default => 'Unknown',
                },
                'match_type' => match ($match->match_category_id) {
                    2 => 'seni_tunggal',
                    3 => 'seni_ganda',
                    4 => 'seni_regu',
                    5 => 'solo_kreatif',
                    default => 'unknown',
                },
                'gender' => $match->gender,
                'contingent_name' => $match->contingent?->name ?? 'TBD',

                'participant_1' => $nama1,
                'participant_2' => $nama2,
                'participant_3' => $nama3,

                'age_category' => $match->pool->ageCategory->name,
                'final_score' => null,
            ];
        });

        return response()->json($result);
    }



    public function updateTandingMatchStatus(Request $request)
    {
        $validated = $request->validate([
            'remote_match_id' => 'required|integer',
            'status' => 'required|in:not_started,in_progress,finished',
            'participant_1_score' => 'nullable|numeric',
            'participant_2_score' => 'nullable|numeric',
            'winner_id' => 'nullable|integer',
        ]);

        TournamentMatch::where('id', $validated['remote_match_id'])
            ->update([
                'status' => $validated['status'],
                'participant_1_score' => $validated['participant_1_score'] ?? 0,
                'participant_2_score' => $validated['participant_2_score'] ?? 0,
                'winner_id' => $validated['winner_id'] ?? null,
            ]);

        return response()->json(['message' => 'Status updated']);
    }

    public function updateSeniMatchStatus(Request $request)
    {
        $validated = $request->validate([
            'remote_match_id' => 'required|integer',
            'status' => 'required|in:not_started,ongoing,finished',
            'final_score' => 'nullable|numeric',
        ]);

        SeniMatch::where('id', $validated['remote_match_id'])
            ->update([
                'status' => $validated['status'],
                'final_score' => $validated['final_score'] ?? 0,
            ]);

        return response()->json(['message' => 'Status updated']);
    }

    public function updateNextMatchSlot(Request $request)
    {
        $request->validate([
            'remote_match_id' => 'required|integer|exists:tournament_matches,id',
            'slot' => 'required|in:1,2', // 1 = biru, 2 = merah
            'winner_id' => 'required|integer',
        ]);

        $match = TournamentMatch::findOrFail($request->remote_match_id);

        if ($request->slot == 1) {
            // 🟦 Slot biru
            $match->participant_1 = $request->winner_id;
        } elseif ($request->slot == 2) {
            // 🔴 Slot merah
            $match->participant_2 = $request->winner_id;
        }

        $match->save();

        return response()->json([
            'message' => 'Peserta berhasil dimasukkan ke pertandingan selanjutnya.',
            'match_id' => $match->id,
            'slot' => $request->slot,
            'participant_id' => $request->winner_id,
        ]);
    }







}
