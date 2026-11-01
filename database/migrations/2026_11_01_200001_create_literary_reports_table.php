<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULE = 'literary_reports';

    public function up(): void
    {
        Schema::create('literary_reports', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->date('period_from');
            $table->date('period_to');
            $table->json('sections');
            $table->longText('president_word')->nullable();
            $table->longText('introduction')->nullable();
            $table->longText('conclusion')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // New rights start empty for every custom role; system roles get them.
        $all = ['view', 'add', 'edit', 'delete'];
        DB::table('roles')->where('is_system', true)->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) use ($all) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                $permissions[self::MODULE] = $all;
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });
    }

    public function down(): void
    {
        DB::table('roles')->orderBy('id')->get(['id', 'permissions'])
            ->each(function ($role) {
                $permissions = json_decode((string) $role->permissions, true) ?: [];
                unset($permissions[self::MODULE]);
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            });

        Schema::dropIfExists('literary_reports');
    }
};
