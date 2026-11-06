<?php

namespace Tests\Feature\Registrations;

use App\Models\Category;
use App\Support\CategoryLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\RegistrationFixtures;
use Tests\TestCase;

class CategoryLabelsTest extends TestCase
{
    use RefreshDatabase, RegistrationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->twoSportClub();
    }

    #[Test]
    public function the_sport_is_added_only_when_the_name_repeats(): void
    {
        $swimSenior = Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);

        $labels = CategoryLabels::make();

        $this->assertSame('Senior · Football', $labels->of($this->senior->id));
        $this->assertSame('Senior · Swimming', $labels->of($swimSenior->id));
        $this->assertSame('U15', $labels->of($this->u15->id));
        $this->assertNull($labels->of(null));
        $this->assertNull($labels->of(999));
    }

    #[Test]
    public function it_uses_the_current_locale(): void
    {
        $this->foot->update(['name_fr' => 'Foot']);
        $this->senior->update(['name_fr' => 'Séniors']);
        Category::create(['name' => 'Senior', 'branch_id' => $this->swim->id]);
        app()->setLocale('fr');

        $this->assertSame('Séniors · Foot', CategoryLabels::make()->of($this->senior->id));
    }

    #[Test]
    public function an_unowned_repeat_has_no_sport_to_add(): void
    {
        $open = Category::create(['name' => 'Senior']);

        $this->assertSame('Senior', CategoryLabels::make()->of($open->id));
    }

    #[Test]
    public function options_are_ordered_by_name_for_a_filter(): void
    {
        $options = CategoryLabels::make()->options();

        $this->assertSame(['Senior', 'U15'], array_column($options, 'label'));
        $this->assertSame($this->senior->id, $options[0]['value']);
    }
}
