<?php

namespace App\Http\Controllers;

use App\Models\TeamMember;
use App\Models\BillingDetail;
use App\Models\AgeCategory;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class TeamMemberController extends Controller
{
    public function index__(Request $request)
    {
        try {
            $fetchAll = $request->query('fetch_all', false);
            $is_payment_confirmation = $request->query('is_payment_confirmation', false);
            $tournamentId = $request->query('tournament_id');
            

            $user = auth()->user();
            $search = $request->input('search', '');

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // 🔗 Query awal + eager loading
            $query = TeamMember::with([
                'contingent.tournamentContingents.tournament', // untuk tournament_name
                'championshipCategory',
                'matchCategory.tournamentCategories',
                'ageCategory',            // 👈 Tambahan
                'categoryClass', 
            ]);

            // 🔍 Search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                    ->orWhereHas('contingent', function ($qc) use ($search) {
                        $qc->where('name', 'like', "%$search%");
                    })
                    ->orWhereHas('matchCategory', function ($qm) use ($search) {
                        $qm->where('name', 'like', "%$search%");
                    });
                });
            }

            // 🎯 Filter by tournament
            if ($tournamentId) {
                $query->whereHas('contingent.tournamentContingents', function ($q) use ($tournamentId) {
                    $q->where('tournament_id', $tournamentId);
                });
            }

            // 🎯 Filter tambahan
            if ($request->filled('match_category_id')) {
                $query->where('match_category_id', $request->match_category_id);
            }

            if ($request->filled('age_category_id')) {
                $query->where('age_category_id', $request->age_category_id);
            }

            if ($request->filled('category_class_id')) {
                $query->where('category_class_id', $request->category_class_id);
            }


            // 🔐 Filter berdasarkan grup user
            if ($user->group && $user->group->name === 'Owner') {
                if ($is_payment_confirmation) {
                    // ⛔ Kalau mode konfirmasi pembayaran, jangan load semua
                    $query->whereHas('billingDetails');
                }
                // lihat semua
            } elseif ($user->group && $user->group->name === 'Event PIC') {
                $query->where(function ($q) use ($user) {
                    $q->whereHas('contingent.tournamentContingents', function ($subQ) use ($user) {
                        $subQ->where('tournament_id', $user->tournament_id);
                    })->orWhereHas('contingent', function ($subQ) use ($user) {
                        $subQ->where('owner_id', $user->id);
                    });
                });
            } else {
                $query->whereHas('contingent', function ($q) use ($user) {
                    $q->where('owner_id', $user->id);
                });
            }

            // 💰 Filter billing confirmation
            if ($is_payment_confirmation) {
                $query->whereHas('billingDetails');
            }

            // 📦 Ambil data
            $members = $fetchAll ? $query->get() : $query->paginate(10);

            // 🔁 Transform untuk inject tournament_name & billing flag
            $transform = function ($member) {
                $tournamentName = optional(
                    $member->contingent?->tournamentContingents->first()?->tournament
                )->name;

                $member->tournament_name = $tournamentName;
                $member->exists_in_billing_details = BillingDetail::where('team_member_id', $member->id)->exists();

                return $member;
            };

            if ($fetchAll) {
                $members = $members->map($transform);
            } else {
                $members->setCollection($members->getCollection()->map($transform));
            }

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index_asli(Request $request)
    {
        try {
            $fetchAll = filter_var($request->query('fetch_all', false), FILTER_VALIDATE_BOOLEAN);
            $is_payment_confirmation = filter_var($request->query('is_payment_confirmation', false), FILTER_VALIDATE_BOOLEAN);
            $tournamentId = $request->query('tournament_id');

            // ⛔ Paksa fetchAll jadi false jika sedang konfirmasi pembayaran
            if ($is_payment_confirmation) {
                $fetchAll = false;
            }

            $user = auth()->user();
            $search = $request->input('search', '');

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // 🔗 Query awal + eager loading
            $query = TeamMember::with([
                'contingent.tournamentContingents.tournament',
                'championshipCategory',
                'matchCategory.tournamentCategories',
                'ageCategory',
                'categoryClass',
            ]);

            // 🔍 Search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                        ->orWhereHas('contingent', function ($qc) use ($search) {
                            $qc->where('name', 'like', "%$search%");
                        })
                        ->orWhereHas('matchCategory', function ($qm) use ($search) {
                            $qm->where('name', 'like', "%$search%");
                        });
                });
            }

            // 🎯 Filter by tournament
            if ($tournamentId) {
                $query->whereHas('contingent.tournamentContingents', function ($q) use ($tournamentId) {
                    $q->where('tournament_id', $tournamentId);
                });
            }

            // 🎯 Filter tambahan
            if ($request->filled('match_category_id')) {
                $query->where('match_category_id', $request->match_category_id);
            }

            if ($request->filled('age_category_id')) {
                $query->where('age_category_id', $request->age_category_id);
            }

            if ($request->filled('category_class_id')) {
                $query->where('category_class_id', $request->category_class_id);
            }

            // 🔐 Filter berdasarkan grup user
            if ($user->group && $user->group->name === 'Owner') {
                if ($is_payment_confirmation) {
                    // Hanya tampilkan yang ada di billing saat konfirmasi
                    $query->whereHas('billingDetails');
                }
                // Kalau bukan konfirmasi, tetap lihat semua
            } elseif ($user->group && $user->group->name === 'Event PIC') {
                $query->where(function ($q) use ($user) {
                    $q->whereHas('contingent.tournamentContingents', function ($subQ) use ($user) {
                        $subQ->where('tournament_id', $user->tournament_id);
                    })->orWhereHas('contingent', function ($subQ) use ($user) {
                        $subQ->where('owner_id', $user->id);
                    });
                });
            } else {
                $query->whereHas('contingent', function ($q) use ($user) {
                    $q->where('owner_id', $user->id);
                });
            }

            // 💰 Filter billing confirmation (backup, boleh dihapus karena udah di atas)
            if ($is_payment_confirmation) {
                $query->whereHas('billingDetails');
            }

            // 📦 Ambil data
            $members = $fetchAll ? $query->get() : $query->paginate(10);

            // 🔁 Transform untuk inject tournament_name & billing flag
            $transform = function ($member) {
                $tournamentName = optional(
                    $member->contingent?->tournamentContingents->first()?->tournament
                )->name;

                $member->tournament_name = $tournamentName;
                $member->exists_in_billing_details = BillingDetail::where('team_member_id', $member->id)->exists();

                return $member;
            };

            if ($fetchAll) {
                $members = $members->map($transform);
            } else {
                $members->setCollection($members->getCollection()->map($transform));
            }

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function index(Request $request)
    {
        try {
            $fetchAll = filter_var($request->query('fetch_all', false), FILTER_VALIDATE_BOOLEAN);
            $is_payment_confirmation = filter_var($request->query('is_payment_confirmation', false), FILTER_VALIDATE_BOOLEAN);
            $tournamentId = $request->query('tournament_id');

            if ($is_payment_confirmation) {
                $fetchAll = false;
            }

            $user = auth()->user();
            $search = $request->input('search', '');

            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Query awal + eager loading
            $query = TeamMember::with([
                'contingent.tournamentContingents.tournament.tournamentCategories',
                'championshipCategory',
                'matchCategory',
                'ageCategory',
                'categoryClass',
                'tournamentParticipants',
            ]);

            // 🔍 Search
            if ($search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%$search%")
                        ->orWhereHas('contingent', function ($qc) use ($search) {
                            $qc->where('name', 'like', "%$search%");
                        })
                        ->orWhereHas('matchCategory', function ($qm) use ($search) {
                            $qm->where('name', 'like', "%$search%");
                        });
                });
            }

            // 🎯 Filter by tournament
            if ($tournamentId) {
                $query->whereHas('contingent.tournamentContingents', function ($q) use ($tournamentId) {
                    $q->where('tournament_id', $tournamentId);
                });
            }

            // 🎯 Filter tambahan
            if ($request->filled('match_category_id')) {
                $query->where('match_category_id', $request->match_category_id);
            }

            if ($request->filled('age_category_id')) {
                $query->where('age_category_id', $request->age_category_id);
            }

            if ($request->filled('category_class_id')) {
                $query->where('category_class_id', $request->category_class_id);
            }

            // 💳 Filter payment_status berdasarkan keikutsertaan peserta
            if ($request->filled('payment_status')) {
                if ($request->payment_status === 'paid') {
                    $query->whereHas('tournamentParticipants');
                } elseif ($request->payment_status === 'unpaid') {
                    $query->whereDoesntHave('tournamentParticipants');
                }
            }


            // 🔐 Filter berdasarkan grup user
            if ($user->group && $user->group->name === 'Owner') {
                if ($is_payment_confirmation) {
                    $query->whereHas('billingDetails');
                }
            } elseif ($user->group && $user->group->name === 'Event PIC') {
                $query->where(function ($q) use ($user) {
                    $q->whereHas('contingent.tournamentContingents', function ($subQ) use ($user) {
                        $subQ->where('tournament_id', $user->tournament_id);
                    })->orWhereHas('contingent', function ($subQ) use ($user) {
                        $subQ->where('owner_id', $user->id);
                    });
                });
            } else {
                $query->whereHas('contingent', function ($q) use ($user) {
                    $q->where('owner_id', $user->id);
                });
            }

            // 💰 Billing filter
            if ($is_payment_confirmation) {
                $query->whereHas('billingDetails');
            }

            // 📦 Ambil data
            $members = $fetchAll ? $query->get() : $query->paginate(10);

            // 🔁 Transform untuk inject tournament_name & registration_fee
            $transform = function ($member) {
                $tournamentContingent = $member->contingent?->tournamentContingents?->first();
                $tournament = $tournamentContingent?->tournament;

                $member->tournament_name = $tournament?->name;
                $member->exists_in_billing_details = BillingDetail::where('team_member_id', $member->id)->exists();

                // Inject registration_fee yang sesuai
                $matchedCategory = $tournament?->tournamentCategories
                    ?->firstWhere('match_category_id', $member->match_category_id);
                $member->registration_fee = $matchedCategory?->registration_fee;

                return $member;
            };

            if ($fetchAll) {
                $members = $members->map($transform);
            } else {
                $members->setCollection($members->getCollection()->map($transform));
            }

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }







    public function export(Request $request)
    {
        $search = $request->query('search');
        $tournamentId = $request->query('tournament_id');
        $matchCategoryId = $request->query('match_category_id');
        $ageCategoryId = $request->query('age_category_id');
        $categoryClassId = $request->query('category_class_id');
        $paymentStatus = $request->query('payment_status');

        $query = TeamMember::with([
            'contingent.tournamentContingents.tournament',
            'championshipCategory',
            'matchCategory',
            'tournamentParticipants',
        ]);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                ->orWhereHas('contingent', function ($qc) use ($search) {
                    $qc->where('name', 'like', "%{$search}%");
                });
            });
        }

        if ($tournamentId) {
            $query->whereHas('contingent.tournamentContingents', function ($q) use ($tournamentId) {
                $q->where('tournament_id', $tournamentId);
            });
        }

        if ($matchCategoryId) {
            $query->where('match_category_id', $matchCategoryId);
        }

        if ($ageCategoryId) {
            $query->where('age_category_id', $ageCategoryId);
        }

        if ($categoryClassId) {
            $query->where('category_class_id', $categoryClassId);
        }

        if ($paymentStatus === 'paid') {
            $query->whereHas('tournamentParticipants');
        } elseif ($paymentStatus === 'unpaid') {
            $query->whereDoesntHave('tournamentParticipants');
        }


        $teamMembers = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // ✅ Header
        $sheet->fromArray([
            ['ID', 'Tournaments', 'Contingent', 'Nama', 'Tempat Lahir', 'Tanggal Lahir', 'Jenis Kelamin', 'Tinggi Badan', 'Berat Badan', 'NIK', 'No. KK', 'Alamat']
        ], null, 'A1');

        // ✅ Data
        $row = 2;
        foreach ($teamMembers as $member) {
            // Ambil semua nama turnamen dari relasi tournamentContingents
            $tournamentNames = collect($member->contingent?->tournamentContingents)
                ->pluck('tournament.name')
                ->filter()
                ->unique()
                ->implode(', ');

           $sheet->setCellValue("A{$row}", $member->id);
            $sheet->setCellValue("B{$row}", $tournamentNames);
            $sheet->setCellValue("C{$row}", $member->contingent->name ?? '');
            $sheet->setCellValue("D{$row}", $member->name);
            $sheet->setCellValue("E{$row}", $member->birth_place);
            $sheet->setCellValue("F{$row}", $member->birth_date);
            $sheet->setCellValue("G{$row}", $member->gender);
            $sheet->setCellValue("H{$row}", $member->body_height);
            $sheet->setCellValue("I{$row}", $member->body_weight);

            // ✅ Khusus NIK & KK pakai format teks eksplisit
            $sheet->setCellValueExplicit("J{$row}", (string) $member->nik, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $sheet->setCellValueExplicit("K{$row}", (string) $member->family_card_number, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

            $sheet->setCellValue("L{$row}", $member->address);

            $row++;
        }

        // Set NIK dan KK sebagai string (kolom J dan K = kolom ke-10 dan 11)
        $sheet->setCellValueExplicit("J{$row}", (string) $member->nik, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $sheet->setCellValueExplicit("K{$row}", (string) $member->family_card_number, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);

        $writer = new Xlsx($spreadsheet);
        $filename = 'team_members_' . date('Ymd_His') . '.xlsx';

        return response()->stream(function () use ($writer) {
            $writer->save('php://output');
        }, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control' => 'no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }




    

    public function index_(Request $request)
    {
        try {
            // Check for a query parameter to decide the mode
            $fetchAll = $request->query('fetch_all', false);
            $is_payment_confirmation = $request->query('is_payment_confirmation', false);

            // Get the authenticated user
            $user = auth()->user();
            $search = $request->input('search', ''); // Mendapatkan parameter search dari request

            // Ensure the user is authenticated
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }

            // Determine if the user is an owner
            if ($user->group->name === 'Owner') {
                // Fetch all members without filtering by owner_id
                if ($fetchAll) {
                    if ($is_payment_confirmation) {
                        // Ambil data member yang ada di BillingDetail saat payment confirmation
                        $members = TeamMember::with(['contingent', 'championshipCategory', 'matchCategory.tournamentCategories'])
                            ->whereHas('billingDetails')  // Pastikan hanya mengambil anggota yang ada di BillingDetail
                            ->when($search, function ($query, $search) {
                                // Apply search on both name and contingent name
                                $query->where('name', 'like', '%' . $search . '%')
                                    ->orWhereHas('contingent', function ($contingentQuery) use ($search) {
                                        $contingentQuery->where('name', 'like', '%' . $search . '%');
                                    })
                                    ->orWhereHas('matchCategory', function ($matchCategoryQuery) use ($search) {
                                        $matchCategoryQuery->where('name', 'like', '%' . $search . '%');
                                    });
                            })
                            ->get();
                    } else {
                        // Ambil semua member tanpa filter
                        $members = TeamMember::with(['contingent', 'championshipCategory', 'matchCategory.tournamentCategories'])
                            ->when($search, function ($query, $search) {
                                // Apply search on both name and contingent name
                                $query->where('name', 'like', '%' . $search . '%')
                                    ->orWhereHas('contingent', function ($contingentQuery) use ($search) {
                                        $contingentQuery->where('name', 'like', '%' . $search . '%');
                                    })
                                    ->orWhereHas('matchCategory', function ($matchCategoryQuery) use ($search) {
                                        $matchCategoryQuery->where('name', 'like', '%' . $search . '%');
                                    });
                            })
                            ->get();
                    }
                } else {
                    $members = TeamMember::with(['contingent', 'championshipCategory', 'matchCategory.tournamentCategories'])
                        ->when($search, function ($query, $search) {
                            // Apply search on both name and contingent name
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhereHas('contingent', function ($contingentQuery) use ($search) {
                                    $contingentQuery->where('name', 'like', '%' . $search . '%');
                                })
                                ->orWhereHas('matchCategory', function ($matchCategoryQuery) use ($search) {
                                    $matchCategoryQuery->where('name', 'like', '%' . $search . '%');
                                });
                        })
                        ->paginate(10);
                }
            } else {
                if ($fetchAll) {
                    // Fetch all members without pagination
                    $members = TeamMember::with(['contingent', 'championshipCategory', 'matchCategory.tournamentCategories'])
                        ->whereHas('contingent', function ($query) use ($user) {
                            $query->where('owner_id', $user->id);
                        })
                        ->when($search, function ($query, $search) {
                            // Apply search on both name and contingent name
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhereHas('contingent', function ($contingentQuery) use ($search) {
                                    $contingentQuery->where('name', 'like', '%' . $search . '%');
                                })
                                ->orWhereHas('matchCategory', function ($matchCategoryQuery) use ($search) {
                                    $matchCategoryQuery->where('name', 'like', '%' . $search . '%');
                                });
                        })
                        ->get();
                } else {
                    // Fetch members filtered by the user's owner_id
                    $members = TeamMember::with(['contingent', 'championshipCategory', 'matchCategory.tournamentCategories'])
                        ->whereHas('contingent', function ($query) use ($user) {
                            $query->where('owner_id', $user->id);
                        })
                        ->when($search, function ($query, $search) {
                            // Apply search on both name and contingent name
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhereHas('contingent', function ($contingentQuery) use ($search) {
                                    $contingentQuery->where('name', 'like', '%' . $search . '%');
                                })
                                ->orWhereHas('matchCategory', function ($matchCategoryQuery) use ($search) {
                                    $matchCategoryQuery->where('name', 'like', '%' . $search . '%');
                                });
                        })
                        ->paginate(10);
                }
            }

            // Check if each member exists in BillingDetail
            $members->transform(function ($member) {
                $member->exists_in_billing_details = BillingDetail::where('team_member_id', $member->id)->exists();
                return $member;
            });

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function fetchTeamMembersBilling(){
        try {
            // Get the authenticated user
            $user = auth()->user();

            // Ensure the user is authenticated
            if (!$user) {
                return response()->json(['error' => 'Unauthorized'], 401);
            }    

            // Determine if the user is an owner
            if ($user->group->name === 'Owner') {
                // Fetch all members without filtering by owner_id
                $members = TeamMember::with('billing')->paginate(10);
            } else {
                // Fetch members filtered by the user's owner_id
                $members = TeamMember::with('billing')
                    ->whereHas('billing', function ($query) use ($user) {
                        $query->where('user_id', $user->id);
                    })
                    ->paginate(10);
            }

            return response()->json($members, 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }



    public function store(Request $request)
    {
       
        $data = $request->validate([
            'contingent_id' => 'required|exists:contingents,id',
            'name' => 'required|string',
            'birth_place' => 'required|string',
            'birth_date' => 'required|date',
            'gender' => 'required|string',
            'body_weight' => 'nullable|numeric',
            'body_height' => 'nullable|numeric',
            'blood_type' => 'nullable|string',
            'nik' => 'required|string',
            'family_card_number' => 'required|string',
            'country_id' => 'required|exists:countries,id',
            'province_id' => 'required|exists:provinces,id',
            'district_id' => 'required|exists:districts,id',
            'subdistrict_id' => 'required|exists:subdistricts,id',
            'ward_id' => 'required|exists:wards,id',
            'address' => 'required|string',
            'championship_category_id' => 'required|exists:championship_categories,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'required|exists:category_classes,id',
            'documents' => 'required|string',
        ]);

        // Create team member
        $teamMember = TeamMember::create($data);

        return response()->json($teamMember, 201);
    }


    public function show($id)
    {
        return TeamMember::findOrFail($id);
    }

    

    public function update(Request $request, $id)
    {
        // Log the request data
       
        Log::info('Update request data:', ['data' => $request->all()]);

        // Validate the request data
        $data = $request->validate([
            'contingent_id' => 'required|exists:contingents,id',
            'name' => 'required|string',
            'birth_place' => 'required|string',
            'birth_date' => 'required|date',
            'gender' => 'required|in:male,female',
            'body_weight' => 'nullable|numeric',
            'body_height' => 'nullable|numeric',
            'nik' => 'required|string|max:16',
            'family_card_number' => 'required|string|max:16',
            'country_id' => 'required|exists:countries,id',
            'province_id' => 'required|exists:provinces,id',
            'district_id' => 'required|exists:districts,id',
            'subdistrict_id' => 'required|exists:subdistricts,id',
            'ward_id' => 'required|exists:wards,id',
            'address' => 'required|string',
            'championship_category_id' => 'required|exists:championship_categories,id',
            'match_category_id' => 'required|exists:match_categories,id',
            'age_category_id' => 'required|exists:age_categories,id',
            'category_class_id' => 'required|exists:category_classes,id',
            'documents' => 'required|string',
        ]);

        // Find the existing member
        $member = TeamMember::findOrFail($id);

        // Update base attributes
        $member->update([
            'contingent_id' => $data['contingent_id'],
            'name' => $data['name'],
            'birth_place' => $data['birth_place'],
            'birth_date' => $data['birth_date'],
            'gender' => $data['gender'],
            'body_weight' => $data['body_weight'],
            'body_height' => $data['body_height'],
            'nik' => $data['nik'],
            'family_card_number' => $data['family_card_number'],
            'country_id' => $data['country_id'],
            'province_id' => $data['province_id'],
            'district_id' => $data['district_id'],
            'subdistrict_id' => $data['subdistrict_id'],
            'ward_id' => $data['ward_id'],
            'address' => $data['address'],
            'championship_category_id' => $data['championship_category_id'],
            'match_category_id' => $data['match_category_id'],
            'age_category_id' => $data['age_category_id'],
            'category_class_id' => $data['category_class_id'],
            'documents' => $data['documents'],
        ]); 

        // Return success response
        return response()->json([
            'message' => 'Member updated successfully!',
            'data' => $member,
        ], 200);
    }

    


    public function destroy($id)
    {
        try {
            $teamMember = TeamMember::findOrFail($id);
            $teamMember->delete();
            return response()->json(['message' => 'Deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
