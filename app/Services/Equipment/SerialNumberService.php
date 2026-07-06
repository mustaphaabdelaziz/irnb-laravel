<?php

namespace App\Services\Equipment;

use App\Models\EquipmentCatalog;
use App\Models\EquipmentCategory;
use App\Models\EquipmentItem;
use App\Models\WebsiteConfig;
use Carbon\Carbon;
use Illuminate\Database\QueryException;

class SerialNumberService
{
    /**
     * Build the serial for an item ({CLUB}-{YYYY}-{CODE}-{NNNNN}).
     * Does not persist. The item must have catalog_id and purchase_date set.
     */
    public function generate(EquipmentItem $item): string
    {
        $prefix = sprintf(
            '%s-%s-%s-',
            $this->clubAbbreviation(),
            Carbon::parse($item->purchase_date)->format('Y'),
            $this->categoryCode($item),
        );

        $next = $this->nextSequence($prefix);
        $width = max(5, strlen((string) $next));

        return $prefix.str_pad((string) $next, $width, '0', STR_PAD_LEFT);
    }

    /**
     * Assign a freshly generated serial and save. Retries on the rare
     * unique-constraint collision (concurrent insert on the web path).
     */
    public function assign(EquipmentItem $item): void
    {
        for ($attempt = 1; ; $attempt++) {
            $item->unique_identifier = $this->generate($item);

            try {
                $item->save();

                return;
            } catch (QueryException $e) {
                if ($attempt >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** The serial the next item for this catalog + purchase date would get. */
    public function previewNext(int $catalogId, string $purchaseDate): string
    {
        return $this->generate(new EquipmentItem([
            'catalog_id' => $catalogId,
            'purchase_date' => $purchaseDate,
        ]));
    }

    private function clubAbbreviation(): string
    {
        $short = WebsiteConfig::singleton()->club_short_name;
        $abbr = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string) $short) ?? '');

        return $abbr === '' ? 'CLUB' : $abbr;
    }

    private function categoryCode(EquipmentItem $item): string
    {
        /** @var EquipmentCatalog|null $catalog */
        $catalog = $item->catalog;
        $categoryName = $catalog?->category;

        if (! $categoryName) {
            return 'CAT';
        }

        $code = EquipmentCategory::where('name', $categoryName)->value('code');

        return strtoupper($code ?: EquipmentCategory::deriveCode($categoryName));
    }

    private function nextSequence(string $prefix): int
    {
        $max = 0;

        foreach (EquipmentItem::where('unique_identifier', 'like', $prefix.'%')->pluck('unique_identifier') as $uid) {
            $tail = substr((string) $uid, strlen($prefix));
            if (ctype_digit($tail)) {
                $max = max($max, (int) $tail);
            }
        }

        return $max + 1;
    }
}
