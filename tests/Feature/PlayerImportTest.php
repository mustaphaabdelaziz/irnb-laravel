<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerImportTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'privileges' => ['admin'],
            'email_verified_at' => now(),
        ]);
    }

    private function makeCsv(array $dataRows): UploadedFile
    {
        $header = array_fill(0, 19, 'header');

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM, matching the real template
        fputcsv($fh, $header);
        foreach ($dataRows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $content = stream_get_contents($fh);
        fclose($fh);

        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, $content);

        return new UploadedFile($path, 'players.csv', 'text/csv', null, true);
    }

    #[Test]
    public function the_template_can_be_downloaded(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.import.template'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    #[Test]
    public function it_imports_players_without_attaching_subscriptions(): void
    {
        $category = Category::create(['name' => 'U17']);
        $subscription = Subscription::create([
            'name' => 'Annual Membership',
            'year' => (int) now()->year,
            'amount_student' => 2000,
            'amount_worker' => 3000,
            'is_mandatory' => true,
            'is_active' => true,
        ]);
        $subscription->categories()->attach($category->id);

        // firstname, lastname, father, grandfather, nickname, birthdate, gender, phone,
        // email, city, state, category, position, job, status, skill, blood, medical, join_year
        $file = $this->makeCsv([
            ['Ali', 'Brahimi', '', '', '', '2008-05-20', 'Male', '0550000000', '', 'Algiers', 'Algiers', 'U17', 'GK', '', 'student', '6', 'O+', '', (string) now()->year],
            ['', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', ''], // blank row ignored
        ]);

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('players', 1);
        $player = Player::firstOrFail();
        $this->assertSame('Ali', $player->firstname);
        $this->assertSame($category->id, $player->category_id);
        $this->assertSame(6, $player->skill_level);
        $this->assertTrue($player->is_student);
        $this->assertSame('منخرط', $player->status->name);
        // Owner decision: registration (import included) attaches no subscription.
        $this->assertDatabaseCount('player_subscriptions', 0);
    }

    #[Test]
    public function it_defaults_to_worker_when_status_is_blank_or_not_student(): void
    {
        $blank = ['Sami', 'Kaci', '', '', '', '2000-01-01', 'Male', '', '', 'Algiers', 'Algiers', '', '', '', '', '5', '', '', (string) now()->year];
        $worker = ['Nabil', 'Saidi', '', '', '', '1995-01-01', 'Male', '', '', 'Algiers', 'Algiers', '', '', '', 'worker', '5', '', '', (string) now()->year];

        $file = $this->makeCsv([$blank, $worker]);

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertFalse(Player::where('firstname', 'Sami')->firstOrFail()->is_student);
        $this->assertFalse(Player::where('firstname', 'Nabil')->firstOrFail()->is_student);
    }
}
