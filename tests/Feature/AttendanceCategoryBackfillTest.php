<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceCategoryBackfillTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    #[Test]
    public function the_migration_backfills_existing_marks_and_running_it_twice_changes_nothing(): void
    {
        $this->assertTrue(Schema::hasColumn('attendances', 'category_id'));

        $u15 = $this->category('U15');
        $a = $this->player($u15);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        // A mark recorded before this column existed: category_id left null,
        // written straight through the query builder (bypassing the model,
        // whose fillable would otherwise happily accept it).
        DB::table('attendances')->insert([
            'training_session_id' => $session->id, 'player_id' => $a->id, 'category_id' => null,
            'status' => AttendanceStatus::Present->value, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require database_path('migrations/2026_09_30_100001_add_category_id_to_attendances.php');
        $migration->up();

        $this->assertSame($u15->id, Attendance::sole()->category_id);

        // A second run, as on every desktop boot: no error, nothing changes.
        $migration->up();

        $this->assertSame($u15->id, Attendance::sole()->category_id);
    }

    /**
     * The test above never exercises the `constrained()` DDL: RefreshDatabase
     * already migrated `category_id` in, so `up()`'s `hasColumn` guard always
     * skips straight to the backfill. sqlite implements that DDL as a full
     * table rebuild (create-copy-drop-rename — see the migration's docblock),
     * so it needs its own test with rows already in the table, to prove the
     * rebuild carries every row over and the unique and foreign-key
     * constraints survive it.
     */
    #[Test]
    public function the_migration_rebuilds_the_table_on_sqlite_without_losing_existing_marks_or_constraints(): void
    {
        $this->assertTrue(Schema::hasColumn('attendances', 'category_id'));

        // Undo RefreshDatabase's migrate so up() below runs the real
        // constrained()-column-add DDL, not just the backfill.
        Schema::table('attendances', fn (Blueprint $table) => $table->dropConstrainedForeignId('category_id'));
        $this->assertFalse(Schema::hasColumn('attendances', 'category_id'));

        $u15 = $this->category('U15');
        $u17 = $this->category('U17');
        $a = $this->player($u15);
        $b = $this->player($u17);
        $session = TrainingSession::create([
            'category_id' => $u15->id, 'date' => '2026-10-05', 'start_time' => '18:00', 'end_time' => '19:30',
            'kind' => SessionKind::Regular, 'state' => SessionState::Held,
        ]);
        DB::table('attendances')->insert([
            ['training_session_id' => $session->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Present->value, 'created_at' => now(), 'updated_at' => now()],
            ['training_session_id' => $session->id, 'player_id' => $b->id, 'status' => AttendanceStatus::AbsentUnexcused->value, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $migration = require database_path('migrations/2026_09_30_100001_add_category_id_to_attendances.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumn('attendances', 'category_id'));
        $marks = Attendance::orderBy('player_id')->get()->keyBy('player_id');
        $this->assertCount(2, $marks);
        $this->assertSame($u15->id, $marks[$a->id]->category_id);
        $this->assertSame($u17->id, $marks[$b->id]->category_id);

        // The (training_session_id, player_id) unique constraint must have
        // survived the rebuild.
        $this->expectException(QueryException::class);
        DB::table('attendances')->insert([
            'training_session_id' => $session->id, 'player_id' => $a->id, 'status' => AttendanceStatus::Present->value,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
