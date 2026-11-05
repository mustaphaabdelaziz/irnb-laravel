<?php

namespace Tests\Feature\Registrations;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\ReceiptPrint;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Finance\ReceiptData;
use App\Services\Finance\ReceiptPrinter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

/** Spec section 5: a receipt shows the subscription's branch, otherwise the main registration. */
class ReceiptRegistrationTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    /** bothActive() leaves the swimming row without an ID (no join date); give it one so the assertions can tell the rows apart. */
    private function bothWithIds(): Player
    {
        $player = $this->bothActive();
        $player->registrationIn($this->swim->id)->update(['membership_id' => 'SW-2020-00001']);

        return $player->refresh();
    }

    private function owed(Player $player, array $branches = []): PlayerSubscription
    {
        $sub = Subscription::create([
            'name' => 'Season '.uniqid(), 'year' => (int) now()->year,
            'amount_student' => 1500, 'amount_worker' => 1500,
            'is_mandatory' => false, 'is_active' => true,
        ]);
        $sub->branches()->attach(collect($branches)->pluck('id'));

        return $sub->assignTo($player);
    }

    private function paid(Player $player, ?PlayerSubscription $line, string $category = 'subscription'): Transaction
    {
        return Transaction::create([
            'amount' => 100, 'transaction_date' => now(), 'transaction_type' => 'income', 'category' => $category,
            'payment_method' => 'cash', 'status' => 'Paid',
            'related_entity_type' => 'Player', 'related_entity_id' => $player->id, 'player_subscription_id' => $line?->id,
        ]);
    }

    private function receipt(Transaction $transaction, ?Collection $lines = null): array
    {
        $user = User::factory()->admin()->create();
        $print = app(ReceiptPrinter::class)->record($transaction, ReceiptPrint::FORMAT_THERMAL, $user, $lines);

        return app(ReceiptData::class)->build($transaction, $print, $lines);
    }

    private function assertShows(array $data, ?string $branch, ?string $category, ?string $id): void
    {
        $this->assertSame($branch, $data['branches']);
        $this->assertSame($category, $data['memberCategory']);
        $this->assertSame($id, $data['memberId']);
    }

    #[Test]
    public function a_one_branch_subscription_shows_that_registration(): void
    {
        $player = $this->bothWithIds();
        $data = $this->receipt($this->paid($player, $this->owed($player, [$this->swim])));

        $this->assertShows($data, 'Swimming', 'U15', $player->registrationIn($this->swim->id)->membership_id);
    }

    #[Test]
    public function no_branch_or_two_branches_show_the_main_registration(): void
    {
        $player = $this->bothActive();
        $footId = $player->registrationIn($this->foot->id)->membership_id;

        $this->assertShows($this->receipt($this->paid($player, $this->owed($player))), 'Football', 'Senior', $footId);
        $this->assertShows($this->receipt($this->paid($player, $this->owed($player, [$this->foot, $this->swim]))), 'Football', 'Senior', $footId);
    }

    #[Test]
    public function a_payment_with_no_subscription_shows_the_main_registration(): void
    {
        $player = $this->bothActive();

        $this->assertShows($this->receipt($this->paid($player, null, 'donation')), 'Football', 'Senior', $player->registrationIn($this->foot->id)->membership_id);
    }

    #[Test]
    public function a_group_uses_the_branch_only_when_all_lines_share_it(): void
    {
        $player = $this->bothActive();
        $swimA = $this->paid($player, $this->owed($player, [$this->swim]));
        $swimB = $this->paid($player, $this->owed($player, [$this->swim]));
        $foot = $this->paid($player, $this->owed($player, [$this->foot]));

        $shared = $this->receipt($swimA, new Collection([$swimA, $swimB]));
        $this->assertSame('Swimming', $shared['branches']);

        $mixed = $this->receipt($swimA, new Collection([$swimA, $foot]));
        $this->assertSame('Football', $mixed['branches']);
    }

    #[Test]
    public function a_grouped_donation_does_not_break_the_shared_branch(): void
    {
        $player = $this->bothActive();
        $swim = $this->paid($player, $this->owed($player, [$this->swim]));
        $donation = $this->paid($player, null, 'donation');

        $this->assertSame('Swimming', $this->receipt($swim, new Collection([$swim, $donation]))['branches']);
    }

    #[Test]
    public function a_paid_debt_of_a_left_branch_still_names_it(): void
    {
        $player = $this->leftFootball();
        $data = $this->receipt($this->paid($player, $this->owed($player, [$this->foot])));

        $this->assertShows($data, 'Football', 'Senior', $player->registrationIn($this->foot->id)->membership_id);
    }

    #[Test]
    public function left_everywhere_still_prints(): void
    {
        $player = $this->leftBoth();

        $this->assertSame('Senior', $this->receipt($this->paid($player, $this->owed($player)))['memberCategory']);
    }

    #[Test]
    public function no_registration_shows_the_players_own_values_and_no_branch(): void
    {
        $player = $this->noRegistration();
        $player->update(['membership_id' => '2020FLAT1']);

        $this->assertShows($this->receipt($this->paid($player, $this->owed($player))), null, 'Senior', '2020FLAT1');
    }

    #[Test]
    public function both_papers_print_it_and_the_snapshot_records_the_same_id(): void
    {
        $player = $this->bothWithIds();
        $swimId = $player->registrationIn($this->swim->id)->membership_id;
        $footId = $player->registrationIn($this->foot->id)->membership_id;
        $transaction = $this->paid($player, $this->owed($player, [$this->swim]));
        $data = $this->receipt($transaction);

        foreach (['pdf.receipt', 'pdf.receipt-ticket'] as $view) {
            $html = view($view, [...$data, 'rtl' => false])->render();
            $this->assertStringContainsString($swimId, $html);
            $this->assertStringContainsString('U15', $html);
            $this->assertStringContainsString('Swimming', $html);
            $this->assertStringNotContainsString($footId, $html);
        }

        $this->assertSame($swimId, ReceiptPrint::sole()->snapshot['membership_id']);
    }
}
