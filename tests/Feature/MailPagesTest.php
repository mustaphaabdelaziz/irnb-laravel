<?php

namespace Tests\Feature;

use App\Models\MailEntry;
use App\Models\User;
use App\Services\Secretariat\MailRegister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MailPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-26 10:00:00');
        $this->admin = User::factory()->admin()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function letter(): MailEntry
    {
        return app(MailRegister::class)->create([
            'direction' => MailEntry::OUT, 'entry_date' => '2026-11-26', 'correspondent' => 'Test Ligue',
            'subject' => 'Engagement', 'body' => "Monsieur,\nBonjour.",
        ], $this->admin);
    }

    #[Test]
    public function the_register_and_the_create_page_render(): void
    {
        $this->letter();

        $this->actingAs($this->admin)->get(route('mail.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Index')->has('entries.data', 1));
        $this->get(route('mail.create', ['direction' => 'in']))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Create')->where('direction', 'in')->has('correspondents.in', 1));
    }

    #[Test]
    public function the_edit_and_show_pages_render(): void
    {
        $entry = $this->letter();

        $this->actingAs($this->admin)->get(route('mail.edit', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Edit')
                ->where('editable', ['entry_date', 'correspondent', 'address', 'subject', 'body', 'notes']));
        $this->get(route('mail.show', $entry))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Mail/Show')
                ->where('entry.reference', '2026/01')->where('can.print', true)->where('source', null));
    }
}
