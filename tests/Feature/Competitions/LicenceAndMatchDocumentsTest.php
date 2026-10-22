<?php

namespace Tests\Feature\Competitions;

use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Services\Player\DocumentChecklist;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\CompetitionFixtures;
use Tests\TestCase;

class LicenceAndMatchDocumentsTest extends TestCase
{
    use CompetitionFixtures, RefreshDatabase;

    private function makeCsv(array $dataRows): UploadedFile
    {
        $header = array_fill(0, 22, 'header'); // Updated for 22 columns including licence_number

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF"); // UTF-8 BOM
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
    public function the_licence_number_is_saved_from_the_player_form(): void
    {
        $player = $this->player($this->category());

        $this->actingAs($this->admin())->put(route('players.update', $player), [
            'firstname' => $player->firstname, 'lastname' => $player->lastname, 'licence_number' => 'DZ-FAF-12345',
        ])->assertSessionHasNoErrors();

        $this->assertSame('DZ-FAF-12345', $player->fresh()->licence_number);
    }

    #[Test]
    public function a_document_type_can_be_required_for_matches(): void
    {
        $this->actingAs($this->admin())->post(route('document-types.store'), [
            'name' => 'Medical certificate', 'validity' => 'season', 'is_required' => false,
            'required_for_matches' => true, 'is_active' => true,
        ])->assertSessionHasNoErrors();

        $this->assertTrue(DocumentType::where('name', 'Medical certificate')->sole()->required_for_matches);
    }

    #[Test]
    public function the_per_type_missing_sql_matches_received_and_missing_players(): void
    {
        $type = DocumentType::create(['code' => 'medical', 'name' => 'Medical', 'validity' => 'none', 'is_required' => false, 'required_for_matches' => true, 'is_active' => true]);
        $category = $this->category();
        [$has, $lacks] = [$this->player($category), $this->player($category)];
        PlayerDocument::create(['player_id' => $has->id, 'document_type_id' => $type->id, 'state' => 'received', 'received_at' => now()->toDateString()]);

        [$sql, $bindings] = DocumentChecklist::missingTypeSql($type);
        $missing = Player::query()->whereIn('id', [$has->id, $lacks->id])->select('id')->selectRaw("{$sql} as missing", $bindings)
            ->pluck('missing', 'id')->map(fn ($v) => (int) $v)->all();

        $this->assertSame([$has->id => 0, $lacks->id => 1], $missing);
    }

    #[Test]
    public function licence_number_is_imported_from_csv(): void
    {
        $category = $this->category();
        // firstname, lastname, father, grandfather, nickname, birthdate, gender, phone,
        // email, city, state, category, position, job, status, skill_level, blood_group,
        // medical_conditions, join_year, wilaya, other_positions, licence_number
        $file = $this->makeCsv([
            ['Karim', 'Safir', '', '', '', '2006-03-15', 'Male', '0550111222', '', 'Algiers', 'Algiers', 'Senior', 'CB', '', 'worker', '7', 'B+', '', (string) now()->year, '16', '', 'LIC-77'],
        ]);

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => $file])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseCount('players', 1);
        $player = Player::firstOrFail();
        $this->assertSame('LIC-77', $player->licence_number);
    }

    #[Test]
    public function a_file_with_the_old_licence_header_still_imports(): void
    {
        $this->category();
        $header = [UiLang::get('col.firstname', null, 'en'), UiLang::get('col.lastname', null, 'en'), 'Licence number'];

        $fh = fopen('php://temp', 'r+');
        fputcsv($fh, $header);
        fputcsv($fh, ['Karim', 'Safir', 'LIC-88']);
        rewind($fh);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        file_put_contents($path, stream_get_contents($fh));
        fclose($fh);

        $this->actingAs($this->admin())
            ->post(route('players.import.store'), ['file' => new UploadedFile($path, 'players.csv', 'text/csv', null, true)])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame('LIC-88', Player::firstOrFail()->licence_number);
    }
}
