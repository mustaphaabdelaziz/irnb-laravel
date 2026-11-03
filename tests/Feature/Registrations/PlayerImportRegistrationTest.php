<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Player;
use App\Models\User;
use App\Models\WebsiteConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerImportRegistrationTest extends TestCase
{
    use RefreshDatabase;

    /** Template order, retired skill slot included: a header-less file reads by position. */
    private const ORDER = [
        'firstname', 'lastname', 'father', 'grandfather', 'nickname', 'birthdate', 'gender', 'phone', 'email',
        'city', 'state', 'category', 'position', 'job', 'status', 'skill_level', 'blood_group',
        'medical_conditions', 'join_year', 'wilaya', 'other_positions', 'licence_number', 'branch',
    ];

    /** @param  list<array<string, string>>  $rows  cells by column key */
    private function import(array $rows): TestResponse
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, array_fill(0, count(self::ORDER), 'header'));
        foreach ($rows as $row) {
            fputcsv($fh, array_map(fn (string $key) => $row[$key] ?? '', self::ORDER));
        }
        rewind($fh);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, stream_get_contents($fh));
        fclose($fh);

        $admin = User::factory()->create(['privileges' => ['admin'], 'email_verified_at' => now()]);

        return $this->actingAs($admin)->post(route('players.import.store'), [
            'file' => new UploadedFile($path, 'players.csv', 'text/csv', null, true),
        ]);
    }

    #[Test]
    public function a_full_date_is_exact_and_a_year_goes_to_the_queue(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        WebsiteConfig::singleton()->update(['main_branch_id' => $foot->id]);

        $this->import([
            ['firstname' => 'Exact', 'lastname' => 'A', 'join_year' => '2022-09-15'],
            ['firstname' => 'Year', 'lastname' => 'B', 'join_year' => '2019'],
        ]);

        $exact = Player::where('firstname', 'Exact')->firstOrFail()->registrations()->firstOrFail();
        $this->assertSame('2022-09-15', $exact->join_date->toDateString());
        $this->assertSame('FB-2022-00001', $exact->membership_id);

        $year = Player::where('firstname', 'Year')->firstOrFail()->registrations()->firstOrFail();
        $this->assertNull($year->join_date);
        $this->assertSame(2019, $year->legacy_join_year);
        $this->assertSame('FB-2019-00001', $year->membership_id);
    }

    #[Test]
    public function an_empty_date_is_a_row_error_not_the_current_year(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->import([['firstname' => 'None', 'lastname' => 'A', 'join_year' => '']])
            ->assertSessionHas('error');

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function an_empty_date_is_a_row_error_in_a_club_with_no_branches_too(): void
    {
        $this->import([['firstname' => 'None', 'lastname' => 'A', 'join_year' => '']])
            ->assertSessionHas('error');

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function a_future_date_or_nonsense_is_a_row_error(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);
        $future = now()->addDays(3)->toDateString();

        foreach ([$future, 'tomorrow', '+1 week', 'soon', '2022-13-45'] as $cell) {
            $this->import([['firstname' => 'Bad', 'lastname' => 'A', 'join_year' => $cell]])
                ->assertSessionHas('error');
        }

        $this->assertSame(0, Player::count());
    }

    #[Test]
    public function a_day_first_date_and_an_excel_serial_are_read_as_dates(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);

        $this->import([
            ['firstname' => 'Slash', 'lastname' => 'A', 'join_year' => '15/09/2022'],
            ['firstname' => 'Serial', 'lastname' => 'B', 'join_year' => '44819'], // 2022-09-15
        ]);

        foreach (['Slash', 'Serial'] as $name) {
            $card = Player::where('firstname', $name)->firstOrFail()->registrations()->firstOrFail();
            $this->assertSame('2022-09-15', $card->join_date->toDateString());
        }
    }

    #[Test]
    public function the_branch_cell_picks_the_branch_and_an_unknown_one_is_a_row_error(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW', 'name_ar' => 'السباحة']);

        $this->import([['firstname' => 'Swim', 'lastname' => 'A', 'join_year' => '2025-03-10', 'branch' => 'السباحة']]);
        $this->assertSame($swim->id, Player::firstOrFail()->registrations()->firstOrFail()->branch_id);

        $this->import([['firstname' => 'Lost', 'lastname' => 'A', 'join_year' => '2025-03-10', 'branch' => 'Chess']])
            ->assertSessionHas('error');
        $this->assertNull(Player::where('firstname', 'Lost')->first());
    }

    #[Test]
    public function with_no_branches_the_year_stays_on_the_player(): void
    {
        $this->import([['firstname' => 'Flat', 'lastname' => 'A', 'join_year' => '2020-05-01']]);

        $player = Player::firstOrFail();
        $this->assertSame(2020, $player->join_year);
        $this->assertSame(0, $player->registrations()->count());
    }
}
