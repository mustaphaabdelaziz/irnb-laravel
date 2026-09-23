<?php

namespace App\Services\Lookup;

use App\Models\MemberJob;
use App\Support\NameNormalizer;
use Illuminate\Support\Collection;

/**
 * Keeps the job list from filling up with the same job spelled three ways.
 *
 * An exact match (after normalising) is refused outright. A near match is only
 * reported: "Informaticien" and "Informaticienne" are genuinely different
 * jobs, and the club decides, not the app.
 */
class JobDuplicateFinder
{
    /** Names in every language, as submitted: ['name' => …, 'name_ar' => …, …]. */
    public function exact(array $names, ?int $ignoreId = null): ?MemberJob
    {
        $keys = $this->keys($names);

        if ($keys === []) {
            return null;
        }

        return $this->candidates($ignoreId)
            ->first(fn (MemberJob $job) => array_intersect($keys, $this->keys($job->only(['name', 'name_ar', 'name_fr', 'name_en']))) !== []);
    }

    /** @return Collection<int, MemberJob> */
    public function similar(array $names, ?int $ignoreId = null): Collection
    {
        $keys = $this->keys($names);

        if ($keys === []) {
            return collect();
        }

        return $this->candidates($ignoreId)
            ->filter(function (MemberJob $job) use ($keys) {
                foreach ($this->keys($job->only(['name', 'name_ar', 'name_fr', 'name_en'])) as $existing) {
                    foreach ($keys as $key) {
                        if ($existing === $key) {
                            continue; // exact() owns this case
                        }

                        if (str_contains($existing, $key) || str_contains($key, $existing)) {
                            return true;
                        }

                        // levenshtein() counts bytes, not characters. Arabic is
                        // encoded 2 bytes/char in UTF-8, so a "distance <= 2"
                        // threshold only tolerates ~1 changed Arabic letter —
                        // stricter there than for Latin names. Acceptable: an
                        // Arabic near-duplicate still usually differs by more
                        // than that in raw bytes, so real near-misses still
                        // surface. levenshtein() also refuses (returns -1 or
                        // throws) past 255 bytes per argument; job names never
                        // approach that, so no length guard is needed here.
                        if (mb_strlen($key) > 4 && levenshtein($key, $existing) <= 2) {
                            return true;
                        }
                    }
                }

                return false;
            })
            ->values();
    }

    /** @return Collection<int, MemberJob> */
    private function candidates(?int $ignoreId): Collection
    {
        return MemberJob::query()
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->get();
    }

    /** @return list<string> */
    private function keys(array $names): array
    {
        return collect($names)
            ->map(fn ($value) => NameNormalizer::key(is_string($value) ? $value : null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
