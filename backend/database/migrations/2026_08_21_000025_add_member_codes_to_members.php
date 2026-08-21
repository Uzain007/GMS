<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            // This six-digit reception lookup is deliberately separate from the
            // UUID primary key, business member number and secure QR credential.
            $table->char('member_code', 6)->nullable()->after('member_number');
        });

        $postgres = DB::connection()->getDriverName() === 'pgsql';

        foreach (DB::table('gyms')->orderBy('id')->pluck('id') as $gymId) {
            if ($postgres) {
                // FORCE RLS remains active during migrations, so backfill one
                // explicit gym at a time instead of disabling tenant isolation.
                DB::statement("select set_config('ironcore.current_gym_id', ?, false)", [$gymId]);
            }

            $nextCode = 0;
            DB::table('members')->where('gym_id', $gymId)->orderBy('id')
                ->chunkById(1000, function ($members) use (&$nextCode): void {
                    foreach ($members as $member) {
                        if ($nextCode > 999999) {
                            throw new \RuntimeException('A gym cannot contain more than one million six-digit member codes.');
                        }

                        DB::table('members')->where('id', $member->id)->update([
                            'member_code' => str_pad((string) $nextCode++, 6, '0', STR_PAD_LEFT),
                        ]);
                    }
                }, 'id');
        }

        if ($postgres) {
            DB::statement("select set_config('ironcore.current_gym_id', '', false)");
            DB::statement('ALTER TABLE members ALTER COLUMN member_code SET NOT NULL');
        } else {
            Schema::table('members', function (Blueprint $table): void {
                $table->char('member_code', 6)->nullable(false)->change();
            });
        }

        Schema::table('members', function (Blueprint $table): void {
            // Tenant-leading uniqueness lets different gyms safely reuse a code
            // while keeping reception lookups index-backed inside one gym.
            $table->unique(['gym_id', 'member_code']);
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropUnique(['gym_id', 'member_code']);
            $table->dropColumn('member_code');
        });
    }
};
