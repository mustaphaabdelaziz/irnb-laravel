<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documents per registration. Existing rows go to the player's main registration,
 * so a two-branch player's other branch starts with its documents missing, as the
 * owner chose. A player with no registration keeps a blank value.
 *
 * The foreign key blanks the link on delete, so a removed registration never takes
 * a document or its files with it. On SQLite it is declared with the column:
 * foreign() would rebuild this table inside the migration's transaction, where
 * foreign_keys can't be switched off, and the drop would cascade away
 * player_document_files.
 *
 * Every step checks its own work, so a run that failed half way (MySQL DDL is not
 * transactional) finishes on the next run, and a second run changes nothing.
 * Literal values, not the models: a migration must not move when the code does.
 */
return new class extends Migration
{
    private const UNIQUE = 'player_documents_registration_type_unique';

    private const OLD_UNIQUE = 'player_documents_player_id_document_type_id_unique';

    private const PLAYER_INDEX = 'player_documents_player_id_index';

    private const FOREIGN = 'player_documents_registration_foreign';

    public function up(): void
    {
        if (! Schema::hasColumn('player_documents', 'branch_registration_id')) {
            if ($this->isSqlite()) {
                DB::statement('ALTER TABLE player_documents ADD COLUMN branch_registration_id INTEGER REFERENCES branch_player(id) ON DELETE SET NULL');
            } else {
                Schema::table('player_documents', fn (Blueprint $t) => $t->unsignedBigInteger('branch_registration_id')->nullable());
            }
        }
        // MySQL's player_id foreign key must keep an index once the old key is gone.
        if (! Schema::hasIndex('player_documents', self::PLAYER_INDEX)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->index('player_id', self::PLAYER_INDEX));
        }

        $this->backfill();

        if (! Schema::hasIndex('player_documents', self::UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->unique(['branch_registration_id', 'document_type_id'], self::UNIQUE));
        }
        if (Schema::hasIndex('player_documents', self::OLD_UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropUnique(self::OLD_UNIQUE));
        }
        if (! $this->isSqlite() && ! $this->hasForeignKey()) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->foreign('branch_registration_id', self::FOREIGN)->references('id')->on('branch_player')->nullOnDelete());
        }
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function hasForeignKey(): bool
    {
        return collect(Schema::getForeignKeys('player_documents'))->contains(fn (array $key) => $key['columns'] === ['branch_registration_id']);
    }

    public function backfill(): void
    {
        $mainBranch = DB::table('website_configs')->value('main_branch_id');
        $left = DB::table('player_statuses')->where('code', 'left')->value('id');

        DB::table('branch_player')
            ->whereIn('player_id', DB::table('player_documents')->whereNull('branch_registration_id')->select('player_id'))
            ->orderBy('id')
            ->get(['id', 'player_id', 'branch_id', 'status_id', 'join_date', 'legacy_join_year'])
            ->groupBy('player_id')
            ->each(function (Collection $rows, $playerId) use ($mainBranch, $left) {
                DB::table('player_documents')
                    ->where('player_id', $playerId)
                    ->whereNull('branch_registration_id')
                    ->update(['branch_registration_id' => $this->mainOf($rows, $mainBranch, $left)->id]);
            });
    }

    /** RegistrationService::primary()'s rule, kept here so this migration never moves. */
    private function mainOf(Collection $rows, mixed $mainBranch, mixed $left): object
    {
        $active = $rows->reject(fn ($r) => $left !== null && (int) $r->status_id === (int) $left);

        return $active->first(fn ($r) => $mainBranch !== null && (int) $r->branch_id === (int) $mainBranch)
            ?? $active->sortBy(fn ($r) => [
                $r->join_date !== null ? substr((string) $r->join_date, 0, 10)
                    : ($r->legacy_join_year !== null ? $r->legacy_join_year.'-12-31' : '9999-12-31'),
                $r->id,
            ])->first()
            ?? $rows->first();
    }

    /**
     * Back to one row per player and type. The oldest row stays, and the others'
     * files move onto it, so no scan loses its record.
     */
    public function down(): void
    {
        if (! Schema::hasColumn('player_documents', 'branch_registration_id')) {
            return;
        }

        DB::table('player_documents')->orderBy('id')->get(['id', 'player_id', 'document_type_id'])
            ->groupBy(fn ($row) => $row->player_id.'-'.$row->document_type_id)
            ->each(function (Collection $rows) {
                $extra = $rows->slice(1)->pluck('id');
                if ($extra->isNotEmpty()) {
                    DB::table('player_document_files')->whereIn('player_document_id', $extra)->update(['player_document_id' => $rows->first()->id]);
                    DB::table('player_documents')->whereIn('id', $extra)->delete();
                }
            });

        if (! $this->isSqlite() && $this->hasForeignKey()) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropForeign(self::FOREIGN));
        }
        if (Schema::hasIndex('player_documents', self::UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropUnique(self::UNIQUE));
        }
        if (! Schema::hasIndex('player_documents', self::OLD_UNIQUE)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->unique(['player_id', 'document_type_id'], self::OLD_UNIQUE));
        }
        if (Schema::hasIndex('player_documents', self::PLAYER_INDEX)) {
            Schema::table('player_documents', fn (Blueprint $t) => $t->dropIndex(self::PLAYER_INDEX));
        }
        Schema::table('player_documents', fn (Blueprint $t) => $t->dropColumn('branch_registration_id'));
    }
};
