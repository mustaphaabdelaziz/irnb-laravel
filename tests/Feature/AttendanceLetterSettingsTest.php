<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\AttendanceSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\AttendanceFixtures;
use Tests\TestCase;

class AttendanceLetterSettingsTest extends TestCase
{
    use AttendanceFixtures, RefreshDatabase;

    private const VALUES = [
        'player' => 'Ali Ben', 'category' => 'U15', 'period' => '01/10/2026 – 31/10/2026',
        'absences' => 4, 'lates' => 2, 'club' => 'IRNB',
    ];

    private const EMPTY = ['ar' => null, 'fr' => null, 'en' => null];

    private function payload(array $subject = [], array $body = []): array
    {
        return ['letter' => [
            'subject' => array_merge(self::EMPTY, $subject),
            'body' => array_merge(self::EMPTY, $body),
        ]];
    }

    #[Test]
    public function the_built_in_text_is_used_until_a_text_is_saved(): void
    {
        $this->assertSame(['subject' => self::EMPTY, 'body' => self::EMPTY], AttendanceSettings::get()['letter']);

        $fr = AttendanceSettings::letter(self::VALUES, 'fr');

        $this->assertSame('Assiduité de Ali Ben aux entraînements', $fr['subject']);
        $this->assertStringContainsString('Ali Ben (U15) a manqué 4 séance(s)', $fr['body']);
        $this->assertStringContainsString('en retard 2 fois durant la période 01/10/2026 – 31/10/2026.', $fr['body']);
        $this->assertStringEndsWith('IRNB', $fr['body']);
        $this->assertStringNotContainsString('{', $fr['body']);
        $this->assertSame('مواظبة Ali Ben على التدريبات', AttendanceSettings::letter(self::VALUES, 'ar')['subject']);
    }

    #[Test]
    public function a_saved_text_replaces_the_built_in_one_in_its_language_only(): void
    {
        $this->actingAs($this->admin())
            ->put(route('attendance.settings.letter'), $this->payload(
                ['fr' => 'Absences de {player}'],
                ['fr' => "Bonjour,\r\n{player} : {absences} absences, {lates} retards.", 'en' => ''],
            ))
            ->assertRedirect()
            ->assertSessionHas('success', 'flash.attendance_settings_saved');

        $letter = AttendanceSettings::get()['letter'];
        $this->assertSame('Absences de {player}', $letter['subject']['fr']);
        $this->assertSame("Bonjour,\n{player} : {absences} absences, {lates} retards.", $letter['body']['fr']);
        $this->assertNull($letter['body']['en']);
        $this->assertNull($letter['subject']['ar']);

        $this->assertSame('Absences de Ali Ben', AttendanceSettings::letter(self::VALUES, 'fr')['subject']);
        $this->assertSame("Bonjour,\nAli Ben : 4 absences, 2 retards.", AttendanceSettings::letter(self::VALUES, 'fr')['body']);
        $this->assertSame('Attendance of Ali Ben at training', AttendanceSettings::letter(self::VALUES, 'en')['subject']);
        // The other settings survive.
        $this->assertSame(60, AttendanceSettings::get()['alerts']['min_score_pct']);
        $this->assertSame('P', AttendanceSettings::get()['codes']['present']['code']);
    }

    #[Test]
    public function texts_are_validated(): void
    {
        $this->actingAs($this->admin())
            ->put(route('attendance.settings.letter'), $this->payload(['ar' => str_repeat('x', 151)], ['fr' => str_repeat('y', 3001)]))
            ->assertSessionHasErrors(['letter.subject.ar', 'letter.body.fr']);

        $this->assertSame(['subject' => self::EMPTY, 'body' => self::EMPTY], AttendanceSettings::get()['letter']);
    }

    #[Test]
    public function the_settings_page_sends_the_letter_and_saving_needs_edit(): void
    {
        $this->actingAs($this->admin())->get(route('attendance.settings'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('settings.letter.subject.fr', null)
                ->where('settings.letter.body.ar', null));

        $viewer = User::factory()->create(['privileges' => ['user'], 'role_id' => Role::factory()->create(['permissions' => ['attendance' => ['view']]])->id]);
        $this->actingAs($viewer)->put(route('attendance.settings.letter'), $this->payload(['fr' => 'X']))->assertForbidden();
        $this->assertNull(AttendanceSettings::get()['letter']['subject']['fr']);
    }
}
