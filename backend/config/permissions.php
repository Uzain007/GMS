<?php

use App\Enums\UserRole;

return [
    // These maximum grants mirror the MAD. Policies may narrow them for a
    // particular record, but tenant roles can never exceed this boundary.
    UserRole::SuperAdmin->value => [
        'platform.gyms.manage', 'platform.billing.manage', 'platform.audit.read',
        'tenant.select', 'tenant.read', 'tenant.manage',
    ],
    UserRole::GymOwner->value => [
        'gym.read', 'gym.update', 'branches.manage', 'members.manage', 'members.import',
        'memberships.manage', 'plans.manage', 'staff.manage', 'payments.manage',
        'saas_billing.read', 'saas_billing.manage', 'attendance.manage',
        'classes.manage', 'bookings.manage', 'training.manage', 'progress.manage',
        'notifications.read', 'reports.read', 'audit.read',
    ],
    UserRole::GymManager->value => [
        'gym.read', 'gym.update', 'branches.manage', 'members.manage', 'members.import',
        'memberships.manage', 'plans.manage', 'staff.manage', 'payments.record',
        'saas_billing.read', 'attendance.manage', 'classes.manage',
        'bookings.manage', 'training.manage', 'progress.manage',
        'notifications.read', 'reports.read', 'audit.read',
    ],
    UserRole::Receptionist->value => [
        'gym.read', 'branches.read', 'members.read', 'members.create',
        'members.update', 'members.import', 'memberships.read', 'memberships.create',
        'attendance.manage', 'classes.read', 'bookings.manage', 'payments.record',
    ],
    UserRole::Trainer->value => [
        'gym.read', 'branches.read', 'members.assigned.read',
        'training.manage', 'attendance.read', 'classes.assigned.read',
        'classes.assigned.attendance', 'progress.assigned.manage',
    ],
    UserRole::Member->value => [
        'self.read', 'self.update_limited', 'membership.self.read',
        'payment.self.read', 'attendance.self.read', 'classes.read', 'booking.self.manage',
        'training.self.read', 'training.self.log', 'progress.self.manage', 'notifications.self.manage',
    ],
];
