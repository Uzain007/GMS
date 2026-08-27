<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $postgres = DB::connection()->getDriverName() === 'pgsql';

        try {
            foreach (DB::table('gyms')->orderBy('id')->pluck('id') as $gymId) {
                if ($postgres) {
                    // Earlier installations may already enforce FORCE RLS, so
                    // repair legacy rows under one explicit tenant at a time.
                    DB::statement("select set_config('ironcore.current_gym_id', ?, false)", [$gymId]);
                }

                DB::table('payments')
                    ->where('gym_id', $gymId)
                    ->where('status', 'succeeded')
                    ->update(['status' => 'paid']);

                DB::table('payments')
                    ->where('gym_id', $gymId)
                    ->where('status', 'failed')
                    ->update(['status' => 'rejected']);
            }
        } finally {
            if ($postgres) {
                DB::statement("select set_config('ironcore.current_gym_id', '', false)");
            }
        }
    }

    public function down(): void
    {
        // Business status names are intentionally not reverted: converting
        // genuine cash or approved bank payments back to provider vocabulary
        // would corrupt ledger meaning.
    }
};
