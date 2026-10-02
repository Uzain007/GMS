<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Fail before changing the index if legacy rows would violate the
        // tenant-leading one-lifecycle invariant; migrations never repair data.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1
                    FROM class_bookings
                    WHERE status IN ('booked', 'waitlisted', 'attended', 'no_show')
                    GROUP BY gym_id, class_session_id, member_id
                    HAVING COUNT(*) > 1
                ) THEN
                    RAISE EXCEPTION 'Conflicting class booking lifecycles must be reviewed before applying this migration.';
                END IF;
            END
            $$
        SQL);

        DB::unprepared('DROP INDEX IF EXISTS class_bookings_one_active_unique');
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX class_bookings_one_active_unique
            ON class_bookings (gym_id, class_session_id, member_id)
            WHERE status IN ('booked', 'waitlisted', 'attended', 'no_show')
        SQL);
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared('DROP INDEX IF EXISTS class_bookings_one_active_unique');
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX class_bookings_one_active_unique
            ON class_bookings (gym_id, class_session_id, member_id)
            WHERE status IN ('booked', 'waitlisted', 'attended')
        SQL);
    }
};
