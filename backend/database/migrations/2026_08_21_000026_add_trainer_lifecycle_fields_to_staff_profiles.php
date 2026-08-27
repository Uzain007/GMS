<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            // Contact details stay tenant-owned so editing one gym's trainer
            // never mutates the same person's platform identity in another gym.
            $table->string('display_name', 160)->nullable()->after('home_branch_id');
            $table->string('contact_email', 254)->nullable()->after('display_name');
            $table->string('phone', 40)->nullable()->after('contact_email');

            // Private object metadata is tenant-scoped; no public storage URL is
            // persisted or returned to an unauthorised browser.
            $table->string('profile_image_disk', 80)->nullable()->after('permissions');
            $table->string('profile_image_path', 1024)->nullable()->after('profile_image_disk');
            $table->string('profile_image_mime', 100)->nullable()->after('profile_image_path');
            $table->unsignedBigInteger('profile_image_size')->nullable()->after('profile_image_mime');
        });
    }

    public function down(): void
    {
        Schema::table('staff_profiles', function (Blueprint $table): void {
            $table->dropColumn([
                'display_name',
                'contact_email',
                'phone',
                'profile_image_disk',
                'profile_image_path',
                'profile_image_mime',
                'profile_image_size',
            ]);
        });
    }
};
