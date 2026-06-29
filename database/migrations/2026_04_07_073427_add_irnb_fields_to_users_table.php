<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('membership_id', 10)->nullable()->unique()->after('id');
            $table->string('firstname')->nullable()->after('membership_id');
            $table->string('lastname')->nullable()->after('firstname');
            $table->json('phones')->nullable()->after('lastname');
            $table->date('birthdate')->nullable()->after('phones');
            $table->string('gender', 20)->default('Male')->after('birthdate');
            $table->string('picture_url')->nullable()->after('gender');
            $table->string('picture_filename')->nullable()->after('picture_url');
            $table->boolean('is_user')->default(true)->after('picture_filename');
            $table->boolean('is_active')->default(true)->after('is_user');
            $table->boolean('approved')->default(false)->after('is_active');
            $table->string('state')->default('Unknown')->after('approved');
            $table->string('city')->default('Unknown')->after('state');
            $table->boolean('is_student')->default(true)->after('city');
            $table->foreignId('member_job_id')->nullable()->after('is_student')->constrained('member_jobs')->nullOnDelete();
            $table->foreignId('category_id')->nullable()->after('member_job_id')->constrained('categories')->nullOnDelete();
            $table->string('team')->nullable()->after('category_id');
            $table->string('position')->nullable()->after('team');
            $table->unsignedTinyInteger('skill_level')->default(5)->after('position');
            $table->json('health')->nullable()->after('skill_level');
            $table->json('privileges')->nullable()->after('health');
            $table->string('preferred_lng', 5)->default('ar')->after('privileges');
            $table->timestamp('logged_in_at')->nullable()->after('preferred_lng');

            $table->index(['is_user', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['member_job_id']);
            $table->dropForeign(['category_id']);
            $table->dropIndex(['is_user', 'is_active']);

            $table->dropColumn([
                'membership_id',
                'firstname',
                'lastname',
                'phones',
                'birthdate',
                'gender',
                'picture_url',
                'picture_filename',
                'is_user',
                'is_active',
                'approved',
                'state',
                'city',
                'is_student',
                'member_job_id',
                'category_id',
                'team',
                'position',
                'skill_level',
                'health',
                'privileges',
                'preferred_lng',
                'logged_in_at',
            ]);
        });
    }
};
