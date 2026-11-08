<?php

namespace Tests\Feature\Help;

use App\Models\Role;
use App\Models\User;
use App\Services\Help\HelpArticleParser;
use App\Services\Help\HelpLibrary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HelpPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(HelpLibrary::class, new HelpLibrary(
            new HelpArticleParser,
            base_path('tests/Fixtures/help'),
            base_path('tests/Fixtures/help-shots'),
        ));
    }

    private function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->create(['permissions' => $permissions])->id]);
    }

    #[Test]
    public function the_help_center_lists_only_visible_topics_and_start_here_articles(): void
    {
        $this->actingAs($this->userWith([]))
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Index')
                ->has('topics', 1)
                ->where('topics.0.slug', 'getting-started')
                ->where('topics.0.icon', 'home')
                ->where('topics.0.articles.0.key', 'getting-started/login')
                ->has('startHere', 1)
                ->where('fallback', false));
    }

    #[Test]
    public function a_players_viewer_sees_the_players_topic(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get('/help')
            ->assertInertia(fn (Assert $page) => $page
                ->has('topics', 2)
                ->where('topics.1.articles.0.key', 'players/find-player')
                ->has('startHere', 2));
    }

    #[Test]
    public function an_article_page_has_blocks_related_and_topic(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->get('/help/players/add-player')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Help/Show')
                ->where('article.title', 'كيف أضيف لاعباً جديداً')
                ->where('article.blocks.0.type', 'step')
                ->where('topic.slug', 'players')
                // players/missing does not exist and is dropped.
                ->has('related', 1)
                ->where('related.0.key', 'players/find-player'));
    }

    #[Test]
    public function hidden_or_unknown_articles_are_404(): void
    {
        $user = $this->userWith([]);
        $this->actingAs($user)->get('/help/players/add-player')->assertNotFound();
        $this->actingAs($user)->get('/help/players/nope')->assertNotFound();
    }

    #[Test]
    public function the_panel_lists_articles_for_a_route(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->getJson('/help/panel?route=players.index')
            ->assertOk()
            ->assertJsonPath('articles.0.key', 'players/find-player')
            ->assertJsonPath('articles.1.key', 'players/add-player')
            ->assertJsonCount(2, 'articles');

        $this->actingAs($this->userWith([]))
            ->getJson('/help/panel?route=players.index')
            ->assertJsonCount(0, 'articles');
    }

    #[Test]
    public function the_search_index_carries_keywords_and_step_headings(): void
    {
        $this->actingAs($this->userWith(['players' => ['view']]))
            ->getJson('/help/search-index')
            ->assertOk()
            ->assertJsonCount(3, 'articles')
            ->assertJsonPath('articles.2.keywords', ['تسجيل'])
            ->assertJsonPath('articles.2.headings', ['اضغط «إضافة لاعب»']);
    }

    #[Test]
    public function help_needs_a_login_except_the_login_article(): void
    {
        $this->get('/help')->assertRedirect('/login');
        $this->getJson('/help/panel?route=x')->assertUnauthorized();

        $this->get('/help/login')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/LoginHelp')
                ->where('article.key', 'getting-started/login'));
    }

    #[Test]
    public function pages_share_their_route_name(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/help')
            ->assertInertia(fn (Assert $page) => $page->where('routeName', 'help.index'));
    }
}
