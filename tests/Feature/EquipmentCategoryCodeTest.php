<?php

namespace Tests\Feature;

use App\Models\EquipmentCategory;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EquipmentCategoryCodeTest extends TestCase
{
    #[Test]
    public function it_derives_a_four_char_uppercase_code_from_the_name(): void
    {
        $this->assertSame('BALL', EquipmentCategory::deriveCode('Balls'));
        $this->assertSame('GOAL', EquipmentCategory::deriveCode('Goals & Nets'));
        $this->assertSame('APPA', EquipmentCategory::deriveCode('Apparel'));
    }

    #[Test]
    public function it_falls_back_to_cat_when_no_alphanumerics_remain(): void
    {
        $this->assertSame('CAT', EquipmentCategory::deriveCode('—— ——'));
    }

    #[Test]
    public function it_handles_names_shorter_than_four_chars(): void
    {
        $this->assertSame('AX', EquipmentCategory::deriveCode('Ax'));
    }
}
