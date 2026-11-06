<?php

namespace Tests\Feature\Registrations;

use App\Models\Branch;
use App\Models\Category;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CategoryAdoptionTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function the_first_branch_takes_every_unowned_category_and_the_second_takes_none(): void
    {
        $senior = Category::create(['name' => 'Senior']);

        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);
        $swim = Branch::create(['name' => 'Swimming', 'code' => 'SW']);

        $this->assertSame($foot->id, $senior->refresh()->branch_id);
        $this->assertSame(0, Category::where('branch_id', $swim->id)->count());
    }

    #[Test]
    public function with_no_branches_categories_have_no_owner(): void
    {
        (new CategorySeeder)->run();

        $this->assertSame(5, Category::whereNull('branch_id')->count());
    }

    #[Test]
    public function seeding_after_a_branch_exists_gives_new_rows_to_the_main_branch(): void
    {
        $foot = Branch::create(['name' => 'Football', 'code' => 'FB']);

        (new CategorySeeder)->run();

        $this->assertSame(5, Category::where('branch_id', $foot->id)->count());
    }

    #[Test]
    public function seeding_twice_adds_nothing(): void
    {
        Branch::create(['name' => 'Football', 'code' => 'FB']);

        (new CategorySeeder)->run();
        (new CategorySeeder)->run();

        $this->assertSame(5, Category::count());
    }
}
