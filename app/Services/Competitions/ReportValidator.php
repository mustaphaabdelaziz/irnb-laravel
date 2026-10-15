<?php

namespace App\Services\Competitions;

use App\Models\Game;
use App\Models\GameEvent;

/**
 * Blocking checks on a match report. Substitutions and send-offs are walked
 * in minute order to know who is on the pitch at each moment: a player comes
 * on only from the bench and only once (no re-entry), and goes off only while
 * on the pitch. Returns field => i18n key; empty means valid.
 */
final class ReportValidator
{
    /**
     * @param  array<int, string>  $roles  player_id => 'starter'|'bench' for every called-up player
     * @param  array<string, mixed>  $data  the validated report payload
     * @return array<string, string>
     */
    public function errors(Game $game, array $roles, array $data): array
    {
        $errors = [];
        $length = $game->matchLength((bool) ($data['extra_time_played'] ?? false));
        $rolling = (bool) $game->rule('rolling_subs');
        $sanctions = collect($game->rule('sanction_types', []))->keyBy('key');
        $timeline = [];

        foreach ($data['events'] as $i => $event) {
            $at = "events.{$i}";
            $minute = $event['minute'] ?? null;
            $player = $event['player_id'] ?? null;
            $related = $event['related_player_id'] ?? null;

            if ($minute !== null && $minute > $length) {
                $errors["{$at}.minute"] = 'comp.error.minute_out_of_range';
            }
            if ($player !== null && ! isset($roles[$player])) {
                $errors["{$at}.player_id"] = 'comp.error.not_in_lineup';
            }
            if ($related !== null && ! isset($roles[$related])) {
                $errors["{$at}.related_player_id"] = 'comp.error.not_in_lineup';
            }

            switch ($event['type']) {
                case 'goal':
                    $kind = $event['subtype'] ?? null;
                    if (! in_array($kind, GameEvent::GOAL_KINDS, true)) {
                        $errors["{$at}.subtype"] = 'comp.error.invalid_subtype';
                    } elseif ($kind === 'opponent_own_goal') {
                        if ($player !== null) {
                            $errors["{$at}.player_id"] ??= 'comp.error.own_goal_no_scorer';
                        }
                    } elseif ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if ($related !== null && $related === $player) {
                        $errors["{$at}.related_player_id"] ??= 'comp.error.assist_is_scorer';
                    }
                    break;

                case 'sanction':
                    $kind = $sanctions->get($event['subtype'] ?? '');
                    if ($kind === null) {
                        $errors["{$at}.subtype"] = 'comp.error.invalid_subtype';
                    }
                    if ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if (($kind['ends_play'] ?? false) && $minute === null) {
                        $errors["{$at}.minute"] ??= 'comp.error.minute_required';
                    }
                    if (($kind['ends_play'] ?? false) && $minute !== null && $player !== null) {
                        $timeline[] = ['minute' => $minute, 'index' => $i, 'kind' => 'off', 'player' => $player];
                    }
                    break;

                case 'substitution':
                    if ($rolling) {
                        $errors["{$at}.type"] = 'comp.error.no_substitutions';
                        break;
                    }
                    if ($player === null) {
                        $errors["{$at}.player_id"] ??= 'comp.error.player_required';
                    }
                    if ($related === null) {
                        $errors["{$at}.related_player_id"] ??= 'comp.error.player_required';
                    }
                    if ($minute === null) {
                        $errors["{$at}.minute"] ??= 'comp.error.minute_required';
                    }
                    if ($player !== null && $related !== null && $minute !== null) {
                        $timeline[] = ['minute' => $minute, 'index' => $i, 'kind' => 'sub', 'in' => $player, 'out' => $related];
                    }
                    break;
            }
        }

        usort($timeline, fn (array $a, array $b) => [$a['minute'], $a['index']] <=> [$b['minute'], $b['index']]);
        $onPitch = array_fill_keys(array_keys(array_filter($roles, fn (string $role) => $role === 'starter')), true);
        $hasPlayed = $onPitch;
        foreach ($timeline as $step) {
            if ($step['kind'] === 'off') {
                unset($onPitch[$step['player']]);
                $hasPlayed[$step['player']] = true;

                continue;
            }
            if (! isset($onPitch[$step['out']])) {
                $errors["events.{$step['index']}.related_player_id"] ??= 'comp.error.not_on_pitch';
            }
            if (isset($hasPlayed[$step['in']]) || ($roles[$step['in']] ?? null) !== 'bench') {
                $errors["events.{$step['index']}.player_id"] ??= 'comp.error.cannot_come_on';
            }
            unset($onPitch[$step['out']]);
            $onPitch[$step['in']] = true;
            $hasPlayed[$step['in']] = true;
        }

        foreach ($data['players'] as $i => $row) {
            if (! isset($roles[$row['player_id']])) {
                $errors["players.{$i}.player_id"] = 'comp.error.not_in_lineup';
            }
            if (($row['minutes_played'] ?? null) !== null && $row['minutes_played'] > $length) {
                $errors["players.{$i}.minutes_played"] = 'comp.error.minute_out_of_range';
            }
            if (($row['sets_played'] ?? null) !== null && $row['sets_played'] > (int) $game->rule('periods_count')) {
                $errors["players.{$i}.sets_played"] = 'comp.error.too_many_sets';
            }
        }
        if (($data['mvp_player_id'] ?? null) !== null && ! isset($roles[$data['mvp_player_id']])) {
            $errors['mvp_player_id'] = 'comp.error.not_in_lineup';
        }

        return $errors;
    }
}
