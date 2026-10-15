<?php

namespace App\Services\Competitions;

/**
 * Minutes played from the line-up and the timeline (formats without rolling
 * substitutions). A starter plays from 0; a substitute from coming on; either
 * stops when subbed off or sent off, else at the end. Events without a minute
 * are ignored; a minute past the end counts as the end.
 */
final class MinutesCalculator
{
    /**
     * @param  list<int>  $starters
     * @param  list<array{type: string, minute: ?int, player_id: ?int, related_player_id: ?int, ends_play: bool}>  $events
     * @return array{minutes: array<int, int>, appeared: list<int>}
     */
    public static function compute(array $starters, array $events, int $length): array
    {
        $since = array_fill_keys($starters, 0);
        $minutes = array_fill_keys($starters, 0);
        $appeared = array_fill_keys($starters, true);

        $steps = array_values(array_filter($events, fn (array $e) => $e['minute'] !== null
            && ($e['type'] === 'substitution' || ($e['type'] === 'sanction' && $e['ends_play']))));
        usort($steps, fn (array $a, array $b) => $a['minute'] <=> $b['minute']);

        foreach ($steps as $event) {
            $at = min((int) $event['minute'], $length);
            $leaving = $event['type'] === 'substitution' ? $event['related_player_id'] : $event['player_id'];
            if ($leaving !== null && array_key_exists($leaving, $since)) {
                $minutes[$leaving] = ($minutes[$leaving] ?? 0) + $at - $since[$leaving];
                unset($since[$leaving]);
            }
            if ($event['type'] === 'substitution' && $event['player_id'] !== null) {
                $since[$event['player_id']] = $at;
                $minutes[$event['player_id']] ??= 0;
                $appeared[$event['player_id']] = true;
            }
        }

        foreach ($since as $playerId => $from) {
            $minutes[$playerId] += $length - $from;
        }

        return ['minutes' => $minutes, 'appeared' => array_keys($appeared)];
    }
}
