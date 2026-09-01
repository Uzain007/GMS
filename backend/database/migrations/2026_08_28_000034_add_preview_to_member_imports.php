<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('member_imports', function (Blueprint $table): void {
            // Preview evidence is bounded metadata only; uploaded rows remain in
            // tenant-prefixed private storage and are never copied into JSON.
            $table->jsonb('preview_summary')->nullable()->after('errors');
            $table->timestampTz('previewed_at')->nullable()->after('preview_summary');
            $table->timestampTz('confirmed_at')->nullable()->after('previewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('member_imports', function (Blueprint $table): void {
            $table->dropColumn(['preview_summary', 'previewed_at', 'confirmed_at']);
        });
    }
};
