<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AttendanceController;
use App\Http\Controllers\Api\V1\BranchController;
use App\Http\Controllers\Api\V1\ClassBookingController;
use App\Http\Controllers\Api\V1\ClassSessionController;
use App\Http\Controllers\Api\V1\GymController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\MemberController;
use App\Http\Controllers\Api\V1\MemberImportController;
use App\Http\Controllers\Api\V1\MembershipController;
use App\Http\Controllers\Api\V1\MembershipPlanController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\PaymentGatewayController;
use App\Http\Controllers\Api\V1\PlatformSaasPlanController;
use App\Http\Controllers\Api\V1\ProgressMeasurementController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SaasSubscriptionController;
use App\Http\Controllers\Api\V1\StaffInvitationController;
use App\Http\Controllers\Api\V1\StaffProfileController;
use App\Http\Controllers\Api\V1\StripeWebhookController;
use App\Http\Controllers\Api\V1\StripeBillingWebhookController;
use App\Http\Controllers\Api\V1\TrainerAssignmentController;
use App\Http\Controllers\Api\V1\WorkoutPlanController;
use App\Http\Controllers\Api\V1\WorkoutSessionController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health/readiness', [HealthController::class, 'readiness'])
        ->middleware('throttle:health');

    // Stripe signs the raw request before a narrow provider-account RLS lookup
    // resolves the event into the normal tenant context.
    Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');
    Route::post('/webhooks/stripe/billing', [StripeBillingWebhookController::class, 'handle'])
        ->middleware('throttle:120,1');

    Route::prefix('auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
        Route::middleware(['auth:sanctum', 'database.identity'])->group(function (): void {
            Route::get('/me', [AuthController::class, 'me']);
            Route::post('/logout', [AuthController::class, 'logout']);
        });
    });

    Route::middleware(['auth:sanctum', 'database.identity'])->group(function (): void {
        Route::get('/gyms', [GymController::class, 'index']);
        Route::post('/gyms', [GymController::class, 'store'])->middleware('role:super_admin');

        Route::get('/platform/saas-plans', [PlatformSaasPlanController::class, 'index'])
            ->middleware('role:super_admin');
        Route::post('/platform/saas-plans', [PlatformSaasPlanController::class, 'store'])
            ->middleware('role:super_admin');
        Route::patch('/platform/saas-plans/{plan}', [PlatformSaasPlanController::class, 'update'])
            ->middleware('role:super_admin');
        Route::post('/platform/saas-plans/{plan}/prices', [PlatformSaasPlanController::class, 'storePrice'])
            ->middleware('role:super_admin');

        // Invitation acceptance uses the signed token before tenant membership exists.
        Route::post('/gyms/{gym}/staff-invitations/accept', [StaffInvitationController::class, 'accept']);

        Route::middleware('tenant')->group(function (): void {
            Route::get('/gyms/{gym}', [GymController::class, 'show']);
            Route::patch('/gyms/{gym}', [GymController::class, 'update'])
                ->middleware('role:super_admin,gym_owner,gym_manager');

            Route::prefix('/gyms/{gym}')->group(function (): void {
                Route::get('/branches', [BranchController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::post('/branches', [BranchController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/branches/{branch}', [BranchController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::patch('/branches/{branch}', [BranchController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');

                Route::get('/members', [MemberController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/members', [MemberController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/members/{member}', [MemberController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::patch('/members/{member}', [MemberController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/members/{member}/access-credential', [AttendanceController::class, 'issueCredential'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');

                Route::get('/member-imports', [MemberImportController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/member-imports', [MemberImportController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/member-imports/{import}', [MemberImportController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');

                Route::get('/staff', [StaffProfileController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/staff/{staff}', [StaffProfileController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::patch('/staff/{staff}', [StaffProfileController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/staff-invitations', [StaffInvitationController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::post('/staff-invitations', [StaffInvitationController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');

                Route::get('/membership-plans', [MembershipPlanController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::post('/membership-plans', [MembershipPlanController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/membership-plans/{plan}', [MembershipPlanController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::patch('/membership-plans/{plan}', [MembershipPlanController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');

                Route::get('/memberships', [MembershipController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/memberships', [MembershipController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/memberships/{membership}', [MembershipController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::patch('/memberships/{membership}', [MembershipController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');

                Route::get('/invoices', [InvoiceController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/invoices', [InvoiceController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');

                Route::get('/payments', [PaymentController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/payments/summary', [PaymentController::class, 'summary'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/payments', [PaymentController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::get('/payments/{payment}', [PaymentController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/payments/{payment}/refunds', [PaymentController::class, 'refund'])
                    ->middleware('role:super_admin,gym_owner');

                Route::get('/payment-gateways/stripe', [PaymentGatewayController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::post('/payment-gateways/stripe/onboard', [PaymentGatewayController::class, 'onboard'])
                    ->middleware('role:super_admin,gym_owner');
                Route::post('/payment-gateways/stripe/refresh', [PaymentGatewayController::class, 'refresh'])
                    ->middleware('role:super_admin,gym_owner');

                Route::get('/saas-plans', [SaasSubscriptionController::class, 'plans'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/saas-subscription', [SaasSubscriptionController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/saas-billing-invoices', [SaasSubscriptionController::class, 'invoices'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::post('/saas-subscription/checkout', [SaasSubscriptionController::class, 'checkout'])
                    ->middleware('role:super_admin,gym_owner');
                Route::post('/saas-subscription/portal', [SaasSubscriptionController::class, 'portal'])
                    ->middleware('role:super_admin,gym_owner');

                Route::get('/attendance', [AttendanceController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer');
                Route::post('/attendance/check-ins', [AttendanceController::class, 'checkIn'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');
                Route::post('/attendance/{attendance}/check-out', [AttendanceController::class, 'checkOut'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist');

                Route::get('/class-sessions', [ClassSessionController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::post('/class-sessions', [ClassSessionController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::patch('/class-sessions/{session}', [ClassSessionController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::get('/class-bookings', [ClassBookingController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer,member');
                Route::get('/class-sessions/{session}/bookings', [ClassBookingController::class, 'sessionBookings'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer');
                Route::post('/class-sessions/{session}/bookings', [ClassBookingController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,member');
                Route::post('/class-bookings/{booking}/cancel', [ClassBookingController::class, 'cancel'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,member');
                Route::post('/class-bookings/{booking}/attend', [ClassBookingController::class, 'attend'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,receptionist,trainer');

                Route::get('/trainer-assignments', [TrainerAssignmentController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::post('/trainer-assignments', [TrainerAssignmentController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');
                Route::patch('/trainer-assignments/{assignment}/end', [TrainerAssignmentController::class, 'end'])
                    ->middleware('role:super_admin,gym_owner,gym_manager');

                Route::get('/workout-plans', [WorkoutPlanController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::post('/workout-plans', [WorkoutPlanController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer');
                Route::get('/workout-plans/{plan}', [WorkoutPlanController::class, 'show'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::patch('/workout-plans/{plan}', [WorkoutPlanController::class, 'update'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer');

                Route::get('/workout-sessions', [WorkoutSessionController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::post('/workout-sessions', [WorkoutSessionController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::get('/progress-measurements', [ProgressMeasurementController::class, 'index'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');
                Route::post('/progress-measurements', [ProgressMeasurementController::class, 'store'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,trainer,member');

                Route::get('/notification-preferences', [NotificationController::class, 'preference'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,member');
                Route::patch('/notification-preferences', [NotificationController::class, 'updatePreference'])
                    ->middleware('role:member');
                Route::get('/notification-deliveries', [NotificationController::class, 'deliveries'])
                    ->middleware('role:super_admin,gym_owner,gym_manager,member');

                Route::get('/reports/overview', [ReportController::class, 'overview'])
                    ->middleware(['role:super_admin,gym_owner,gym_manager', 'throttle:reports']);
            });
        });
    });
});
