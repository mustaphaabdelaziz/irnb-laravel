<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sign-in moves from the email address to a free-form username. Existing
 * accounts get their lowercased email as username, so everyone keeps signing
 * in with what they typed before. Email becomes an optional contact field.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });

        $taken = [];
        DB::table('users')->orderBy('id')->select(['id', 'email'])->each(function ($row) use (&$taken) {
            $base = mb_strtolower(trim((string) $row->email)) ?: 'user'.$row->id;
            // Emails differing only by case collapse to one username; suffix the later ones.
            $username = isset($taken[$base]) ? $base.'-'.$row->id : $base;
            $taken[$username] = true;

            DB::table('users')->where('id', $row->id)->update(['username' => $username]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Accounts created without an email need a placeholder to restore NOT NULL.
        DB::table('users')->whereNull('email')->orderBy('id')->each(function ($row) {
            DB::table('users')->where('id', $row->id)->update(['email' => $row->username.'@local.invalid']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
