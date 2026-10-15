<?php

namespace Tests\Unit;

use App\Models\Game;
use App\Services\Competitions\ReportValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ReportValidatorTest extends TestCase
{
    private function game(bool $rolling = false): Game
    {
        return (new Game)->forceFill(['extra_time_played' => false, 'rules' => [
            'periods_count' => 2, 'period_minutes' => 45, 'extra_time_minutes' => 30, 'rolling_subs' => $rolling,
            'sanction_types' => [['key' => 'yellow', 'ends_play' => false], ['key' => 'red', 'ends_play' => true]],
        ]]);
    }

    private function data(array $events, array $players = []): array
    {
        return ['extra_time_played' => false, 'events' => $events, 'players' => $players, 'mvp_player_id' => null];
    }

    private const ROLES = [1 => 'starter', 2 => 'starter', 3 => 'bench', 4 => 'bench'];

    private function sub(int $minute, int $in, int $out): array
    {
        return ['type' => 'substitution', 'subtype' => null, 'minute' => $minute, 'player_id' => $in, 'related_player_id' => $out];
    }

    #[Test]
    public function a_clean_report_has_no_errors(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            ['type' => 'goal', 'subtype' => 'normal', 'minute' => null, 'player_id' => 1, 'related_player_id' => 2],
            ['type' => 'goal', 'subtype' => 'opponent_own_goal', 'minute' => 30, 'player_id' => null, 'related_player_id' => null],
            $this->sub(60, 3, 1),
            ['type' => 'sanction', 'subtype' => 'yellow', 'minute' => null, 'player_id' => 3, 'related_player_id' => null],
        ]));

        $this->assertSame([], $errors);
    }

    #[Test]
    public function substitution_timing_is_checked_in_minute_order(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            $this->sub(70, 4, 1),
            $this->sub(60, 3, 1),
            $this->sub(80, 1, 2),
        ]));

        $this->assertSame('comp.error.not_on_pitch', $errors['events.0.related_player_id']);
        $this->assertSame('comp.error.cannot_come_on', $errors['events.2.player_id']);
        $this->assertArrayNotHasKey('events.1.player_id', $errors);
    }

    #[Test]
    public function a_sent_off_player_cannot_be_subbed_off_and_minutes_must_fit(): void
    {
        $errors = (new ReportValidator)->errors($this->game(), self::ROLES, $this->data([
            ['type' => 'sanction', 'subtype' => 'red', 'minute' => 50, 'player_id' => 2, 'related_player_id' => null],
            $this->sub(60, 3, 2),
            ['type' => 'sanction', 'subtype' => 'red', 'minute' => null, 'player_id' => 1, 'related_player_id' => null],
            ['type' => 'goal', 'subtype' => 'normal', 'minute' => 95, 'player_id' => 1, 'related_player_id' => 1],
        ]));

        $this->assertSame('comp.error.not_on_pitch', $errors['events.1.related_player_id']);
        $this->assertSame('comp.error.minute_required', $errors['events.2.minute']);
        $this->assertSame('comp.error.minute_out_of_range', $errors['events.3.minute']);
        $this->assertSame('comp.error.assist_is_scorer', $errors['events.3.related_player_id']);
    }

    #[Test]
    public function rolling_formats_refuse_substitution_events_and_players_must_be_in_the_line_up(): void
    {
        $errors = (new ReportValidator)->errors($this->game(true), self::ROLES, $this->data(
            [$this->sub(10, 3, 1), ['type' => 'goal', 'subtype' => 'normal', 'minute' => 5, 'player_id' => 9, 'related_player_id' => null]],
            [['player_id' => 1, 'minutes_played' => 120]],
        ));

        $this->assertSame('comp.error.no_substitutions', $errors['events.0.type']);
        $this->assertSame('comp.error.not_in_lineup', $errors['events.1.player_id']);
        $this->assertSame('comp.error.minute_out_of_range', $errors['players.0.minutes_played']);
    }
}
