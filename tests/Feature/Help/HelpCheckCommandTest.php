<?php

namespace Tests\Feature\Help;

use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpCheckCommandTest extends TestCase
{
    #[Test]
    public function it_lists_problems_and_fails(): void
    {
        $this->app->instance(HelpLibrary::class, new HelpLibrary(
            new HelpArticleParser,
            base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        ));

        $this->artisan('help:check')
            ->expectsOutputToContain('players/add-player: related article "players/missing" does not exist')
            ->expectsOutputToContain('getting-started/login: figure "login" has no light screenshot')
            ->assertFailed();
    }

    #[Test]
    public function it_reports_pages_that_are_not_route_names(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        mkdir("{$dir}/ar/players", 0777, true);
        file_put_contents("{$dir}/topics.php", "<?php return ['topics' => ['players' => ['icon' => 'players', 'module' => 'players', 'articles' => []]], 'start_here' => []];");
        file_put_contents("{$dir}/ar/players/a.md", "---\ntitle: A\npages: [players.index, players.nope]\n---\nنص\n");

        try {
            $this->app->instance(HelpLibrary::class, new HelpLibrary(new HelpArticleParser, $dir, $dir.'/shots'));

            $this->artisan('help:check')
                ->expectsOutputToContain('players/a: page "players.nope" is not a route name')
                ->assertFailed();
        } finally {
            @unlink("{$dir}/ar/players/a.md");
            @unlink("{$dir}/topics.php");
            @rmdir("{$dir}/ar/players");
            @rmdir("{$dir}/ar");
            @rmdir($dir);
        }
    }

    #[Test]
    public function clean_content_passes(): void
    {
        $dir = storage_path('framework/testing/help-'.uniqid());
        mkdir("{$dir}/ar/players", 0777, true);
        file_put_contents("{$dir}/topics.php", "<?php return ['topics' => ['players' => ['icon' => 'players', 'module' => 'players', 'articles' => []]], 'start_here' => ['players/a']];");
        file_put_contents("{$dir}/ar/players/a.md", "---\ntitle: A\npages: [players.index]\n---\nنص\n");

        try {
            $this->app->instance(HelpLibrary::class, new HelpLibrary(new HelpArticleParser, $dir, $dir.'/shots'));

            $this->artisan('help:check')->expectsOutputToContain('1 article')->assertSuccessful();
        } finally {
            @unlink("{$dir}/ar/players/a.md");
            @unlink("{$dir}/topics.php");
            @rmdir("{$dir}/ar/players");
            @rmdir("{$dir}/ar");
            @rmdir($dir);
        }
    }
}
