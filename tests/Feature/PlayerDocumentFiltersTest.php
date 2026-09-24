<?php

namespace Tests\Feature;

use App\Models\CountryState;
use App\Models\DocumentType;
use App\Models\Player;
use App\Models\PlayerDocument;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PlayerDocumentFiltersTest extends TestCase
{
    use RefreshDatabase;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-05');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function admin(): User
    {
        return User::factory()->admin()->create(['email_verified_at' => now()]);
    }

    private function player(string $lastname, array $attributes = []): Player
    {
        $this->sequence++;

        return Player::create([
            'membership_id' => '20268'.str_pad((string) $this->sequence, 4, '0', STR_PAD_LEFT),
            'firstname' => 'Test',
            'lastname' => $lastname,
            'birthdate' => '1990-01-01',
            ...$attributes,
        ]);
    }

    private function type(string $code): DocumentType
    {
        return DocumentType::where('code', $code)->firstOrFail();
    }

    private function receive(Player $player, string $code, ?string $validUntil = null): void
    {
        PlayerDocument::create([
            'player_id' => $player->id,
            'document_type_id' => $this->type($code)->id,
            'state' => PlayerDocument::RECEIVED,
            'received_at' => '2026-09-01',
            'valid_until' => $validUntil,
        ]);
    }

    /** Picture + birth certificate + a medical certificate valid all season. */
    private function complete(Player $player): Player
    {
        $player->update(['picture_url' => '/media/players/'.$player->id.'.jpg']);
        $this->receive($player, 'birth_certificate');
        $this->receive($player, 'medical_certificate', '2027-08-31');

        return $player;
    }

    private function props(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin())
            ->get(route('players.index', $query))
            ->assertOk()
            ->viewData('page')['props'];
    }

    /** @return list<string> */
    private function names(array $props): array
    {
        return collect($props['players']['data'])->pluck('lastname')->sort()->values()->all();
    }

    #[Test]
    public function each_row_carries_its_missing_documents_count(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');
        $this->player('Minor', ['birthdate' => '2012-01-01']);

        $counts = collect($this->props()['players']['data'])
            ->mapWithKeys(fn (array $row) => [$row['lastname'] => (int) $row['missing_documents_count']])
            ->all();

        $this->assertSame(['Adult' => 3, 'Complete' => 0, 'Minor' => 4], collect($counts)->sortKeys()->all());
    }

    #[Test]
    public function the_missing_filter_keeps_only_players_with_something_missing(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');

        $props = $this->props(['documents' => 'missing']);

        $this->assertSame(['Adult'], $this->names($props));
        $this->assertSame('missing', $props['filters']['documents']);
    }

    #[Test]
    public function the_missing_type_filter_keeps_only_players_missing_that_type(): void
    {
        $hasMedical = $this->player('HasMedical');
        $this->receive($hasMedical, 'medical_certificate', '2027-08-31');
        $expired = $this->player('ExpiredMedical');
        $this->receive($expired, 'medical_certificate', '2026-08-31');
        $this->player('NoMedical');

        $props = $this->props(['documents' => 'missing-'.$this->type('medical_certificate')->id]);

        $this->assertSame(['ExpiredMedical', 'NoMedical'], $this->names($props));
    }

    #[Test]
    public function the_missing_type_filter_on_the_photo_finds_players_without_a_picture(): void
    {
        $this->player('WithPicture', ['picture_url' => '/media/players/x.jpg']);
        $this->player('WithoutPicture');

        $props = $this->props(['documents' => 'missing-'.$this->type('photo')->id]);

        $this->assertSame(['WithoutPicture'], $this->names($props));
    }

    #[Test]
    public function an_optional_type_or_a_malformed_value_filters_nothing_in(): void
    {
        $this->player('Adult');

        $this->assertSame([], $this->names($this->props(['documents' => 'missing-'.$this->type('school_certificate')->id])));
        $this->assertSame(['Adult'], $this->names($this->props(['documents' => 'nonsense'])));
    }

    #[Test]
    public function the_expiring_filter_keeps_players_with_a_document_expiring_within_thirty_days(): void
    {
        $soon = $this->player('Soon');
        $this->receive($soon, 'medical_certificate', '2026-10-20');
        $later = $this->player('Later');
        $this->receive($later, 'medical_certificate', '2027-08-31');
        $this->player('Nothing');

        $this->assertSame(['Soon'], $this->names($this->props(['documents' => 'expiring'])));
    }

    #[Test]
    public function the_stats_follow_the_documents_filter(): void
    {
        $this->complete($this->player('Complete'));
        $this->player('Adult');
        $this->player('Other');

        $props = $this->props(['documents' => 'missing']);

        $this->assertSame(2, collect($props['categoryStats'])->sum('count'));
        $this->assertSame(2, collect($props['ageStats'])->sum('count'));
    }

    #[Test]
    public function the_no_wilaya_option_finds_players_without_one(): void
    {
        $wilaya = CountryState::query()->whereNotNull('code')->orderBy('code')->firstOrFail();
        $this->player('Placed', ['wilaya_id' => $wilaya->id]);
        $this->player('Unplaced');

        $this->assertSame(['Unplaced'], $this->names($this->props(['wilaya_id' => 'none'])));
        $this->assertSame(['Placed'], $this->names($this->props(['wilaya_id' => $wilaya->id])));
        $this->assertSame('none', $this->props(['wilaya_id' => 'none'])['filters']['wilaya_id']);
    }

    #[Test]
    public function the_filter_offers_the_required_active_types(): void
    {
        $this->type('parental_authorization')->update(['is_active' => false]);

        $codes = collect($this->props()['documentTypes'])->pluck('code')->all();

        $this->assertSame(['birth_certificate', 'photo', 'medical_certificate'], $codes);
    }

    #[Test]
    public function the_column_and_filters_need_player_rights_only(): void
    {
        $viewer = User::factory()->create([
            'privileges' => ['user'],
            'role_id' => Role::create(['key' => 'viewer', 'name' => ['en' => 'Viewer'], 'permissions' => ['players' => ['view']]])->id,
        ]);
        $this->player('Adult');

        $props = $this->props(['documents' => 'missing'], $viewer);

        $this->assertSame(['Adult'], $this->names($props));
        $this->assertSame(3, (int) $props['players']['data'][0]['missing_documents_count']);
    }
}
