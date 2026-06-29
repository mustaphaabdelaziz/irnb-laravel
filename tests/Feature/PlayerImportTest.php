<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Player;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
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

    private function makeWorkbook(array $dataRows): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $header = array_fill(0, 19, 'header');
        $sheet->fromArray([$header, ...$dataRows], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'players.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true,
        );
    }

    #[Test]
    public function the_template_can_be_downloaded(): void
    {
        $this->actingAs($this->admin())
            ->get(route('players.import.template'))
            ->assertOk()
            ->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    #[Test]
    public function it_imports_players_and_assigns_mandatory_subscriptions(): void
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
        $file = $this->makeWorkbook([
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
        $this->assertDatabaseCount('player_subscriptions', 1);
    }
}
