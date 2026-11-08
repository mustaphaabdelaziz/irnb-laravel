<?php

namespace Tests\Feature\LiteraryReport;

use App\Models\BoardMember;
use App\Models\BoardRole;
use App\Models\BoardTerm;
use App\Models\LiteraryReport;
use App\Models\Role;
use App\Models\User;
use App\Services\LiteraryReport\LiteraryReportBuilder;
use App\Services\LiteraryReport\Sections\SignaturesSection;
use App\Support\UiLang;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SignaturesSectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
    }

    private function member(string $name, string $role): BoardMember
    {
        $term = BoardTerm::firstOrCreate(['name' => 'Term Sig'], ['is_current' => true]);

        return BoardMember::create(['name' => $name, 'role' => $role, 'board_term_id' => $term->id, 'status' => 'active']);
    }

    private function report(array $extra = []): LiteraryReport
    {
        return LiteraryReport::create($extra + ['title' => 'R', 'period_from' => '2025-09-01', 'period_to' => '2026-08-31', 'sections' => ['signatures']]);
    }

    #[Test]
    public function it_prints_the_chosen_members_with_their_role_in_slot_order(): void
    {
        BoardRole::create(['name' => 'sg_custom', 'label' => 'الأمين العام للجمعية', 'level' => 'bureau', 'sort_order' => 90]);
        $secretary = $this->member('عبد العزيز مصطفى', 'sg_custom');
        $president = $this->member('Président Test', 'president');

        $data = app(SignaturesSection::class)->build($this->report([
            'signer_1_board_member_id' => $president->id, 'signer_2_board_member_id' => $secretary->id,
        ]));

        $this->assertSame([
            ['label' => 'إمضاء وختم واسم '.UiLang::get('president'), 'name' => 'Président Test'],
            ['label' => 'إمضاء وختم واسم الأمين العام للجمعية', 'name' => 'عبد العزيز مصطفى'],
        ], $data['signers']);
    }

    #[Test]
    public function an_empty_slot_is_skipped_and_no_slot_gives_an_empty_section(): void
    {
        $president = $this->member('P', 'president');

        $this->assertCount(1, app(SignaturesSection::class)->build($this->report(['signer_2_board_member_id' => $president->id]))['signers']);
        $this->assertSame([], app(SignaturesSection::class)->build($this->report()));
    }

    #[Test]
    public function a_deleted_member_drops_out(): void
    {
        $president = $this->member('P', 'president');
        $report = $this->report(['signer_1_board_member_id' => $president->id]);
        $president->delete();

        $this->assertSame([], app(SignaturesSection::class)->build($report->fresh()));
    }

    #[Test]
    public function signatures_need_no_extra_right(): void
    {
        $report = $this->report(['signer_1_board_member_id' => $this->member('P', 'president')->id]);
        $user = User::factory()->create(['role_id' => Role::factory()->create(['permissions' => ['literary_reports' => ['view']]])->id]);

        $this->assertSame(['signatures'], array_column(app(LiteraryReportBuilder::class)->build($report, $user), 'key'));
    }
}
