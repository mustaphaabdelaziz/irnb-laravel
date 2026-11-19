<?php

namespace Tests\Unit;

use App\Models\InventorySessionItem;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class InventorySessionItemFoundUnitsTest extends TestCase
{
    private function line(array $attrs): InventorySessionItem
    {
        return new InventorySessionItem($attrs + ['expected_quantity' => 50]);
    }

    #[Test]
    public function an_uncounted_line_has_no_found_units(): void
    {
        $this->assertSame(0, $this->line(['counted' => false, 'found' => true, 'found_quantity' => 10])->found_units);
    }

    #[Test]
    public function a_line_not_found_has_no_found_units(): void
    {
        $this->assertSame(0, $this->line(['counted' => true, 'found' => false, 'found_quantity' => 10])->found_units);
    }

    #[Test]
    public function a_found_line_without_a_quantity_means_every_expected_unit(): void
    {
        $this->assertSame(50, $this->line(['counted' => true, 'found' => true, 'found_quantity' => null])->found_units);
    }

    #[Test]
    public function a_found_line_with_a_quantity_uses_it(): void
    {
        $this->assertSame(47, $this->line(['counted' => true, 'found' => true, 'found_quantity' => 47])->found_units);
    }

    #[Test]
    public function found_units_travels_with_the_serialised_line(): void
    {
        $this->assertSame(50, $this->line(['counted' => true, 'found' => true])->toArray()['found_units']);
    }
}
