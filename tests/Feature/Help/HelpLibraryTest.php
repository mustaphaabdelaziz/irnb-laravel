<?php

namespace Tests\Feature\Help;

use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpArticle;
use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    /** A copy of the fixture shots with players-index.json replaced by $json. */
    private function withShotJson(string $json, callable $test): void
    {
        $shots = storage_path('framework/testing/help-shots-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help-shots'), $shots);
        File::put("{$shots}/ar/players-index.json", $json);

        try {
            $test(new HelpLibrary(new HelpArticleParser, base_path('tests/Fixtures/help'), $shots));
        } finally {
            File::deleteDirectory($shots);
        }
    }

    #[Test]
    public function unreadable_or_non_object_shot_json_never_breaks_a_page(): void
    {
        foreach (['{not json', '"just a string"', '42', '{"width":1280,"height":800,"targets":"nope"}'] as $json) {
            $this->withShotJson($json, function (HelpLibrary $lib) use ($json): void {
                $figure = $lib->all('ar')['players/add-player']->blocks[0]['figures'][0];

                $this->assertSame([], $figure['rings'], $json);
                $this->assertNull($figure['zoomBox'], $json);
                $this->assertStringContainsString(
                    'players/add-player: figure "players-index" has invalid screenshot JSON',
                    implode("\n", $lib->problems('ar')),
                    $json,
                );
            });
        }
    }

    #[Test]
    public function malformed_targets_are_skipped_and_reported(): void
    {
        $json = json_encode(['width' => 1280, 'height' => 800, 'targets' => [
            'add-player' => ['x' => 'left', 'y' => 9.5, 'w' => 8.4],
            'ghost' => 'not a box',
        ]]);

        $this->withShotJson($json, function (HelpLibrary $lib): void {
            $figure = $lib->all('ar')['players/add-player']->blocks[0]['figures'][0];

            $this->assertSame([], $figure['rings']);
            $this->assertNull($figure['zoomBox']);
            $problems = implode("\n", $lib->problems('ar'));
            $this->assertStringContainsString('players/add-player: figure "players-index" target "add-player" is malformed', $problems);
            $this->assertStringContainsString('players/add-player: figure "players-index" target "ghost" is malformed', $problems);
            $this->assertStringNotContainsString('has invalid screenshot JSON', $problems);
        });
    }

    #[Test]
    public function an_edited_article_shows_up_on_the_next_request(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help'), $dir);

        try {
            $first = $this->library($dir);
            $this->assertSame('كيف أضيف لاعباً جديداً', $first->all('ar')['players/add-player']->title);

            $file = "{$dir}/ar/players/add-player.md";
            File::put($file, str_replace('title: كيف أضيف لاعباً جديداً', 'title: عنوان جديد', File::get($file)));
            touch($file, time() + 60);
            clearstatcache();

            // One instance per request: the same request keeps its memo, the next one re-reads.
            $this->assertSame('كيف أضيف لاعباً جديداً', $first->all('ar')['players/add-player']->title);
            $this->assertSame('عنوان جديد', $this->library($dir)->all('ar')['players/add-player']->title);
        } finally {
            File::deleteDirectory($dir);
        }
    }

    #[Test]
    public function the_library_works_on_a_cache_store_that_refuses_objects(): void
    {
        $path = storage_path('framework/testing/cache-'.uniqid());
        config([
            'cache.default' => 'file',
            'cache.serializable_classes' => false,
            'cache.prefix' => 'helptest-'.uniqid(),
            'cache.stores.file.path' => $path,
        ]);
        Cache::purge('file');

        try {
            $first = $this->library()->all('ar');
            // A fresh instance has no memo, so this really reads back from the file store.
            $second = $this->library()->all('ar');

            $this->assertSame(array_keys($first), array_keys($second));
            foreach ($second as $key => $article) {
                $this->assertInstanceOf(HelpArticle::class, $article);
                $this->assertSame($first[$key]->blocks, $article->blocks);
            }
        } finally {
            Cache::purge('file');
            File::deleteDirectory($path);
        }
    }

    #[Test]
    public function superadmin_articles_are_visible_to_superadmins_only(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        File::copyDirectory(base_path('tests/Fixtures/help'), $dir);
        File::put("{$dir}/ar/players/roles.md", "---\ntitle: الأدوار\nmodule: superadmin\n---\nنص\n");

        try {
            $lib = $this->library($dir);
            $everything = collect(Role::MODULES)
                ->mapWithKeys(fn (string $m) => [$m => ['view', 'add', 'edit', 'delete']])
                ->all();

            $this->assertArrayNotHasKey('players/roles', $lib->visibleFor($this->userWith($everything), 'ar'));
            $this->assertArrayNotHasKey('players/roles', $lib->visibleFor(User::factory()->admin()->create(), 'ar'));

            $super = User::factory()->create(['privileges' => ['superadmin']]);
            $this->assertArrayHasKey('players/roles', $lib->visibleFor($super, 'ar'));
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
