<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\TournamentMatch;
use App\Models\Tournament;
use App\Models\TeamMember;
use App\Models\Pool;
use App\Models\TournamentParticipant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Faker\Factory as Faker;

class DrawingController extends Controller
{
    public function index()
    {
        // Ambil semua pertandingan
        $matches = TournamentMatch::with(['participant1', 'participant2', 'participant1.contingent', 'participant2.contingent'])
            ->get();

        // Format data pertandingan
        $matchesData = $matches->map(function ($match) {
            return [
                'match_id' => $match->id,
                'round' => $match->round,
                'team_member_1_name' => $match->participant1?->name ?? 'TBD',
                'team_member_2_name' => $match->participant2?->name ?? 'TBD',
                'team_member_1_contingent' => $match->participant1?->contingent?->name ?? 'TBD',
                'team_member_2_contingent' => $match->participant2?->contingent?->name ?? 'TBD',
                'winner' => $match->winner_id ? $match->winner->name : 'TBD',
            ];
        });
        
        return response()->json([
            'data' => $matchesData
        ]);
    }
 
    public function store(Request $request)
    {
        try {
            DB::beginTransaction(); // Mulai transaksi

            $validated = $request->validate([
                'tournament_id' => 'required|exists:tournaments,id',
                'match_category_id' => 'required|exists:match_categories,id',
                'age_category_id' => 'required|exists:age_categories,id',
                'category_class_id' => 'required|exists:category_classes,id',  // Validasi sebagai single value
            ]);

            $classId = $validated['category_class_id'];  // Ambil nilai single category_class_id

            // Ambil data peserta
            $participants = TeamMember::where('category_class_id', $classId)->get()->shuffle();
            $totalParticipants = $participants->count();

            if ($totalParticipants < 2) {
                return response()->json(['message' => 'Not enough participants'], 400);
            }

            // **Hapus pertandingan sebelumnya dengan kriteria yang sama**
            TournamentMatch::where([
                'tournament_id' => $validated['tournament_id'],
                'match_category_id' => $validated['match_category_id'],
                'age_category_id' => $validated['age_category_id'],
                'category_class_id' => $validated['category_class_id'],
            ])->delete();

            // Menyesuaikan jumlah peserta agar menjadi kekuatan 2 (powers of 2)
            $nearestPowerOfTwo = pow(2, ceil(log($totalParticipants, 2))); // Mendapatkan angka terdekat yang merupakan kekuatan 2
            $preliminaryMatches = max(0, $nearestPowerOfTwo - $totalParticipants); // Menyesuaikan jumlah pertandingan pendahuluan
            $preliminaryWinners = collect();
            $matchList = collect();

            // Tentukan round pertama
            $round = $preliminaryMatches > 0 ? 1 : 0;

            // **Babak Pendahuluan - Jika ada lebih dari 2 peserta yang tidak sesuai kekuatan 2**
            for ($i = 0; $i < $preliminaryMatches * 2; $i += 2) {
                $p1 = $participants[$i];
                $p2 = $participants[$i + 1];

                // Insert pertandingan baru
                $match = TournamentMatch::create([
                    'tournament_id' => $validated['tournament_id'],
                    'match_category_id' => $validated['match_category_id'],
                    'age_category_id' => $validated['age_category_id'],
                    'category_class_id' => $validated['category_class_id'],
                    'round' => $round,  // Babak pendahuluan tetap di round 1
                    'team_member_1_id' => $p1->id,
                    'team_member_2_id' => $p2->id,
                ]);

                $preliminaryWinners->push($match->id);
                $matchList->push($match->id);
            }

            // **Babak Kedua - Pemenang babak pendahuluan langsung lolos ke babak kedua**
            $remainingParticipants = $participants->slice($preliminaryMatches * 2)->values();
            $matchSlots = collect();

            // Masukkan peserta yang langsung lolos ke babak kedua
            foreach ($remainingParticipants as $p) {
                $matchSlots->push($p->id);
            }

            // **Tambahkan pemenang babak pendahuluan ke babak kedua**
            foreach ($preliminaryWinners as $winnerId) {
                $matchSlots->push(null); // Peserta yang mendapatkan BYE
            }

            // **Atur pertandingan babak kedua berdasarkan match slots**
            $secondRoundMatches = collect();
            $round = $preliminaryMatches > 0 ? 2 : 1;  // Jika ada babak pendahuluan, mulai babak kedua dari round 2, jika tidak mulai dari round 1
            for ($i = 0; $i < $matchSlots->count(); $i += 2) {
                $team1 = $matchSlots[$i] ?? null;
                $team2 = $matchSlots[$i + 1] ?? null;

                // Insert pertandingan baru
                $match = TournamentMatch::create([
                    'tournament_id' => $validated['tournament_id'],
                    'match_category_id' => $validated['match_category_id'],
                    'age_category_id' => $validated['age_category_id'],
                    'category_class_id' => $validated['category_class_id'],
                    'round' => $round,
                    'team_member_1_id' => $team1,
                    'team_member_2_id' => $team2,
                ]);

                $secondRoundMatches->push($match->id);
                $matchList->push($match->id);
            }

            // Update next_match_id untuk babak-babak yang sesuai
            $secondRoundMatchIds = $secondRoundMatches->toArray();
            for ($i = 0; $i < count($secondRoundMatchIds); $i++) {
                $nextMatchId = $secondRoundMatchIds[$i];
                TournamentMatch::where('id', $matchList[$i])->update(['next_match_id' => $nextMatchId]);
            }

            // **Babak Ketiga (Final)**
            $round++;
            $finalMatch = TournamentMatch::create([
                'tournament_id' => $validated['tournament_id'],
                'match_category_id' => $validated['match_category_id'],
                'age_category_id' => $validated['age_category_id'],
                'category_class_id' => $validated['category_class_id'],
                'round' => $round,
                'team_member_1_id' => null,
                'team_member_2_id' => null,
            ]);

            // Update next_match_id untuk semifinal ke final
            TournamentMatch::where('id', $secondRoundMatches[0])->update(['next_match_id' => $finalMatch->id]);
            TournamentMatch::where('id', $secondRoundMatches[1])->update(['next_match_id' => $finalMatch->id]);

            DB::commit(); // Simpan transaksi
            return response()->json(['message' => 'Tournament brackets generated successfully']);
        } catch (\Exception $e) {
            DB::rollBack(); // Batalkan transaksi jika terjadi error
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

   public function createDummyOpponent($matchId)
    {
        $match = TournamentMatch::with('pool')->find($matchId);

        if (!$match) {
            return response()->json(['message' => 'Pertandingan tidak ditemukan.'], 404);
        }

        // Cek sisi mana yang kosong, dan sisi mana yang sudah ada
        if ($match->participant_1 && $match->participant_2) {
            return response()->json(['message' => 'Pertandingan sudah lengkap, tidak bisa tambah dummy.'], 400);
        }

        $participantId = $match->participant_1 ?? $match->participant_2;
        $original = TeamMember::find($participantId);

        if (!$original) {
            return response()->json(['message' => 'Peserta asli tidak ditemukan.'], 404);
        }

        $faker = \Faker\Factory::create('id_ID');
        $gender = $original->gender;

        // Generate nama tanpa gelar
        $first = $gender === 'male' ? $faker->firstNameMale : $faker->firstNameFemale;
        $last = $faker->lastName;
        $dummyName = $first . ' ' . $last;

        // Konstanta dummy
        $contingentId = 127;
        $matchCategoryId = $original->match_category_id;
        $ageCategoryId = $original->age_category_id;
        $categoryClassId = $original->category_class_id;

        // Buat dummy peserta
        $dummy = new TeamMember();
        $dummy->forceFill([
            'contingent_id' => $contingentId,
            'name' => $dummyName,
            'birth_place' => $faker->city,
            'birth_date' => $faker->date(),
            'gender' => $gender,
            'body_weight' => 70,
            'body_height' => 170,
            'blood_type' => 'O',
            'nik' => $faker->numerify('###############'),
            'family_card_number' => $faker->numerify('###############'),
            'country_id' => 103,
            'province_id' => 32,
            'district_id' => 3217,
            'subdistrict_id' => 321714,
            'ward_id' => 3217142009,
            'address' => $faker->address,
            'championship_category_id' => $original->championship_category_id, // Seni
            'match_category_id' => $matchCategoryId,
            'age_category_id' => $ageCategoryId,
            'category_class_id' => $categoryClassId,
            'registration_status' => 'approved',
            'is_dummy' => true,
        ])->save();

        // Daftarkan dummy ke tournament_participants
        $tp = TournamentParticipant::create([
            'tournament_id' => $match->pool->tournament_id,
            'team_member_id' => $dummy->id,
            'pool_id' => $match->pool_id,
        ]);

        // Assign ke slot kosong (pakai team_member_id, bukan tp.id!)
        if (!$match->participant_1) {
            $match->participant_1 = $dummy->id;
        } else {
            $match->participant_2 = $dummy->id;
        }

        // Reset pemenang
        $match->winner_id = null;
        $match->save();

        return response()->json([
            'message' => 'Dummy berhasil ditambahkan ke match.',
            'match_id' => $match->id,
            'dummy' => $dummy,
        ]);
    }





    public function generatePools__(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'nullable|exists:category_classes,id',
            'match_chart' => 'required|in:2,4,6,8,16,full_prestasi',
            'match_duration' => 'required|in:60,90,120,150,180,210,240,270,300'
        ]);

        $tournamentId = $request->tournament_id;
        $matchCategoryId = $request->match_category_id;
        $ageCategoryId = $request->age_category_id;
        $categoryClassId = $request->category_class_id;
        $matchChart = $request->match_chart;
        $matchDuration = $request->match_duration;

        // ✅ Validasi: turnamen punya kategori & usia
        $hasCategory = DB::table('tournament_categories')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->exists();

        $hasAgeCategory = DB::table('tournament_age_categories')
            ->where('tournament_id', $tournamentId)
            ->where('age_category_id', $ageCategoryId)
            ->exists();

        if (!$hasCategory || !$hasAgeCategory) {
            return response()->json(['message' => 'Kategori pertandingan atau usia tidak ditemukan di turnamen ini.'], 400);
        }

        // ✅ Ambil team_member_id yang valid
        $validTeamMemberIds = DB::table('team_members')
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when($categoryClassId, fn($q) => $q->where('category_class_id', $categoryClassId))
            ->pluck('id')
            ->toArray();

        if (count($validTeamMemberIds) === 0) {
            return response()->json(['message' => 'Tidak ada peserta yang valid untuk kategori ini.'], 400);
        }

        // ✅ Hitung peserta yang ikut turnamen ini dan cocok dengan filter
        $totalParticipant = DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->count();

        if ($totalParticipant === 0) {
            return response()->json(['message' => 'Tidak ada peserta yang ditemukan dalam turnamen ini untuk kategori tersebut.'], 400);
        }

        // ✅ Reset pool_id
        DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->update(['pool_id' => null]);

        // ✅ Hitung jumlah pool
        $totalPools = match ($matchChart) {
            'full_prestasi', 2 => 1,
            default => ceil($totalParticipant / $matchChart),
        };

        // ✅ Hapus pool lama
        DB::table('pools')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when($categoryClassId, fn($q) => $q->where('category_class_id', $categoryClassId))
            ->delete();

        // ✅ Buat pool baru
        $pools = [];
        for ($i = 1; $i <= $totalPools; $i++) {
            $pools[] = [
                'tournament_id' => $tournamentId,
                'match_category_id' => $matchCategoryId,
                'age_category_id' => $ageCategoryId,
                'category_class_id' => $categoryClassId,
                'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                'match_duration' => $matchDuration,
                'name' => 'Pool ' . $i,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('pools')->insert($pools);

        return response()->json([
            'message' => 'Pools berhasil dibuat',
            'total_participant' => $totalParticipant,
            'total_pools' => $totalPools,
            'pools' => $pools,
            'match_chart' => $matchChart,
            'match_duration' => $matchDuration
        ]);
    }

    public function generatePools__bagi_perkelas(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'nullable|exists:category_classes,id',
            'match_chart' => 'required|in:2,4,6,8,16,full_prestasi',
            'match_duration' => 'required|in:60,90,120,150,180,210,240,270,300'
        ]);

        $tournamentId = $request->tournament_id;
        $matchCategoryId = $request->match_category_id;
        $ageCategoryId = $request->age_category_id;
        $categoryClassId = $request->category_class_id;
        $matchChart = $request->match_chart;
        $matchDuration = $request->match_duration;

        // ✅ Validasi kategori & usia tersedia di turnamen
        $hasCategory = DB::table('tournament_categories')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->exists();

        $hasAgeCategory = DB::table('tournament_age_categories')
            ->where('tournament_id', $tournamentId)
            ->where('age_category_id', $ageCategoryId)
            ->exists();

        if (!$hasCategory || !$hasAgeCategory) {
            return response()->json(['message' => 'Kategori pertandingan atau usia tidak ditemukan di turnamen ini.'], 400);
        }

        // ✅ USIA DINI: 1 pool per class
        if (in_array($ageCategoryId, [1, 2])) {
            $classList = DB::table('team_members')
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->whereIn('id', function ($q) use ($tournamentId) {
                    $q->select('team_member_id')
                        ->from('tournament_participants')
                        ->where('tournament_id', $tournamentId);
                })
                ->select('category_class_id')
                ->distinct()
                ->pluck('category_class_id')
                ->filter() // buang null
                ->toArray();

            $allPools = [];
            $totalParticipant = 0;

            foreach ($classList as $classId) {
                $validIds = DB::table('team_members')
                    ->where('match_category_id', $matchCategoryId)
                    ->where('age_category_id', $ageCategoryId)
                    ->where('category_class_id', $classId)
                    ->whereIn('id', function ($q) use ($tournamentId) {
                        $q->select('team_member_id')
                            ->from('tournament_participants')
                            ->where('tournament_id', $tournamentId);
                    })
                    ->pluck('id')
                    ->toArray();

                $count = count($validIds);
                if ($count === 0) continue;

                // Reset pool_id peserta
                DB::table('tournament_participants')
                    ->where('tournament_id', $tournamentId)
                    ->whereIn('team_member_id', $validIds)
                    ->update(['pool_id' => null]);

                // Hapus pool lama untuk kombinasi ini
                DB::table('pools')
                    ->where('tournament_id', $tournamentId)
                    ->where('match_category_id', $matchCategoryId)
                    ->where('age_category_id', $ageCategoryId)
                    ->where('category_class_id', $classId)
                    ->delete();

                // Buat pool (1 pool per class)
                $allPools[] = [
                    'tournament_id' => $tournamentId,
                    'match_category_id' => $matchCategoryId,
                    'age_category_id' => $ageCategoryId,
                    'category_class_id' => $classId,
                    'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                    'match_duration' => $matchDuration,
                    'name' => "Pool",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $totalParticipant += $count;
            }

            if ($totalParticipant === 0) {
                return response()->json(['message' => 'Tidak ada peserta untuk usia dini dengan class yang valid.'], 400);
            }

            DB::table('pools')->insert($allPools);

            return response()->json([
                'message' => 'Pools berhasil dibuat per kelas untuk usia dini',
                'total_participant' => $totalParticipant,
                'total_pools' => count($allPools),
                'pools' => $allPools
            ]);
        }

        // ✅ KATEGORI LAIN: hitung berdasarkan jumlah peserta / bagan
        $validTeamMemberIds = DB::table('team_members')
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when($categoryClassId, fn($q) => $q->where('category_class_id', $categoryClassId))
            ->pluck('id')
            ->toArray();

        if (count($validTeamMemberIds) === 0) {
            return response()->json(['message' => 'Tidak ada peserta yang valid untuk kategori ini.'], 400);
        }

        $totalParticipant = DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->count();

        if ($totalParticipant === 0) {
            return response()->json(['message' => 'Tidak ada peserta ditemukan dalam turnamen ini untuk kategori tersebut.'], 400);
        }

        // Reset pool
        DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->update(['pool_id' => null]);

        // Hitung pool
        $totalPools = match ($matchChart) {
            'full_prestasi', 2 => 1,
            default => ceil($totalParticipant / $matchChart),
        };

        // Hapus pool lama
        DB::table('pools')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when($categoryClassId, fn($q) => $q->where('category_class_id', $categoryClassId))
            ->delete();

        // Buat pool
        $pools = [];
        for ($i = 1; $i <= $totalPools; $i++) {
            $pools[] = [
                'tournament_id' => $tournamentId,
                'match_category_id' => $matchCategoryId,
                'age_category_id' => $ageCategoryId,
                'category_class_id' => $categoryClassId,
                'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                'match_duration' => $matchDuration,
                'name' => "Pool {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('pools')->insert($pools);

        return response()->json([
            'message' => 'Pools berhasil dibuat',
            'total_participant' => $totalParticipant,
            'total_pools' => $totalPools,
            'pools' => $pools
        ]);
    }

    public function generatePools_bagi_per_kelas(Request $request)
    {   
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'nullable|exists:category_classes,id',
            'match_chart' => 'required|in:2,4,6,8,16,full_prestasi',
            'match_duration' => 'required|in:60,90,120,150,180,210,240,270,300'
        ]);

        $tournamentId = $request->tournament_id;
        $matchCategoryId = $request->match_category_id;
        $ageCategoryId = $request->age_category_id;
        $categoryClassId = $request->category_class_id;
        $matchChart = $request->match_chart;
        $matchDuration = $request->match_duration;

        $hasCategory = DB::table('tournament_categories')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->exists();

        $hasAgeCategory = DB::table('tournament_age_categories')
            ->where('tournament_id', $tournamentId)
            ->where('age_category_id', $ageCategoryId)
            ->exists();

        if (!$hasCategory || !$hasAgeCategory) {
            return response()->json(['message' => 'Kategori pertandingan atau usia tidak ditemukan di turnamen ini.'], 400);
        }

        $categoryClasses = $ageCategoryId == 1
            ? DB::table('category_classes')->get()
            : collect([$categoryClassId]);

        if ($categoryClasses->isEmpty()) {
            return response()->json(['message' => 'Tidak ada kategori kelas ditemukan.'], 400);
        }

        foreach ($categoryClasses as $class) {
            $classId = $class->id ?? null;

            $validTeamMemberIds = DB::table('team_members')
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->when($ageCategoryId != 1 && $classId, function ($q) use ($classId) {
                    $q->where('category_class_id', $classId);
                })
                ->pluck('id')
                ->toArray();

            if (count($validTeamMemberIds) === 0) continue;

            DB::table('tournament_participants')
                ->where('tournament_id', $tournamentId)
                ->whereIn('team_member_id', $validTeamMemberIds)
                ->update(['pool_id' => null]);

            DB::table('pools')
                ->where('tournament_id', $tournamentId)
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->when($ageCategoryId != 1 && $classId, function ($q) use ($classId) {
                    $q->where('category_class_id', $classId);
                })
                ->delete();
        }

        $createdPools = [];
        $totalParticipant = 0;
        $poolCounter = 1;

        foreach ($categoryClasses as $class) {
            $classId = $class->id ?? null;

            $participantIds = DB::table('team_members')
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->when($ageCategoryId != 1 && $classId, function ($q) use ($classId) {
                    $q->where('category_class_id', $classId);
                })
                ->pluck('id')
                ->toArray();

            $participantCount = DB::table('tournament_participants')
                ->where('tournament_id', $tournamentId)
                ->whereIn('team_member_id', $participantIds)
                ->count();

            $totalParticipant += $participantCount;

            if ($participantCount === 0) continue;

            $totalPools = in_array($matchChart, ['full_prestasi', 2]) ? 1 : ceil($participantCount / $matchChart);

            for ($i = 1; $i <= $totalPools; $i++) {
                $createdPools[] = [
                    'tournament_id' => $tournamentId,
                    'match_category_id' => $matchCategoryId,
                    'age_category_id' => $ageCategoryId,
                    'category_class_id' => $ageCategoryId == 1 ? $class->id : $categoryClassId,
                    'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                    'match_duration' => $matchDuration,
                    'name' => "Pool " . ($poolCounter++),
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        DB::table('pools')->insert($createdPools);

        return response()->json([
            'message' => 'Pools berhasil dibuat',
            'total_participant' => $totalParticipant,
            'total_pools' => count($createdPools),
            'pools' => $createdPools,
        ]);
    }

    public function generatePools(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'nullable|exists:category_classes,id', // tidak wajib
            'match_chart' => 'required|in:2,4,6,8,16,full_prestasi',
            'match_duration' => 'required|in:60,90,120,150,180,210,240,270,300'
        ]);

        $tournamentId = $request->tournament_id;
        $matchCategoryId = $request->match_category_id;
        $ageCategoryId = $request->age_category_id;
        $matchChart = $request->match_chart;
        $matchDuration = $request->match_duration;

        // ✅ Validasi kategori & usia tersedia di turnamen
        $hasCategory = DB::table('tournament_categories')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->exists();

        $hasAgeCategory = DB::table('tournament_age_categories')
            ->where('tournament_id', $tournamentId)
            ->where('age_category_id', $ageCategoryId)
            ->exists();

        if (!$hasCategory || !$hasAgeCategory) {
            return response()->json(['message' => 'Kategori pertandingan atau usia tidak ditemukan di turnamen ini.'], 400);
        }

        // ✅ Ambil semua kelas jika tidak dikirim
        $categoryClassIds = $request->filled('category_class_id')
            ? [$request->category_class_id]
            : DB::table('team_members')
                ->join('tournament_participants', 'team_members.id', '=', 'tournament_participants.team_member_id')
                ->where('team_members.match_category_id', $matchCategoryId)
                ->where('team_members.age_category_id', $ageCategoryId)
                ->where('tournament_participants.tournament_id', $tournamentId)
                ->pluck('team_members.category_class_id')
                ->unique()
                ->toArray();

        if (empty($categoryClassIds)) {
            return response()->json(['message' => 'Tidak ada kelas yang ditemukan untuk turnamen ini.'], 400);
        }

        $result = [];

        foreach ($categoryClassIds as $categoryClassId) {
            // ✅ Ambil peserta valid
            $validTeamMemberIds = DB::table('team_members')
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->where('category_class_id', $categoryClassId)
                ->pluck('id')
                ->toArray();

            if (count($validTeamMemberIds) === 0) {
                continue;
            }

            $totalParticipant = DB::table('tournament_participants')
                ->where('tournament_id', $tournamentId)
                ->whereIn('team_member_id', $validTeamMemberIds)
                ->count();

            if ($totalParticipant === 0) {
                continue;
            }

            // ✅ Reset pool_id & hapus pool lama
            DB::table('tournament_participants')
                ->where('tournament_id', $tournamentId)
                ->whereIn('team_member_id', $validTeamMemberIds)
                ->update(['pool_id' => null]);

            DB::table('pools')
                ->where('tournament_id', $tournamentId)
                ->where('match_category_id', $matchCategoryId)
                ->where('age_category_id', $ageCategoryId)
                ->where('category_class_id', $categoryClassId)
                ->delete();

            // ✅ Hitung jumlah pool
            if ($matchChart === 'full_prestasi' || (int)$matchChart === 2) {
                $totalPools = 1;
            } else {
                $totalPools = ceil($totalParticipant / $matchChart);
            }

            $pools = [];
            for ($i = 1; $i <= $totalPools; $i++) {
                $pools[] = [
                    'tournament_id' => $tournamentId,
                    'match_category_id' => $matchCategoryId,
                    'age_category_id' => $ageCategoryId,
                    'category_class_id' => $categoryClassId,
                    'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                    'match_duration' => $matchDuration,
                    'name' => "Pool {$i}",
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            DB::table('pools')->insert($pools);

            $result[] = [
                'category_class_id' => $categoryClassId,
                'total_participant' => $totalParticipant,
                'total_pools' => $totalPools,
                'pools' => $pools
            ];
        }

        if (empty($result)) {
            return response()->json(['message' => 'Tidak ada pool yang dibuat karena tidak ada peserta yang valid.'], 400);
        }

        return response()->json([
            'message' => 'Pools berhasil dibuat',
            'data' => $result
        ]);
    }




    public function generatePools_backup(Request $request)
    {
        $request->validate([
            'tournament_id' => 'required|exists:tournaments,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'nullable|exists:category_classes,id',
            'match_chart' => 'required|in:2,4,6,8,16,full_prestasi',
            'match_duration' => 'required|in:60,90,120,150,180,210,240,270,300'
        ]);

        $tournamentId = $request->tournament_id;
        $matchCategoryId = $request->match_category_id;
        $ageCategoryId = $request->age_category_id;
        $categoryClassId = $request->category_class_id;
        $matchChart = $request->match_chart;
        $matchDuration = $request->match_duration;

        // ✅ Validasi kategori & usia tersedia di turnamen
        $hasCategory = DB::table('tournament_categories')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->exists();

        $hasAgeCategory = DB::table('tournament_age_categories')
            ->where('tournament_id', $tournamentId)
            ->where('age_category_id', $ageCategoryId)
            ->exists();

        if (!$hasCategory || !$hasAgeCategory) {
            return response()->json(['message' => 'Kategori pertandingan atau usia tidak ditemukan di turnamen ini.'], 400);
        }

        // ✅ Ambil peserta yang cocok
        $validTeamMemberIds = DB::table('team_members')
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when(!in_array($ageCategoryId, [1]) && $categoryClassId, function ($q) use ($categoryClassId) {
                $q->where('category_class_id', $categoryClassId);
            })
            ->pluck('id')
            ->toArray();

        if (count($validTeamMemberIds) === 0) {
            return response()->json(['message' => 'Tidak ada peserta yang valid untuk kategori ini.'], 400);
        }

        // ✅ Hitung peserta dalam turnamen
        $totalParticipant = DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->count();

        if ($totalParticipant === 0) {
            return response()->json(['message' => 'Tidak ada peserta ditemukan dalam turnamen ini untuk kategori tersebut.'], 400);
        }

        // ✅ Reset pool_id
        DB::table('tournament_participants')
            ->where('tournament_id', $tournamentId)
            ->whereIn('team_member_id', $validTeamMemberIds)
            ->update(['pool_id' => null]);

        // ✅ Hapus pool lama
        DB::table('pools')
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->when(!in_array($ageCategoryId, [1]) && $categoryClassId, function ($q) use ($categoryClassId) {
                $q->where('category_class_id', $categoryClassId);
            })
            ->delete();

        // ✅ Hitung jumlah pool
        $totalPools = in_array($ageCategoryId, [1])
            ? 1
            : match ($matchChart) {
                'full_prestasi', 2 => 1,
                default => ceil($totalParticipant / $matchChart),
            };

        // ✅ Buat pool
        $pools = [];
        for ($i = 1; $i <= $totalPools; $i++) {
            $pools[] = [
                'tournament_id' => $tournamentId,
                'match_category_id' => $matchCategoryId,
                'age_category_id' => $ageCategoryId,
                'category_class_id' => in_array($ageCategoryId, [1]) ? null : $categoryClassId,
                'match_chart' => $matchChart === 'full_prestasi' ? 0 : $matchChart,
                'match_duration' => $matchDuration,
                'name' => "Pool {$i}",
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('pools')->insert($pools);

        return response()->json([
            'message' => 'Pools berhasil dibuat',
            'total_participant' => $totalParticipant,
            'total_pools' => $totalPools,
            'pools' => $pools
        ]);
    }

    public function getPools(Request $request)
    {
        // Ambil tournament_id dari query jika ada
        $filterTournamentId = $request->query('tournament_id');

        // Query pool + relasi
        $poolQuery = Pool::with([
            'tournament:id,name',
            'matchCategory:id,name',
            'ageCategory:id,name',
            'categoryClass:id,name,gender,weight_min,weight_max,age_category_id',
            'categoryClass.ageCategory:id,name'
        ])
        ->withCount('matches');

        // Apply filter jika tournament_id tersedia
        if ($filterTournamentId) {
            $poolQuery->where('tournament_id', $filterTournamentId);
        }

        $pools = $poolQuery->get();

        // Ambil satu id turnamen & match_category (untuk ambil peserta relevan)
        $tournamentId = $filterTournamentId ?: $pools->pluck('tournament_id')->first();
        $matchCategoryId = $pools->pluck('match_category_id')->first();

        // Ambil semua team members yang relevan
        $teamMembers = TeamMember::whereHas('tournamentParticipants', function ($q) use ($tournamentId, $matchCategoryId) {
                $q->where('tournament_id', $tournamentId)
                ->when($matchCategoryId, fn($q) => $q->where('match_category_id', $matchCategoryId));
            })
            ->with(['categoryClass', 'ageCategory'])
            ->get();

        // Kelompokkan berdasarkan category_class_id dan age_category_id
        $groupedByClassId = $teamMembers
            ->filter(fn($tm) => $tm->category_class_id !== null)
            ->groupBy('category_class_id');

        $groupedByAgeId = $teamMembers
            ->filter(fn($tm) => $tm->age_category_id !== null)
            ->groupBy('age_category_id');

        // Transformasi hasil pool
        $pools = $pools->map(function ($pool) use ($groupedByClassId, $groupedByAgeId) {
            $class = $pool->categoryClass;
            $classId = $class?->id;
            $ageCategoryId = $pool->age_category_id;

            $available = $classId
                ? ($groupedByClassId->get($classId)?->count() ?? 0)
                : ($groupedByAgeId->get($ageCategoryId)?->count() ?? 0);

            return [
                'pool_id' => $pool->id,
                'tournament_id' => $pool->tournament_id,
                'tournament_name' => $pool->tournament->name ?? null,
                'match_category' => $pool->matchCategory->name ?? null,
                'age_category' => $pool->ageCategory->name ?? null,
                'category_class' => [
                    'id' => $classId,
                    'name' => $class?->name,
                    'gender' => $class?->gender,
                    'weight_min' => $class?->weight_min,
                    'weight_max' => $class?->weight_max,
                    'available_athletes' => $available
                ],
                'name' => $pool->name,
                'match_chart' => $pool->match_chart,
                'matches_count' => $pool->matches_count,
            ];
        });

        return response()->json([
            'message' => 'Pools retrieved successfully',
            'data' => $pools
        ]);
    }

    public function getMatchList($poolId)
    {
        $tournamentMatches = TournamentMatch::with([
            'pool:id,name,tournament_id',
            'pool.tournament:id,name', // Pastikan "name" ikut diambil
            'participantOne:id,name,contingent_id', // Tambahkan contingent_id
            'participantOne.contingent:id,name', // Ambil nama kontingen peserta 1
            'participantTwo:id,name,contingent_id',
            'participantTwo.contingent:id,name', // Ambil nama kontingen peserta 2
            'winner:id,name,contingent_id',
            'winner.contingent:id,name'          
        ])->where('pool_id', $poolId)
        ->get()
        ->map(function ($match) {
            return [
                'match_id' => $match->id,
                'pool_id' => $match->pool->id,
                'pool_name' => $match->pool->name ?? null,
                'tournament_name' => $match->pool->tournament->name ?? null,
                'round' => $match->round,
                'participant_one_contingent' => $match->participantOne->contingent->name ?? null,
                'participant_two_contingent' => $match->participantTwo->contingent->name ?? null,
                'participant_one' => $match->participantOne->name ?? null,
                'participant_two' => $match->participantTwo->name ?? null,
                'winner' => $match->winner->name ?? null,
                'winner_contingent' => $match->winner->contingent->name ?? null,
                
            ];
        });

        return response()->json([
            'message' => 'Pools retrieved successfully',
            'data' => $tournamentMatches
        ]);
    }

    public function getAllMatchRecap(Request $request)
    {
        $tournamentId = $request->query('tournament_id');

        $matches = TournamentMatch::with([
            'pool:id,name,tournament_id,category_class_id,age_category_id',
            'pool.ageCategory:id,name',
            'pool.categoryClass:id,name,gender,weight_min,weight_max',
            'participantOne:id,name,contingent_id',
            'participantOne.contingent:id,name',
            'participantTwo:id,name,contingent_id',
            'participantTwo.contingent:id,name',
            'winner:id,name,contingent_id',
            'winner.contingent:id,name'
        ])
        ->whereHas('pool', function ($query) use ($tournamentId) {
            if ($tournamentId) {
                $query->where('tournament_id', $tournamentId);
            }
        })
        ->get();

        $grouped = [];

        foreach ($matches as $match) {
            $age = $match->pool->ageCategory->name ?? 'Tanpa Usia';
            $gender = $match->pool->categoryClass->gender ?? 'unknown';
            $pool = $match->pool->name ?? 'Tanpa Pool';
            $kelas = $match->pool->categoryClass->name ?? 'Gabungan Kelas';

            $grouped[$age][$gender][$pool][$kelas][] = [
                'match_id' => $match->id,
                'round' => $match->round_label,
                'participant_one_contingent' => $match->participantOne->contingent->name ?? '-',
                'participant_two_contingent' => $match->participantTwo->contingent->name ?? '-',
                'participant_one' => $match->participantOne->name ?? '-',
                'participant_two' => $match->participantTwo->name ?? '-',
                'winner' => $match->winner->name ?? '-',
                'winner_contingent' => $match->winner->contingent->name ?? '-',
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => $grouped
        ]);
    }




    /**
     * Mencari lawan dengan tim yang berbeda
     */
    private function findOpponent($participants, $p1)
    {
        // Jika p1 sudah mendapatkan BYE (tidak memiliki lawan), lewati
        if ($p1 === null) {
            return null; // BYE tidak membutuhkan lawan
        }
    
        // Cari peserta yang tidak berasal dari tim yang sama dan bukan peserta yang mendapatkan BYE
        foreach ($participants as $key => $p2) {
            if ($p2 === null) {
                continue; // Lewati peserta yang mendapatkan BYE
            }
    
            // Cek jika peserta p2 adalah lawan yang valid
            if (isset($p1->contingent_id) && isset($p2->contingent_id)) {
                if ($p2->contingent_id !== $p1->contingent_id) {
                    return $participants->splice($key, 1)->first();
                }
            }
        }
    
        // Jika tidak ditemukan lawan valid, kembalikan peserta terakhir (BYE)
        return $participants->pop();
    }
    
    public function generateBracket($tournamentId, $matchCategoryId, $ageCategoryId)
    {
        $matches = TournamentMatch::with([
                'participant1',
                'participant2',
                'participant1.contingent',
                'participant2.contingent'
            ])
            ->where('tournament_id', $tournamentId)
            ->where('match_category_id', $matchCategoryId)
            ->where('age_category_id', $ageCategoryId)
            ->orderBy('round')
            ->get();

        $bracket = [];
        $winners = [];

        foreach ($matches as $match) {
            $participant1 = $match->participant1;
            $participant2 = $match->participant2;

            $bracket[$match->round][] = [
                'match_id' => $match->id,
                'round' => $match->round,
                'next_match_id' => $match->next_match_id,
                'player1' => [
                    'id' => $participant1?->id ?? null,
                    'name' => $participant1?->name ?? 'TBD',
                    'contingent' => $participant1?->contingent?->name ?? 'TBD',
                    'winner' => $match->winner_id === $participant1?->id,
                ],
                'player2' => [
                    'id' => $participant2?->id ?? null,
                    'name' => $participant2?->name ?? 'TBD',
                    'contingent' => $participant2?->contingent?->name ?? 'TBD',
                    'winner' => $match->winner_id === $participant2?->id,
                ],
            ];

            // Tentukan pemenang untuk ronde berikutnya
            if ($match->winner_id) {
                $winners[$match->round + 1][] = $match->winner_id;
            } else {
                $winners[$match->round + 1][] = "TBD";
            }
        }

        $bracket = $this->addNextRounds($bracket, $winners);

        return response()->json([
            'bracket' => $this->formatBracketForVue($bracket),
            'winners' => $winners,
            'finalMatch' => $this->buildFinalBracket($winners)
        ]);
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
                        'team_member_1_contingent' => "TBD",
                        'team_member_2_contingent' => "TBD",
                        'winner' => "TBD",
                    ];
                }
                $bracket[$round] = $nextRoundMatches;
            }
        }

        return $bracket;
    }

    private function getParticipantName($participantId)
    {
        if ($participantId === "TBD") return "TBD";

        $participant = TournamentParticipant::find($participantId);
        return $participant ? $participant->name : "TBD";
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


    public function show($id)
    {
        // Implementasi Show jika diperlukan
    }

   public function detailPool($id)
    {
        $pool = Pool::with([
            'ageCategory:id,name',
            'categoryClass:id,name,gender,weight_min,weight_max',
            'matchCategory:id,name',
        ])->findOrFail($id);

        return response()->json([
            'id' => $pool->id,
            'pool_name' => $pool->name,
            'age_category_name' => $pool->ageCategory?->name,
            'class_name' => $pool->categoryClass?->name,
            'gender' => $pool->categoryClass?->gender,
            'weight_min' => $pool->categoryClass?->weight_min,
            'weight_max' => $pool->categoryClass?->weight_max,
            'match_category_name' => $pool->matchCategory?->name,
        ]);
    }




    public function update(Request $request, $id)
    {
        // Implementasi Update jika diperlukan
    }

    public function destroy($id)
    {
        // Implementasi Destroy jika diperlukan
    }
}
