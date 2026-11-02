<?php

namespace Tests\Feature;

use App\Models\Role;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** What a new database needs that SQLite does not enforce on its own. */
class FreshMigrationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function every_index_name_fits_the_mysql_limit(): void
    {
        $tooLong = collect(DB::select("select name from sqlite_master where type = 'index' and name not like 'sqlite_%'"))
            ->pluck('name')
            ->filter(fn (string $name) => strlen($name) > 64)
            ->values()
            ->all();

        $this->assertSame([], $tooLong);
    }

    #[Test]
    public function the_role_seeder_runs_before_the_own_teams_column_exists(): void
    {
        Schema::table('roles', fn ($table) => $table->dropColumn('own_teams_only'));

        (new RoleSeeder)->run();

        $this->assertTrue(Role::where('key', 'coach')->exists());
    }

    #[Test]
    public function the_role_seeder_limits_the_coach_to_their_teams(): void
    {
        (new RoleSeeder)->run();

        $this->assertTrue(Role::where('key', 'coach')->first()->own_teams_only);
    }
}
