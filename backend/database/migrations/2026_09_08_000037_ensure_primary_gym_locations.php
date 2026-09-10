<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $connection = DB::connection();
        $driver = $connection->getDriverName();

        DB::table('gyms')->orderBy('id')->each(function (object $gym) use ($driver): void {
            if ($driver === 'pgsql') {
                // Existing production tenants are entered one at a time so
                // FORCE RLS remains active during this operational backfill.
                DB::statement("select set_config('ironcore.current_gym_id', ?, false)", [$gym->id]);
            }

            if (DB::table('gym_branches')->where('gym_id', $gym->id)->exists()) {
                return;
            }

            DB::table('gym_branches')->insert([
                'id' => (string) Str::uuid(),
                'gym_id' => $gym->id,
                'name' => 'Primary location',
                'code' => 'PRIMARY',
                'timezone' => $gym->timezone,
                'status' => 'active',
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        if ($driver === 'pgsql') {
            DB::statement("select set_config('ironcore.current_gym_id', '', false)");
        }
    }

    public function down(): void
    {
        // Operational primary locations may acquire real business history, so
        // rollback never guesses that an apparently default row is disposable.
    }
};
