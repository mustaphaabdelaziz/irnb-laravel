<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * branch_player becomes the registration. The table is rebuilt (create, copy,
 * drop, rename) because it needs a surrogate key, and that behaves the same on
 * SQLite and MySQL. Nothing references it, so dropping it cascades nowhere.
 *
 * join_date stays NULL on every row: no trustworthy date exists. The year goes
 * to legacy_join_year, on both registrations of a two-branch player.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL is
 * not transactional) finishes on the next run, and a second run changes nothing.
 * Constraint names are explicit: MySQL names are global while the old table exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('branch_player') && Schema::hasTable('branch_player_new')) {
            Schema::rename('branch_player_new', 'branch_player');
        }

        if (! Schema::hasColumn('branch_player', 'id')) {
            Schema::dropIfExists('branch_player_new');
            $this->create('branch_player_new');
            DB::statement('INSERT INTO branch_player_new (branch_id, player_id) SELECT branch_id, player_id FROM branch_player ORDER BY player_id, branch_id');
            Schema::drop('branch_player');
            Schema::rename('branch_player_new', 'branch_player');
        }

        $this->backfill();
    }

    private function create(string $name): void
    {
        Schema::create($name, function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('player_id');
            $table->string('membership_id', 24)->nullable();
            $table->string('legacy_membership_id', 10)->nullable();
            $table->date('join_date')->nullable();
            $table->unsignedSmallInteger('legacy_join_year')->nullable();
            $table->unsignedBigInteger('category_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('licence_number', 50)->nullable();
            $table->unsignedBigInteger('status_id')->nullable();
            $table->date('left_at')->nullable();
            $table->unsignedBigInteger('sporting_status_id')->nullable();
            $table->timestamps();

            $table->foreign('branch_id', 'branch_reg_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('player_id', 'branch_reg_player_fk')->references('id')->on('players')->cascadeOnDelete();
            $table->foreign('category_id', 'branch_reg_category_fk')->references('id')->on('categories')->restrictOnDelete();
            $table->foreign('position_id', 'branch_reg_position_fk')->references('id')->on('positions')->nullOnDelete();
            $table->foreign('status_id', 'branch_reg_status_fk')->references('id')->on('player_statuses')->nullOnDelete();
            $table->foreign('sporting_status_id', 'branch_reg_sporting_fk')->references('id')->on('sporting_statuses')->nullOnDelete();

            $table->unique(['branch_id', 'player_id'], 'branch_reg_branch_player_unique');
            $table->unique('membership_id', 'branch_reg_membership_unique');
            $table->index('legacy_membership_id', 'branch_reg_legacy_id_index');
            $table->index(['branch_id', 'join_date'], 'branch_reg_branch_date_index');
            $table->index('join_date', 'branch_reg_date_index');
            $table->index('category_id', 'branch_reg_category_index');
            $table->index(['status_id', 'branch_id'], 'branch_reg_status_index');
            $table->index('player_id', 'branch_reg_player_index');
            $table->index('legacy_join_year', 'branch_reg_legacy_year_index');
        });
    }

    private function backfill(): void
    {
        $main = DB::table('website_configs')->value('main_branch_id')
            ?? DB::table('branch_player')->selectRaw('branch_id, COUNT(*) AS c')
                ->groupBy('branch_id')->orderByDesc('c')->orderBy('branch_id')->value('branch_id');
        $positionBranch = DB::table('positions')->pluck('branch_id', 'id');
        $now = now();

        DB::table('players')
            ->whereIn('id', DB::table('branch_player')->select('player_id'))
            ->orderBy('id')
            ->chunkById(500, function ($players) use ($main, $positionBranch, $now) {
                $links = DB::table('branch_player')->whereIn('player_id', $players->pluck('id'))
                    ->orderBy('id')->get()->groupBy('player_id');

                foreach ($players as $player) {
                    $rows = $links[$player->id];

                    $primary = $rows->firstWhere('branch_id', $main) ?? $rows->first();
                    // A position no registration's branch owns stays on the primary: nothing is lost.
                    $owner = $rows->firstWhere('branch_id', $positionBranch[$player->position_id] ?? null) ?? $primary;

                    foreach ($rows as $row) {
                        // Stamped by the fill below: an earlier run's row is never overwritten.
                        if ($row->updated_at !== null) {
                            continue;
                        }

                        DB::table('branch_player')->where('id', $row->id)->update([
                            'membership_id' => $row->id === $primary->id ? $player->membership_id : null,
                            'legacy_join_year' => $player->join_year,
                            'category_id' => $player->category_id,
                            'position_id' => $row->id === $owner->id ? $player->position_id : null,
                            'licence_number' => $player->licence_number,
                            'status_id' => $player->status_id,
                            'left_at' => $player->left_at,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }
            });
    }

    /** Back to the plain pivot. The players.* columns still hold their copies. */
    public function down(): void
    {
        Schema::dropIfExists('branch_player_old');
        Schema::create('branch_player_old', function (Blueprint $table) {
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('player_id');
            $table->primary(['branch_id', 'player_id']);
            $table->foreign('branch_id', 'branch_player_old_branch_fk')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('player_id', 'branch_player_old_player_fk')->references('id')->on('players')->cascadeOnDelete();
        });
        DB::statement('INSERT INTO branch_player_old (branch_id, player_id) SELECT branch_id, player_id FROM branch_player');
        Schema::drop('branch_player');
        Schema::rename('branch_player_old', 'branch_player');
    }
};
