<?php

namespace Tests\Feature\Help;

use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpLibraryTest extends TestCase
{
    use RefreshDatabase;

    private function library(?string $content = null): HelpLibrary
    {
        return new HelpLibrary(
            new HelpArticleParser,
            $content ?? base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        );
    }

    private function userWith(array $permissions): User
    {
        $role = Role::factory()->create(['permissions' => $permissions]);

        return User::factory()->create(['role_id' => $role->id]);
    }

    #[Test]
    public function articles_follow_topic_order_then_the_topic_article_order(): void
    {
        $this->assertSame(
            ['getting-started/login', 'players/find-player', 'players/add-player'],
            array_keys($this->library()->all('ar')),
        );
    }

    #[Test]
    public function figures_get_image_urls_aspect_and_boxes_from_the_shot_json(): void
    {
        $figure = $this->library()->all('ar')['players/add-player']->blocks[0]['figures'][0];

        $this->assertMatchesRegularExpression('#^/help-shots/ar/light/players-index\.webp\?v=\d+$#', $figure['light']);
        $this->assertNull($figure['dark']);
        $this->assertSame(1.6, $figure['aspect']);
        // Unknown ring names are dropped at runtime (help:check reports them).
        $this->assertSame([['name' => 'add-player', 'x' => 71.2, 'y' => 9.5, 'w' => 8.4, 'h' => 4.6]], $figure['rings']);
        $this->assertSame(['x' => 71.2, 'y' => 9.5, 'w' => 8.4, 'h' => 4.6], $figure['zoomBox']);
    }

    #[Test]
    public function a_figure_without_any_screenshot_has_null_images(): void
    {
        $figure = $this->library()->all('ar')['getting-started/login']->blocks[0]['figures'][0];

        $this->assertNull($figure['light']);
        $this->assertNull($figure['dark']);
        $this->assertSame([], $figure['rings']);
        $this->assertNull($figure['zoomBox']);
    }

    #[Test]
    public function articles_of_a_module_the_user_cannot_view_are_hidden(): void
    {
        $lib = $this->library();

        $this->assertSame(['getting-started/login'], array_keys($lib->visibleFor($this->userWith(['subscriptions' => ['view']]), 'ar')));
        $this->assertCount(3, $lib->visibleFor($this->userWith(['players' => ['view']]), 'ar'));
        $this->assertCount(3, $lib->visibleFor(User::factory()->admin()->create(), 'ar'));
        $this->assertNull($lib->find('players/add-player', $this->userWith([]), 'ar'));
        $this->assertNotNull($lib->article('players/add-player', 'ar'));
    }

    #[Test]
    public function for_route_lists_visible_articles_declaring_that_page(): void
    {
        $lib = $this->library();
        $viewer = $this->userWith(['players' => ['view']]);

        $this->assertSame(
            ['players/find-player', 'players/add-player'],
            array_map(fn ($a) => $a->key(), $lib->forRoute('players.index', $viewer, 'ar')),
        );
        $this->assertSame([], $lib->forRoute('players.index', $this->userWith([]), 'ar'));
        $this->assertSame([], $lib->forRoute('dashboard', $viewer, 'ar'));
    }

    #[Test]
    public function a_locale_without_articles_falls_back_to_arabic(): void
    {
        $lib = $this->library();

        $this->assertSame('ar', $lib->contentLocale('fr'));
        $this->assertTrue($lib->isFallback('fr'));
        $this->assertFalse($lib->isFallback('ar'));
        $this->assertCount(3, $lib->all('fr'));
    }

    #[Test]
    public function a_broken_article_is_skipped_at_runtime_and_reported_by_problems(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help'), $dir);
        File::put("{$dir}/ar/players/broken.md", "no front matter\n");

        try {
            $lib = $this->library($dir);

            $this->assertArrayNotHasKey('players/broken', $lib->all('ar'));
            $problems = implode("\n", $lib->problems('ar'));
            $this->assertStringContainsString('players/broken: missing front matter', $problems);
            $this->assertStringContainsString('getting-started/login: figure "login" has no light screenshot', $problems);
            $this->assertStringContainsString('players/add-player: figure "players-index" has no dark screenshot', $problems);
            $this->assertStringContainsString('players/add-player: figure "players-index" rings unknown target "ghost"', $problems);
            $this->assertStringContainsString('players/add-player: related article "players/missing" does not exist', $problems);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[Test]
    public function the_container_builds_the_library_on_the_real_content_folder(): void
    {
        $this->assertInstanceOf(HelpLibrary::class, app(HelpLibrary::class));
        $this->assertSame(app(HelpLibrary::class), app(HelpLibrary::class));
    }
}
