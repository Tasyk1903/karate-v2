<?php

use App\Http\Controllers\Mobile\MobileAboutController;
use App\Http\Controllers\Mobile\MobileAccountController;
use App\Http\Controllers\Mobile\MobileAgreementController;
use App\Http\Controllers\Mobile\MobileAuthController;
use App\Http\Controllers\Mobile\MobileEducationController;
use App\Http\Controllers\Mobile\MobileExaminationController;
use App\Http\Controllers\Mobile\MobileFeedCommentController;
use App\Http\Controllers\Mobile\MobileFeedController;
use App\Http\Controllers\Mobile\MobileFeedSettingsController;
use App\Http\Controllers\Mobile\MobileJoinCoachController;
use App\Http\Controllers\Mobile\MobileJudgeController;
use App\Http\Controllers\Mobile\MobileKataPaymentController;
use App\Http\Controllers\Mobile\MobileKataVideoController;
use App\Http\Controllers\Mobile\MobileMasterController;
use App\Http\Controllers\Mobile\MobileNotificationController;
use App\Http\Controllers\Mobile\MobileQuickFightController;
use App\Http\Controllers\Mobile\MobileRatingController;
use App\Http\Controllers\Mobile\MobileRegistrationController;
use App\Http\Controllers\Mobile\MobileStaffProfileController;
use App\Http\Controllers\Mobile\MobileStudentCategoriesController;
use App\Http\Controllers\Mobile\MobileStudentController;
use App\Http\Controllers\Mobile\MobileStudentEducationController;
use App\Http\Controllers\Mobile\MobileStudentInvitationController;
use App\Http\Controllers\Mobile\MobileStudentRestoreController;
use App\Http\Controllers\Mobile\MobileTournamentController;
use App\Http\Controllers\Mobile\MobileTournamentItemController;
use App\Http\Controllers\Mobile\MobileTournamentListController;
use App\Http\Controllers\Mobile\MobileTrainerProfileController;
use App\Http\Controllers\Mobile\MobileTrainerSettingsController;
use App\Http\Controllers\Panel\PanelTaskController;
use App\Http\Controllers\PaymentCallbackController;
use App\Http\Controllers\ProtectedDocumentController;
use App\Http\Middleware\MobileAgreementConsent;
use App\Http\Middleware\MobileAppMember;
use App\Http\Middleware\MobileRoles;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => response()->json(['status' => 'ok']));
Route::post('/payment-callback', [PaymentCallbackController::class, 'callback'])->middleware('throttle:120,1');

Route::prefix('mobile')->group(function (): void {
    Route::post('auth/login', [MobileAuthController::class, 'login']);
    Route::post('auth/registration/{kind}', [MobileRegistrationController::class, 'store'])->whereIn('kind', ['trainer', 'student'])->middleware('throttle:5,1');
    Route::post('auth/registration/{kind}/confirm', [MobileRegistrationController::class, 'confirm'])->whereIn('kind', ['trainer', 'student'])->middleware('throttle:10,1');
    Route::post('auth/restore', [MobileStudentRestoreController::class, 'request'])->middleware('throttle:5,1');
    Route::post('auth/restore/confirm', [MobileStudentRestoreController::class, 'confirm'])->middleware('throttle:10,1');
    Route::post('auth/logout', [MobileAuthController::class, 'logout'])->middleware('mobile.auth');

    Route::middleware(['mobile.auth', MobileAppMember::class])->group(function (): void {
        Route::get('auth/user', [MobileAuthController::class, 'user']);
        Route::get('agreements', [MobileAgreementController::class, 'index']);
        Route::get('agreements/{agreement}', [MobileAgreementController::class, 'show'])->whereNumber('agreement');
        Route::post('agreements/{agreement}/accept', [MobileAgreementController::class, 'accept'])->whereNumber('agreement');
    });

    Route::middleware(['mobile.auth', MobileAppMember::class, MobileAgreementConsent::class, MobileRoles::class.':Coach,Student'])->group(function (): void {
        Route::get('examinations', [MobileExaminationController::class, 'index']);
        Route::get('examinations/{examination}', [MobileExaminationController::class, 'show']);
        Route::get('examinations/{examination}/students', [MobileExaminationController::class, 'students']);
        Route::post('examinations/{examination}/self', [MobileExaminationController::class, 'attachSelf']);
        Route::delete('examinations/{examination}/students/{student}', [MobileExaminationController::class, 'detachStudent']);
        Route::get('education', [MobileEducationController::class, 'index']);
        Route::get('education/works', [MobileEducationController::class, 'works']);
        Route::get('education/works/{work}', [MobileEducationController::class, 'work'])->whereNumber('work');
        Route::get('files/education/works/{work}', [MobileEducationController::class, 'workFile'])->whereNumber('work');
        Route::get('education/work-options', [MobileStudentEducationController::class, 'options']);
        Route::post('education/works', [MobileStudentEducationController::class, 'store'])->middleware('throttle:10,1');
        Route::post('education/works/{work}', [MobileStudentEducationController::class, 'update'])->whereNumber('work');
        Route::post('education/works/{work}/delete', [MobileStudentEducationController::class, 'destroy'])->whereNumber('work');
        Route::post('education/works/{work}/payment', [MobileStudentEducationController::class, 'pay'])->whereNumber('work')->middleware('throttle:10,1');
        Route::get('education/works/{work}/payment', [MobileStudentEducationController::class, 'payment'])->whereNumber('work')->middleware('throttle:30,1');
        Route::get('education/catalog/{section}', [MobileEducationController::class, 'categories']);
        Route::get('education/catalog/{section}/{category}', [MobileEducationController::class, 'videos'])->whereNumber('category');
        Route::get('files/education/catalog/{section}/{video}/{field}', [MobileEducationController::class, 'catalogFile'])->whereNumber('video');
        Route::get('students/{student}/history', [MobileStudentController::class, 'history']);
        Route::get('students/{student}/public', [MobileStudentController::class, 'publicSummary']);
        Route::get('students/{student}', [MobileStudentController::class, 'show']);
        Route::post('students/{student}', [MobileStudentController::class, 'update']);
        Route::put('students/{student}', [MobileStudentController::class, 'update']);
        Route::get('notifications/unread', [MobileNotificationController::class, 'unread']);
        Route::get('notifications', [MobileNotificationController::class, 'index']);
        Route::post('notifications/read-all', [MobileNotificationController::class, 'markAllAsRead']);
        Route::post('notifications/{notification}/read', [MobileNotificationController::class, 'markAsRead']);
        Route::get('feed/settings', [MobileFeedSettingsController::class, 'show']);
        Route::get('feed/settings/options', [MobileFeedSettingsController::class, 'options']);
        Route::put('feed/settings', [MobileFeedSettingsController::class, 'update']);
        Route::get('feed/{post}', [MobileFeedController::class, 'show']);
        Route::get('feed/{post}/comments', [MobileFeedCommentController::class, 'index']);
        Route::get('feed', [MobileFeedController::class, 'index']);
        Route::post('feed', [MobileFeedController::class, 'store']);
        Route::post('feed/{post}', [MobileFeedController::class, 'update']);
        Route::put('feed/{post}', [MobileFeedController::class, 'update']);
        Route::delete('feed/{post}', [MobileFeedController::class, 'destroy']);
        Route::post('feed/{post}/reaction', [MobileFeedController::class, 'toggleReaction']);
        Route::post('feed/{post}/comments', [MobileFeedCommentController::class, 'store']);
        Route::put('feed/comments/{comment}', [MobileFeedCommentController::class, 'update']);
        Route::delete('feed/comments/{comment}', [MobileFeedCommentController::class, 'destroy']);
        Route::post('feed/comments/{comment}/reaction', [MobileFeedCommentController::class, 'reaction']);
    });

    Route::middleware(['mobile.auth', MobileAppMember::class])->group(function (): void {
        Route::post('account/delete', [MobileAccountController::class, 'destroy'])->middleware('throttle:5,1,mobile-account-delete');
    });

    Route::middleware(['mobile.auth', MobileAppMember::class, MobileAgreementConsent::class])->group(function (): void {
        Route::post('account/coach/preview', [MobileJoinCoachController::class, 'preview'])->middleware([MobileRoles::class.':Student', 'throttle:10,1,student-coach-preview']);
        Route::post('account/coach/join', [MobileJoinCoachController::class, 'store'])->middleware([MobileRoles::class.':Student', 'throttle:10,1,student-coach-join']);
        Route::get('files/users/{owner}/{document}', ProtectedDocumentController::class)->middleware(MobileRoles::class.':Coach,Student,Master');
        Route::get('about', MobileAboutController::class)->middleware(MobileRoles::class.':Coach,Student,Master');
        Route::get('rating', MobileRatingController::class)->middleware(MobileRoles::class.':Coach,Student,Master');
        Route::middleware(MobileRoles::class.':Judge,Master')->group(function (): void {
            Route::get('staff/profile', [MobileStaffProfileController::class, 'show']);
            Route::post('staff/profile', [MobileStaffProfileController::class, 'update']);
        });
        Route::middleware(MobileRoles::class.':Judge')->group(function (): void {
            Route::get('judge/tournaments', [MobileJudgeController::class, 'tournaments']);
            Route::get('judge/tables', [MobileJudgeController::class, 'index']);
            Route::get('judge/tables/{list}', [MobileJudgeController::class, 'show'])->whereNumber('list');
            Route::post('judge/tables/{list}/scores/{pool}', [MobileJudgeController::class, 'score'])->whereNumber(['list', 'pool']);
            Route::get('judge/tables/{list}/scores/{pool}', [MobileJudgeController::class, 'currentScore'])->whereNumber(['list', 'pool']);
            Route::get('files/judge/{pool}/{student}', [MobileJudgeController::class, 'video'])->whereNumber(['pool', 'student']);
        });
        Route::middleware(MobileRoles::class.':Master')->group(function (): void {
            Route::get('master/works', [MobileMasterController::class, 'index']);
            Route::get('master/works/{work}', [MobileMasterController::class, 'show'])->whereNumber('work');
            Route::post('master/works/{work}', [MobileMasterController::class, 'update'])->whereNumber('work');
            Route::get('files/master/{work}', [MobileMasterController::class, 'video'])->whereNumber('work');
        });
    });

    Route::middleware(['mobile.auth', 'mobile.coach', MobileAgreementConsent::class])->group(function (): void {

        Route::get('student-invitations', [MobileStudentInvitationController::class, 'index']);
        Route::post('student-invitations', [MobileStudentInvitationController::class, 'store'])->middleware('throttle:5,1,student-invitations');
        Route::delete('student-invitations/{invitation}', [MobileStudentInvitationController::class, 'destroy']);
        Route::get('students/{student}/categories', [MobileStudentCategoriesController::class, 'index']);
        Route::post('students/{student}/detach', [MobileStudentController::class, 'detach']);
        Route::get('students', [MobileStudentController::class, 'index']);
        Route::get('examinations/{examination}/attach-options', [MobileExaminationController::class, 'attachOptions']);
        Route::post('examinations/{examination}/students', [MobileExaminationController::class, 'attachStudents']);
        Route::get('examinations/{examination}/students/export', [MobileExaminationController::class, 'exportStudents']);
        Route::get('trainer/profile', MobileTrainerProfileController::class);
        Route::post('trainer/profile', [MobileTrainerProfileController::class, 'update']);
        Route::get('trainer/settings', [MobileTrainerSettingsController::class, 'show']);
        Route::put('trainer/settings', [MobileTrainerSettingsController::class, 'update']);
    });

    Route::middleware(['mobile.auth', MobileAppMember::class, MobileAgreementConsent::class, MobileRoles::class.':Coach,Student'])->group(function (): void {
        Route::get('championships', [MobileTournamentController::class, 'index']);
        Route::post('championships/{championship}/tournaments/{tournament}/exports', [MobileTournamentListController::class, 'queueExport'])->middleware('throttle:6,1');
        Route::get('tasks/{task}', [PanelTaskController::class, 'show']);
        Route::get('tasks/{task}/file', [PanelTaskController::class, 'file']);
        Route::get('championships/{championship}', [MobileTournamentController::class, 'show']);
        Route::get('online-kata/applications', [MobileKataPaymentController::class, 'index']);
        Route::get('online-kata/applications/{application}', [MobileKataPaymentController::class, 'show'])->middleware('throttle:30,1');
        Route::post('online-kata/applications/{application}/retry', [MobileKataPaymentController::class, 'retry']);
        Route::get('files/kata/{pool}/{student}', [MobileKataVideoController::class, 'show']);
        Route::get('championships/{championship}/tournaments/{tournament}', [MobileTournamentItemController::class, 'show']);
        Route::get('championships/{championship}/tournaments/{tournament}/documents/{field}', [MobileTournamentItemController::class, 'document']);
        Route::get('championships/{championship}/tournaments/{tournament}/students', [MobileTournamentItemController::class, 'students']);
        Route::get('championships/{championship}/tournaments/{tournament}/students/attach-options', [MobileTournamentItemController::class, 'attachStudentOptions'])->middleware('mobile.coach');
        Route::post('championships/{championship}/tournaments/{tournament}/students', [MobileTournamentItemController::class, 'attachStudents'])->middleware('mobile.coach');
        Route::post('championships/{championship}/tournaments/{tournament}/self', [MobileTournamentItemController::class, 'attachSelf']);
        Route::delete('championships/{championship}/tournaments/{tournament}/self/{membership}', [MobileTournamentItemController::class, 'detachSelf'])->whereNumber('membership');
        Route::post('championships/{championship}/tournaments/{tournament}/students/online-kata', [MobileTournamentItemController::class, 'attachOnlineKataStudent']);
        Route::delete('championships/{championship}/tournaments/{tournament}/students/{studentTournament}', [MobileTournamentItemController::class, 'detachStudent'])->middleware('mobile.coach');
        Route::get('championships/{championship}/tournaments/{tournament}/coaches', [MobileTournamentItemController::class, 'coaches']);
        Route::get('quick-fights', [MobileQuickFightController::class, 'index'])->middleware('mobile.coach');
        Route::get('championships/{championship}/tournaments/{tournament}/lists/export/{format}', [MobileTournamentListController::class, 'export'])->middleware('throttle:6,1');
        Route::get('championships/{championship}/tournaments/{tournament}/lists/{listTournament}/members', [MobileTournamentListController::class, 'members']);
        Route::get('championships/{championship}/tournaments/{tournament}/lists', [MobileTournamentItemController::class, 'lists']);
        Route::get('championships/{championship}/tournaments/{tournament}/lists/{listTournament}/bracket', [MobileTournamentItemController::class, 'bracket']);
        Route::post('championships/{championship}/tournaments/{tournament}/kata-pools/{kataPool}/final-video', [MobileTournamentItemController::class, 'updateKataFinalVideo']);

    });
});
