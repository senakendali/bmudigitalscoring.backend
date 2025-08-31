<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;


use App\Http\Controllers\ProvinceController;
use App\Http\Controllers\DistrictController;
use App\Http\Controllers\SubdistrictController;
use App\Http\Controllers\WardController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\NavigationMenuController;
use App\Http\Controllers\MenuController;
use App\Http\Controllers\ContingentController; 
use App\Http\Controllers\TeamMemberController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\CountryController;
use App\Http\Controllers\TournamentController;
use App\Http\Controllers\AgeCategoryController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CategoryClassController;
use App\Http\Controllers\MatchClasificationController;
use App\Http\Controllers\MatchCategoryController;
use App\Http\Controllers\ChampionshipCategoryController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\DrawingController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\TournamentMatchController;
use App\Http\Controllers\TournamentSettingController;
use App\Http\Controllers\TournamentActivityController;
use App\Http\Controllers\TournamentMatchCategoryController;
use App\Http\Controllers\TournamentArenaController;
use App\Http\Controllers\TournamentContactPersonController;
use App\Http\Controllers\MatchScheduleController;
use App\Http\Controllers\SyncController;
use App\Http\Controllers\SeniMatchController;
use App\Models\TeamMember;

Route::get('/team-members/export', [TeamMemberController::class, 'export']);
Route::get('/contingents/export', [ContingentController::class, 'export']);


Route::middleware('auth:sanctum')->get('/user', function (Request $request) {
    $user = $request->user();

    // Eager-load group and roles
    $user->load(['group.roles']);

    // Add role names to the response
    $roles = $user->group ? $user->group->roles->pluck('name') : [];

    return [
        'user' => $user,
        'role_names' => $roles, // Returns a list of role names
    ];
});

// Permissions
Route::middleware(['auth:sanctum'])->get('/user/permissions', [PermissionController::class, 'getUserPermissions']);

// Menus
Route::middleware('auth:sanctum')->get('/menus', [MenuController::class, 'index']);

//Tournaments

//Route::apiResource('tournaments', TournamentController::class);

Route::prefix('tournaments')->group(function () {
    // Resource route for CRUD operations
    //Route::apiResource('', TournamentController::class);
    Route::middleware('auth:sanctum')->get('/', [TournamentController::class, 'index']);
    Route::apiResource('', TournamentController::class)->except(['index']);

    // Custom route
    Route::middleware('auth:sanctum')->post('register', [TournamentController::class, 'contingentRegistration']);
    Route::get('gallery', [TournamentController::class, 'getTournamentGallery']);
    Route::get('highlight', [TournamentController::class, 'getHighlightedTournament']);
    Route::get('active', [TournamentController::class, 'getActiveTournament']);
    Route::get('all', [TournamentController::class, 'getAllTournament']);
    Route::get('detail/{slug}', [TournamentController::class, 'getTournamentDetail']); 
    Route::get('{id}', [TournamentController::class, 'show']);
    Route::get('{tournament_id}/stats', [TournamentController::class, 'getTournamentStats']);
    Route::get('{tournament_id}/contingents', [TournamentController::class, 'getContingentsWithStats']);
    Route::get('{tournament_id}/contingents/summary-by-province', [TournamentController::class, 'summaryByProvince']);
    Route::get('{tournament_id}/participants-by-province', [TournamentController::class, 'getParticipantsByProvince']);
    Route::get('{tournament_id}/participants-by-age-category', [TournamentController::class, 'getParticipantsByAgeCategory']);
    Route::get('{tournament_id}/participants-by-category-class', [TournamentController::class, 'getParticipantsByCategoryClass']);
    Route::get('{tournament_id}/participants-by-district', [TournamentController::class, 'getParticipantsByDistrict']);
    Route::get('{tournament_id}/participants-by-gender', [TournamentController::class, 'getParticipantsByGender']);
    Route::get('{tournament_id}/get-tournament-income', [TournamentController::class, 'getTotalAmountByPaymentStatus']);
    Route::get('{tournament_id}/get-total-income', [TournamentController::class, 'getTotalAmount']);
    Route::get('{tournament_id}/contingents-join-by-date', [TournamentController::class, 'getContingentJoinByDate']);

    //GET /api/tournament/{tournamentId}/contingents-join-by-date?start_date=2025-03-01&end_date=2025-03-05

    
    
});

//Provinces
Route::apiResource('provinces', ProvinceController::class);
//Districts
Route::apiResource('districts', DistrictController::class);
//Subdistricts
Route::apiResource('subdistricts', SubdistrictController::class);
//Wards
Route::apiResource('wards', WardController::class);
//Age Categories
Route::apiResource('age-categories', AgeCategoryController::class);

//Championship Categories
Route::apiResource('championship-categories', ChampionshipCategoryController::class);

//Navigation Menus
Route::get('navigation-menus/fetch-all', [NavigationMenuController::class, 'fetchAllNavigation']);
Route::apiResource('navigation-menus', NavigationMenuController::class);

//Contingents
Route::middleware('auth:sanctum')->get('contingents/fetch-all', [ContingentController::class, 'fetchAll']);
Route::middleware('auth:sanctum')->get('contingents/my-contingents', [ContingentController::class, 'checkMyContingentsStatus']);
Route::middleware('auth:sanctum')->apiResource('contingents', ContingentController::class);
Route::get('/contingents/by-tournament/{tournament_id}', [ContingentController::class, 'getByTournament']);



//Team Members
/*Route::get('/team-members/export', function () {
    return Excel::download(new TeamMembersExport, 'team_members.xlsx');
});*/
Route::middleware('auth:sanctum')->apiResource('team-members', TeamMemberController::class);

//Documents
Route::get('/download-document', [DocumentController::class, 'download']);

//Billings
Route::middleware('auth:sanctum')->post('billings/add-member', [BillingController::class, 'addMember']);
Route::middleware('auth:sanctum')->post('/billings/{paymentId}/update-document', [BillingController::class, 'updateDocument']);
Route::middleware('auth:sanctum')->post('/billings/{paymentId}/confirm-payment', [BillingController::class, 'confirmPayment']);
Route::middleware('auth:sanctum')->apiResource('billings', BillingController::class);

//countries
Route::get('countries', [CountryController::class, 'index']);

//Category Classes
Route::apiResource('category-classes', CategoryClassController::class);
Route::get('category-classes/fetch-by-age-category/{ageCategoryId}', [CategoryClassController::class, 'fetchByAgeCategory']);
Route::get('category-classes/fetch-class/{ageCategoryId}', [CategoryClassController::class, 'fetchClass']);
Route::get('fetch-available-class', [CategoryClassController::class, 'getClassOnTeamMember']);

//Match Clasifications
Route::apiResource('match-clasifications', MatchClasificationController::class);

//Match Categories
Route::apiResource('match-categories', MatchCategoryController::class);

//Drawings
Route::get('show-bracket/{tournamentId}/{matchCategoryId}/{ageCategoryId}', [DrawingController::class, 'generateBracket']);
Route::post('create-pools', [DrawingController::class, 'generatePools']);
Route::post('/matches/{pool}/create-dummy', [DrawingController::class, 'createDummyOpponent']);

Route::get('pools', [DrawingController::class, 'getPools']);
Route::get('pools/{poolId}/match-list', [DrawingController::class, 'getMatchList']);
Route::get('matches-recap', [DrawingController::class, 'getAllMatchRecap']);
Route::get('pools/{poolId}', [DrawingController::class, 'detailPool']);
Route::apiResource('drawings', DrawingController::class);

//Generate Match
Route::get('/pools/{poolId}/generate-bracket', [TournamentMatchController::class, 'generateBracket']);
Route::get('/pools/{poolId}/regenerate-bracket', [TournamentMatchController::class, 'regenerateBracket']);
Route::post('/pools/next', [TournamentMatchController::class, 'getNextPoolByTournament']);
Route::get('/pools/{poolId}/matches', [TournamentMatchController::class, 'getMatches']);
Route::get('/dummy/{poolId}/matches', [TournamentMatchController::class, 'dummy']);
Route::get('/tournaments/{tournamentId}/matches', [TournamentMatchController::class, 'listMatches']);
Route::get('/match-schedules/{tournamentId}/matches', [TournamentMatchController::class, 'allMatches']);
Route::get('/tournaments/{tournamentId}/available-rounds', [TournamentMatchController::class, 'getAvailableRounds']);

// Generate Match (Seni)

Route::post('/seni/generate-match', [SeniMatchController::class, 'generate']);
Route::post('/seni/matches/regenerate', [SeniMatchController::class, 'regenerate']);
Route::post('/seni/matches/{pool}/regenerate-pool', [SeniMatchController::class, 'regeneratePool']);
Route::get('/seni/pools/{pool}/bracket', [SeniMatchController::class, 'getMatches']);
Route::get('/seni/matches/battle', [SeniMatchController::class, 'bracketByPool']);



Route::get('/seni/matches', [SeniMatchController::class, 'index']);
Route::get('/seni/match-list', [SeniMatchController::class, 'matchList']);
Route::get('/seni/participant-counts/', [SeniMatchController::class, 'getParticipantCounts']);
Route::get('/seni/tournament/{tournamentId}/available-rounds', [SeniMatchController::class, 'getAvailableRounds']);


// Tournament Settings
Route::get('/tournament-settings', [TournamentSettingController::class, 'index']);
Route::post('/tournament-settings', [TournamentSettingController::class, 'store']);
Route::get('/tournament-settings/{tournament_setting}', [TournamentSettingController::class, 'show']);
Route::post('/tournament-settings/update/{tournament_setting}', [TournamentSettingController::class, 'update']);
Route::delete('/tournament-settings/{tournament_setting}', [TournamentSettingController::class, 'destroy']);

// Tournament Activity
Route::apiResource('tournament-activities', TournamentActivityController::class);

// Tournament Category
Route::apiResource('tournament-match-categories', TournamentMatchCategoryController::class);

// Tournament Arena
Route::apiResource('tournament-arenas', TournamentArenaController::class);

// Tournament Arena by Tournament
Route::get('/tournaments/{id}/arenas', [TournamentArenaController::class, 'getByTournament']);


// Tournament Contact Person
Route::apiResource('tournament-contact-persons', TournamentContactPersonController::class);

// Tournament Schedule
Route::get('/tournaments/{slug}/match-schedules/tanding', [MatchScheduleController::class, 'getSchedules']);
Route::apiResource('match-schedules', MatchScheduleController::class);

Route::get('/tournaments/{slug}/match-schedules/seni', [SeniMatchController::class, 'getSchedules']);


// Resest Match Number
Route::get('/matches/reset-number/{tournamentId}', [MatchScheduleController::class, 'resetMatchNumber']);
Route::get('/matches/reset-order/{tournamentId}', [MatchScheduleController::class, 'resetScheduleOrder']);
Route::get('/matches/regenerate-order/{tournament_id}', [MatchScheduleController::class, 'regenerateMatchNumberAndSave']);
Route::get('/matches/reorder/{tournament_id}', [MatchScheduleController::class, 'resetScheduleMatchOrder']);

// Buat normalisasi jadwal
Route::get('/matches/force-order/{tournament_id}', [MatchScheduleController::class, 'resetMatchOrderBasedOnGetSchedules']);
Route::get('/matches/reorder-again/{tournament_id}', [MatchScheduleController::class, 'resetScheduleMatchOrderAgain']);


// Export
Route::get('/tanding/export-schedule', [MatchScheduleController::class, 'export']);
Route::get('/seni/export-schedule', [SeniMatchController::class, 'export']);








//Auth
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);
Route::middleware('auth:sanctum')->post('/logout', [AuthController::class, 'logout']);

Route::get('/users/count', [UserController::class, 'countUsersWithRole']);

Route::get('/sync/matches', [SyncController::class, 'matches']);
Route::get('/sync/matches/seni', [SyncController::class, 'seniMatches']);
Route::post('/update-tanding-match-status', [SyncController::class, 'updateTandingMatchStatus']);
Route::post('/update-seni-match-status', [SyncController::class, 'updateSeniMatchStatus']);

Route::post('/update-next-match-slot', [SyncController::class, 'updateNextMatchSlot']);

Route::get('/fetch-match-categories', [MatchCategoryController::class, 'getByTournament']);
Route::get('/fetch-age-categories', [AgeCategoryController::class, 'getByTournament']);
Route::get('/fetch-category-classes', [CategoryClassController::class, 'getByTournament']);



Route::post('/login', [AuthController::class, 'login']);

// Catch-all OPTIONS request untuk preflight
Route::options('/{any}', function () {
    return response()->json([], 204);
})->where('any', '.*');






//Export



