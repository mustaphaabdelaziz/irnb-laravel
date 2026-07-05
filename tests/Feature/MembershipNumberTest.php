<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Services\Player\MembershipNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MembershipNumberTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function format_builds_year_plus_five_digits(): void
    {
        $this->assertSame('202600001', MembershipNumber::format(2026, 1));
        $this->assertSame('202600042', MembershipNumber::format(2026, 42));
    }

    #[Test]
    public function next_sequence_is_one_for_an_empty_year(): void
    {
        $this->assertSame(1, MembershipNumber::nextSequence(2026));
    }

    #[Test]
    public function next_sequence_increments_within_a_year_and_is_independent_per_year(): void
    {
        Player::create(['membership_id' => '202600001', 'firstname' => 'A']);
        Player::create(['membership_id' => '202600002', 'firstname' => 'B']);

        $this->assertSame(3, MembershipNumber::nextSequence(2026));
        $this->assertSame(1, MembershipNumber::nextSequence(2027));
    }

    #[Test]
    public function format_clamps_out_of_range_years_to_fit_the_column(): void
    {
        $id = MembershipNumber::format(99999, 1);
        $this->assertSame('999900001', $id);
        $this->assertLessThanOrEqual(10, strlen($id));
    }

    #[Test]
    public function next_sequence_handles_legacy_wider_suffixes(): void
    {
        Player::create(['membership_id' => '2026000123', 'firstname' => 'L']); // legacy year+6-digit id
        $this->assertSame(124, MembershipNumber::nextSequence(2026));
    }
}
