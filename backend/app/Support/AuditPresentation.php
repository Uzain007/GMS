<?php

namespace App\Support;

final class AuditPresentation
{
    /** @return array{title:string,description:string} */
    public static function for(string $event, ?string $actor, ?string $gym, ?array $before = null, ?array $after = null): array
    {
        $labels = [
            'gym.created' => 'Gym created', 'gym.updated' => 'Gym updated',
            'gym.suspended' => 'Gym suspended', 'gym.reactivated' => 'Gym reactivated', 'gym.archived' => 'Gym archived',
            'platform.saas_plan.created' => 'SaaS plan created', 'platform.saas_plan.updated' => 'SaaS plan updated',
            'platform.saas_plan.archived' => 'SaaS plan archived', 'platform.saas_plan.permanently_deleted' => 'SaaS plan permanently deleted',
            'saas.plan_change.requested' => 'Plan change requested', 'saas.plan_change.approved' => 'Plan change approved',
            'saas.plan_change.rejected' => 'Plan change rejected', 'saas.plan_change.cancelled' => 'Plan change cancelled',
            'saas.invoice.generated' => 'SaaS invoice created', 'saas.invoice.published' => 'SaaS invoice marked due',
            'saas.invoice.paid' => 'SaaS invoice paid', 'saas.payment_reminder.sent' => 'SaaS payment reminder sent',
            'staff.employee_created' => 'Employee created', 'staff.updated' => 'Employee updated',
            'staff.employee_updated' => 'Employee updated',
            'staff.employee_suspended' => 'Employee suspended', 'staff.employee_deactivated' => 'Employee deactivated',
            'staff.employee_deleted' => 'Employee removed', 'staff.employee_reactivated' => 'Employee reactivated',
            'member.created' => 'Member created', 'member.updated' => 'Member updated',
            'member.suspended' => 'Member suspended', 'member.deactivated' => 'Member deactivated',
            'member.deleted' => 'Member removed', 'member.reactivated' => 'Member reactivated',
            'membership.created' => 'Membership created', 'membership.renewed' => 'Membership renewed',
            'membership.auto_renewed' => 'Membership automatically renewed',
            'attendance.checked_in' => 'Member checked in', 'attendance.checked_out' => 'Member checked out',
            'attendance.member_auto_checked_out' => 'Member automatically checked out',
            'attendance.employee_checked_in' => 'Employee checked in', 'attendance.employee_checked_out' => 'Employee checked out',
            'attendance.employee_auto_checked_out' => 'Employee automatically checked out',
            'member.account_invited' => 'Member invitation sent',
        ];
        $title = match ($event) {
            'gym.updated' => match (true) {
                ($before['status'] ?? null) !== ($after['status'] ?? null) && ($after['status'] ?? null) === 'suspended' => 'Gym suspended',
                ($before['status'] ?? null) !== ($after['status'] ?? null) && ($after['status'] ?? null) === 'active' => 'Gym reactivated',
                ($before['status'] ?? null) !== ($after['status'] ?? null) && ($after['status'] ?? null) === 'cancelled' => 'Gym archived',
                default => 'Gym updated',
            },
            'saas.invoice.status_changed' => match ($after['status'] ?? null) {
                'due' => 'SaaS invoice marked due',
                'paid' => 'SaaS invoice paid',
                default => 'SaaS invoice status updated',
            },
            'saas.subscription_payment.approved' => 'SaaS invoice paid',
            default => $labels[$event] ?? str($event)->replace(['.', '_'], ' ')->headline()->toString(),
        };
        $who = $actor ?: 'IronCore';
        $where = $gym ?: 'the platform';
        return ['title' => $title, 'description' => "{$who} — {$title} for {$where}."];
    }
}
