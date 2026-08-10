<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Every index begins with gym_id so bounded report scans remain inside
        // one tenant even at the million-member scale target.
        Schema::table('members', fn (Blueprint $table) => $table->index(
            ['gym_id', 'created_at'],
            'members_report_created_idx',
        ));
        Schema::table('memberships', fn (Blueprint $table) => $table->index(
            ['gym_id', 'cancelled_at'],
            'memberships_report_cancel_idx',
        ));
        Schema::table('payments', fn (Blueprint $table) => $table->index(
            ['gym_id', 'currency', 'status', 'paid_at'],
            'payments_report_currency_idx',
        ));
        Schema::table('payment_refunds', fn (Blueprint $table) => $table->index(
            ['gym_id', 'currency', 'status', 'refunded_at'],
            'refunds_report_currency_idx',
        ));
        Schema::table('invoices', fn (Blueprint $table) => $table->index(
            ['gym_id', 'currency', 'status'],
            'invoices_report_balance_idx',
        ));
        Schema::table('attendance_records', fn (Blueprint $table) => $table->index(
            ['gym_id', 'checked_in_at'],
            'attendance_report_checkin_idx',
        ));
    }

    public function down(): void
    {
        Schema::table('attendance_records', fn (Blueprint $table) => $table->dropIndex('attendance_report_checkin_idx'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropIndex('invoices_report_balance_idx'));
        Schema::table('payment_refunds', fn (Blueprint $table) => $table->dropIndex('refunds_report_currency_idx'));
        Schema::table('payments', fn (Blueprint $table) => $table->dropIndex('payments_report_currency_idx'));
        Schema::table('memberships', fn (Blueprint $table) => $table->dropIndex('memberships_report_cancel_idx'));
        Schema::table('members', fn (Blueprint $table) => $table->dropIndex('members_report_created_idx'));
    }
};
