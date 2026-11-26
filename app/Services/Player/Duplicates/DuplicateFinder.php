<?php

namespace App\Services\Player\Duplicates;

use App\Models\PlayerDuplicateDecision;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Possible duplicate pairs, computed live on every load (owner 2026-11-26):
 * only players sharing a name, birthdate, phone or father are compared, never
 * every player with every other.
 */
final class DuplicateFinder
{
    /** A bucket bigger than this is a common value (a frequent father's name), not a lead. */
    private const MAX_BUCKET = 400;

    /**
     * Pending pairs: at or above the floor and $minScore, meeting every
     * criterion, with no decision recorded.
     *
     * @param  list<string>  $criteria
     * @return Collection<int, array{a:int, b:int, score:int, reasons:list<string>}>
     */
    public function pending(array $criteria = [], int $minScore = 0): Collection
    {
        $profiles = $this->profiles();
        $decided = PlayerDuplicateDecision::query()
            ->whereNotNull('player_a_id')
            ->whereNotNull('player_b_id')
            ->get(['player_a_id', 'player_b_id'])
            ->mapWithKeys(fn (PlayerDuplicateDecision $d) => [$d->player_a_id.'-'.$d->player_b_id => true]);
        $floor = max(DuplicateScorer::MIN_SCORE, $minScore);

        $pairs = [];
        foreach ($this->candidates($profiles) as $key => [$a, $b]) {
            if (isset($decided[$key])) {
                continue;
            }
            $result = DuplicateScorer::score($profiles[$a], $profiles[$b]);
            if ($result['score'] < $floor || ! DuplicateScorer::meets($result['reasons'], $criteria)) {
                continue;
            }
            $pairs[] = ['a' => $a, 'b' => $b, ...$result];
        }

        usort($pairs, fn (array $x, array $y) => [$y['score'], $x['a'], $x['b']] <=> [$x['score'], $y['a'], $y['b']]);

        return collect($pairs);
    }

    /** @return array{score:int, reasons:list<string>} */
    public function scorePair(int $a, int $b): array
    {
        $rows = $this->rows()->whereIn('id', [$a, $b])->get()->keyBy('id');
        if (! $rows->has($a) || ! $rows->has($b)) {
            return ['score' => 0, 'reasons' => []];
        }

        return DuplicateScorer::score(
            DuplicateScorer::profile((array) $rows[$a]),
            DuplicateScorer::profile((array) $rows[$b]),
        );
    }

    private function rows(): Builder
    {
        return DB::table('players')->select(['id', 'firstname', 'lastname', 'father', 'birthdate', 'gender', 'phones']);
    }

    /** @return array<int, array> profiles keyed by player id */
    private function profiles(): array
    {
        $profiles = [];
        foreach ($this->rows()->orderBy('id')->cursor() as $row) {
            $profiles[(int) $row->id] = DuplicateScorer::profile((array) $row);
        }

        return $profiles;
    }

    /** @return array<string, array{0:int, 1:int}> "a-b" => [a, b] with a < b */
    private function candidates(array $profiles): array
    {
        $buckets = [];
        foreach ($profiles as $id => $p) {
            if ($p['first'] !== '' && $p['last'] !== '') {
                $names = [$p['first'], $p['last']];
                sort($names);
                $buckets['n:'.implode('|', $names)][] = $id; // same or swapped name
            }
            if ($p['birthdate'] !== null) {
                $buckets['b:'.$p['birthdate']][] = $id;
            }
            foreach ($p['phones'] as $phone) {
                $buckets['p:'.$phone][] = $id;
            }
            if ($p['father'] !== '') {
                $buckets['f:'.$p['father']][] = $id;
            }
        }

        $pairs = [];
        foreach ($buckets as $ids) {
            $ids = array_values(array_unique($ids));
            $count = count($ids);
            if ($count < 2 || $count > self::MAX_BUCKET) {
                continue;
            }
            for ($i = 0; $i < $count; $i++) {
                for ($j = $i + 1; $j < $count; $j++) {
                    [$a, $b] = $ids[$i] < $ids[$j] ? [$ids[$i], $ids[$j]] : [$ids[$j], $ids[$i]];
                    $pairs[$a.'-'.$b] = [$a, $b];
                }
            }
        }

        return $pairs;
    }
}
