<?php

namespace Tests\Feature;

use App\Enums\AttendanceStatus;
use App\Enums\SessionKind;
use App\Enums\SessionState;
use App\Models\Attendance;
use App\Models\TrainingSession;
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
}
