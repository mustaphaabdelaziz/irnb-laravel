<?php

namespace App\Services\LiteraryReport\Sections;

use Carbon\CarbonImmutable;

/** One computed chapter of the literary report: plain data for its Blade partial. */
interface Section
{
    /**
     * @param  CarbonImmutable  $from  first day of the period (inclusive)
     * @param  CarbonImmutable  $to  last day of the period (inclusive)
     * @return array<string, mixed>
     */
    public function build(CarbonImmutable $from, CarbonImmutable $to): array;
}
