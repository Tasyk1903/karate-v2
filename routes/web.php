<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordRecoveryController;
use App\Http\Controllers\Auth\StudentRegistrationController;
use App\Http\Controllers\Auth\TrainerRegistrationController;
use App\Http\Controllers\Panel\AboutController;
use App\Http\Controllers\Panel\Account\AgreementController;
use App\Http\Controllers\Panel\Account\DashboardController;
use App\Http\Controllers\Panel\Account\NotificationController;
use App\Http\Controllers\Panel\Account\ProfileController;
use App\Http\Controllers\Panel\Account\StudentAccountController;
use App\Http\Controllers\Panel\ExaminationController;
use App\Http\Controllers\Panel\OrganizationSettingsController;
use App\Http\Controllers\Panel\PanelTaskController;
use App\Http\Controllers\Panel\RatingController;
use App\Http\Controllers\Panel\StudentController;
use App\Http\Controllers\Panel\StudentEducationController;
use App\Http\Controllers\Panel\TeamController;
use App\Http\Controllers\Panel\TeamMemberDeletionController;
use App\Http\Controllers\Panel\TemplateStudentListController;
use App\Http\Controllers\Panel\TournamentController;
use App\Http\Controllers\Panel\Tournaments\BracketController;
use App\Http\Controllers\Panel\Tournaments\ExternalFormEditorController;
use App\Http\Controllers\Panel\Tournaments\ExternalFormStudentLinkController;
use App\Http\Controllers\Panel\Tournaments\KataTableController;
use App\Http\Controllers\Panel\Tournaments\OrganizationApplicationController;
use App\Http\Controllers\Panel\Tournaments\StudentEnrollmentController;
use App\Http\Controllers\Panel\Tournaments\TournamentBulkController;
use App\Http\Controllers\Panel\Tournaments\TournamentCoachController;
use App\Http\Controllers\Panel\Tournaments\TournamentDownloadController;
use App\Http\Controllers\Panel\Tournaments\TournamentFormController;
use App\Http\Controllers\Panel\Tournaments\TournamentItemController;
use App\Http\Controllers\Panel\Tournaments\TournamentListController;
use App\Http\Controllers\Panel\Tournaments\TournamentOptionController;
use App\Http\Controllers\Panel\Tournaments\TournamentStudentController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\ProtectedDocumentController;
use App\Http\Controllers\PublicAgreementController;
use App\Http\Controllers\PublicExternalFormController;
use App\Http\Controllers\PublicMediaController;
use App\Http\Middleware\ExaminationAccess;
use App\Http\Middleware\PanelAgreementConsent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/api/auth/login', [LoginController::class, 'store'])->middleware('guest');
Route::post('/api/auth/forgot-password', [PasswordRecoveryController::class, 'request'])->middleware('throttle:5,1,password-recovery');
Route::post('/api/auth/reset-password', [PasswordRecoveryController::class, 'reset'])->middleware('throttle:10,1,password-reset');
Route::post('/api/auth/student-registration', [StudentRegistrationController::class, 'store'])->middleware(['guest', 'throttle:5,1']);
Route::post('/api/auth/student-registration/confirm', [StudentRegistrationController::class, 'confirm'])->middleware(['guest', 'throttle:10,1']);
Route::post('/api/auth/trainer-registration', [TrainerRegistrationController::class, 'store'])->middleware(['guest', 'throttle:5,1']);
Route::post('/api/auth/trainer-registration/confirm', [TrainerRegistrationController::class, 'confirm'])->middleware(['guest', 'throttle:10,1']);
Route::get('/storage/{path}', PublicMediaController::class)->where('path', '.*');
Route::get('/api/auth/user', [LoginController::class, 'user'])->middleware('auth');
Route::post('/api/auth/logout', [LoginController::class, 'destroy'])->middleware('auth');
Route::get('/api/external-form/{token}', [PublicExternalFormController::class, 'show']);
Route::put('/api/external-form/{token}', [PublicExternalFormController::class, 'save']);
Route::get(
    '/api/panel/tournaments/{championship}/items/{tournament}/students/{studentTournament}/online-kata-video/{round}',
    [TournamentStudentController::class, 'showOnlineKataVideo']
);
Route::get('/online-kata/payment/return/{tournament}', [PaymentCallbackController::class, 'returnOnlineKata'])
    ->name('online-kata.payment.return');

Route::middleware(['auth', PanelAgreementConsent::class])->prefix('api/panel')->group(function () {
    Route::get('tasks/{task}', [PanelTaskController::class, 'show'])->whereUuid('task');
    Route::get('tasks/{task}/file', [PanelTaskController::class, 'file'])->whereUuid('task');
    Route::prefix('account')->group(function (): void {
        Route::get('profile', [ProfileController::class, 'show']);
        Route::post('profile', [ProfileController::class, 'update']);
        Route::get('dashboard', DashboardController::class);
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'read']);
        Route::post('notifications/{notification}/read', [NotificationController::class, 'read'])->whereNumber('notification');
        Route::get('agreements', [AgreementController::class, 'index']);
        Route::get('agreements/{agreement}', [AgreementController::class, 'show'])->whereNumber('agreement');
        Route::post('agreements/{agreement}/accept', [AgreementController::class, 'accept'])->whereNumber('agreement');
    });
    Route::get('files/users/{owner}/{document}', ProtectedDocumentController::class);
    Route::get('about', AboutController::class);
    Route::get('settings', [OrganizationSettingsController::class, 'show']);
    Route::put('settings', [OrganizationSettingsController::class, 'update']);
    Route::get('rating', RatingController::class);
    Route::get('tournaments', [TournamentController::class, 'index']);
    Route::post('tournaments', [TournamentController::class, 'store']);
    Route::put('tournaments/{championship}', [TournamentController::class, 'update']);
    Route::delete('tournaments/{championship}/items/{tournament}', [TournamentItemController::class, 'destroyTournament']);
    Route::post('tournaments/{championship}/items/{tournament}/coaches/bulk-detach', [TournamentBulkController::class, 'coaches']);
    Route::post('tournaments/{championship}/items/{tournament}/lists/bulk-detach', [TournamentBulkController::class, 'lists']);
    Route::post('tournaments/{championship}/forms/bulk-delete', [TournamentBulkController::class, 'forms']);
    Route::get('tournament-applications', [OrganizationApplicationController::class, 'index']);
    Route::post('tournament-applications/{tournament}/apply', [OrganizationApplicationController::class, 'apply']);
    Route::post('tournament-applications/{application}/decision', [OrganizationApplicationController::class, 'decision']);
    Route::get('tournament-applications/{application}/team', [OrganizationApplicationController::class, 'team']);
    Route::post('tournament-applications/{application}/coaches', [OrganizationApplicationController::class, 'coaches']);
    Route::get('tournaments/{championship}/items/{tournament}', [TournamentItemController::class, 'showTournament']);
    Route::post('tournaments/{championship}/items/{tournament}/self', [StudentEnrollmentController::class, 'store']);
    Route::delete('tournaments/{championship}/items/{tournament}/self/{membership}', [StudentEnrollmentController::class, 'destroy']);
    Route::get('tournaments/{championship}/items/{tournament}/downloads/{type}', TournamentDownloadController::class);
    Route::get('tournaments/{championship}/items/{tournament}/brackets/{list}', [BracketController::class, 'show']);
    Route::post('tournaments/{championship}/items/{tournament}/brackets/generate', [BracketController::class, 'generate']);
    Route::post('tournaments/{championship}/items/{tournament}/brackets/{list}/round-robin/winners', [BracketController::class, 'setRoundRobinWinners']);
    Route::post('tournaments/{championship}/items/{tournament}/brackets/{list}/swap', [BracketController::class, 'swapParticipants']);
    Route::post('tournaments/{championship}/items/{tournament}/pools/{pool}/winner', [BracketController::class, 'setWinner']);
    Route::post('tournaments/{championship}/items/{tournament}/pools/{pool}/absences', [BracketController::class, 'setAbsences']);
    Route::post('tournaments/{championship}/items/{tournament}/pools/{pool}/tatami', [BracketController::class, 'updateTatami']);
    Route::get('tournaments/{championship}/items/{tournament}/kata/{list}', [KataTableController::class, 'show']);
    Route::get('tournaments/{championship}/items/{tournament}/kata/{list}/pdf', [KataTableController::class, 'downloadPdf']);
    Route::post('tournaments/{championship}/items/{tournament}/kata/{list}/finalists-count', [KataTableController::class, 'updateFinalistsCount']);
    Route::post('tournaments/{championship}/items/{tournament}/kata/{list}/final', [KataTableController::class, 'generateFinal']);
    Route::post('tournaments/{championship}/items/{tournament}/kata/{list}/winners', [KataTableController::class, 'generateWinners']);
    Route::post('tournaments/{championship}/items/{tournament}/kata-pools/{kataPool}/number', [KataTableController::class, 'updateNumber']);
    Route::post('tournaments/{championship}/items/{tournament}/kata-pools/{kataPool}/score', [KataTableController::class, 'updateScore']);
    Route::post('tournaments/{championship}/items/{tournament}/kata-pools/{kataPool}/final-video', [KataTableController::class, 'updateFinalVideo']);
    Route::get('tournaments/{championship}/items/{tournament}/attach-options/{kind}', [TournamentOptionController::class, 'index']);
    Route::post('tournaments/{championship}/items/{tournament}/coaches', [TournamentCoachController::class, 'attachCoaches']);
    Route::delete('tournaments/{championship}/items/{tournament}/coaches/{coach}', [TournamentCoachController::class, 'detachCoach']);
    Route::post('tournaments/{championship}/items/{tournament}/students', [TournamentStudentController::class, 'attachStudents']);
    Route::get('tournaments/{championship}/items/{tournament}/student-attach-options', [TournamentStudentController::class, 'attachOptions']);
    Route::post('tournaments/{championship}/items/{tournament}/students/group', [TournamentStudentController::class, 'attachGroupStudents']);
    Route::patch('tournaments/{championship}/items/{tournament}/students/{studentTournament}', [TournamentStudentController::class, 'updateStudent']);
    Route::put('tournaments/{championship}/items/{tournament}/students/{studentTournament}/list', [TournamentStudentController::class, 'moveStudentList']);
    Route::delete('tournaments/{championship}/items/{tournament}/students/{studentTournament}', [TournamentStudentController::class, 'detachStudent']);
    Route::post('tournaments/{championship}/items/{tournament}/lists/create', [TournamentListController::class, 'storeList']);
    Route::post('tournaments/{championship}/items/{tournament}/lists', [TournamentListController::class, 'attachLists']);
    Route::post('tournaments/{championship}/items/{tournament}/lists/{listTournament}/tatami', [TournamentListController::class, 'updateTatami']);
    Route::delete('tournaments/{championship}/items/{tournament}/lists/{listTournament}', [TournamentListController::class, 'detachList']);
    Route::post('tournaments/{championship}/items', [TournamentItemController::class, 'storeTournament']);
    Route::put('tournaments/{championship}/items/{tournament}', [TournamentItemController::class, 'updateTournament']);
    Route::post('tournaments/{championship}/forms', [TournamentFormController::class, 'storeForm']);
    Route::get('tournaments/{championship}/forms/{form}', [ExternalFormEditorController::class, 'show']);
    Route::get('tournaments/{championship}/forms/{form}/link-options', [ExternalFormStudentLinkController::class, 'index']);
    Route::post('tournaments/{championship}/forms/{form}/links', [ExternalFormStudentLinkController::class, 'store']);
    Route::put('tournaments/{championship}/forms/{form}/rows', [ExternalFormEditorController::class, 'update']);
    Route::get('tournaments/{championship}/forms/{form}/imports/{run}', [ExternalFormEditorController::class, 'report'])->whereNumber('run');
    Route::put('tournaments/{championship}/forms/{form}', [TournamentFormController::class, 'updateForm']);
    Route::patch('tournaments/{championship}/forms/{form}/status', [TournamentFormController::class, 'updateStatus']);
    Route::post('tournaments/{championship}/forms/{form}/import', [TournamentFormController::class, 'importParticipants']);
    Route::delete('tournaments/{championship}/forms/{form}', [TournamentFormController::class, 'destroyForm']);
    Route::get('tournaments/{championship}/export', [TournamentController::class, 'exportParticipants']);
    Route::get('tournaments/{championship}', [TournamentController::class, 'show']);
    Route::delete('tournaments/{championship}', [TournamentController::class, 'destroy']);
    Route::middleware(ExaminationAccess::class)->group(function (): void {
        Route::get('examinations', [ExaminationController::class, 'index']);
        Route::post('examinations', [ExaminationController::class, 'store']);
        Route::get('examinations/{examination}', [ExaminationController::class, 'show']);
        Route::put('examinations/{examination}', [ExaminationController::class, 'update']);
        Route::delete('examinations/{examination}', [ExaminationController::class, 'destroy']);
        Route::get('examinations/{examination}/students', [ExaminationController::class, 'students']);
        Route::get('examinations/{examination}/students/export', [ExaminationController::class, 'exportStudents']);
        Route::get('examinations/{examination}/attach-options', [ExaminationController::class, 'attachOptions']);
        Route::post('examinations/{examination}/students', [ExaminationController::class, 'attachStudents']);
        Route::post('examinations/{examination}/attach-self', [ExaminationController::class, 'attachSelf']);
        Route::delete('examinations/{examination}/students/{student}', [ExaminationController::class, 'detachStudent']);
    });
    Route::get('team', [TeamController::class, 'index']);
    Route::get('team/export', [TeamController::class, 'export']);
    Route::get('team/invitation-code', [TeamController::class, 'invitationCode']);
    Route::delete('team/{section}/members', TeamMemberDeletionController::class);
    Route::post('team/pending/{invitation}/resend', [TeamController::class, 'resendPendingInvitation']);
    Route::delete('team/pending/{invitation}', [TeamController::class, 'destroyPendingInvitation']);
    Route::get('team/trainers/{trainer}', [TeamController::class, 'showTrainer']);
    Route::delete('team/trainers/{trainer}', [TeamController::class, 'destroyTrainer']);
    Route::get('team/trainers/{trainer}/students/export', [TeamController::class, 'exportTrainerStudents']);
    Route::delete('team/trainers/{trainer}/students/{student}', [TeamController::class, 'detachTrainerStudent']);
    Route::get('student/profile', [StudentController::class, 'selfProfile']);
    Route::get('student/education', [StudentEducationController::class, 'index']);
    Route::get('student/education/catalog/{section}', [StudentEducationController::class, 'categories']);
    Route::get('student/education/catalog/{section}/{category}', [StudentEducationController::class, 'videos']);
    Route::get('student/education/files/{section}/{video}/{field}', [StudentEducationController::class, 'file']);
    Route::delete('student/account', [StudentAccountController::class, 'destroy'])->middleware('throttle:5,1');
    Route::post('student/profile', [StudentController::class, 'updateSelf']);
    Route::get('team/students/{student}/history', [StudentController::class, 'historyPage']);
    Route::get('team/students/{student}', [StudentController::class, 'show']);
    Route::put('team/students/{student}/documents/{document}', [StudentController::class, 'updateDocument']);
    Route::post('team/invite-trainers', [TeamController::class, 'inviteTrainers']);
    Route::post('team/{section}', [TeamController::class, 'store']);
    Route::put('team/{section}/{user}', [TeamController::class, 'update']);
    Route::post('template-student-lists/reorder', [TemplateStudentListController::class, 'reorder']);
    Route::apiResource('template-student-lists', TemplateStudentListController::class)
        ->except(['show']);
});

require __DIR__.'/admin.php';
Route::view('/login', 'welcome')->name('login');

Route::get('/', function (Request $request) {
    if ($request->user()) {
        return redirect($request->user()->hasProjectRole('super_admin') ? '/panel/admin/feed' : '/panel');
    }

    return view('welcome');
});
Route::get('/api/public/app-links', fn () => response()->json([
    'ios' => config('mobile_app.ios_url'), 'android' => config('mobile_app.android_url'),
    'contact_email' => __('about.contacts.email'),
]));
Route::get('/api/public/agreements/{agreement}', PublicAgreementController::class)->whereNumber('agreement');
Route::view('/{any?}', 'welcome')->where('any', '.*');
