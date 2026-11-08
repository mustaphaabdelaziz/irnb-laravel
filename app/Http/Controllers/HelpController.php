<?php

namespace App\Http\Controllers;

use App\Services\Help\HelpArticle;
use App\Services\Help\HelpLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The in-app manual. Every signed-in user may open it; articles are filtered per module. */
class HelpController extends Controller
{
    public function __construct(private readonly HelpLibrary $help) {}

    public function index(Request $request): Response
    {
        $locale = app()->getLocale();
        $visible = $this->help->visibleFor($request->user(), $locale);

        $topics = [];
        foreach ($this->help->topics() as $slug => $topic) {
            $articles = array_values(array_map(
                fn (HelpArticle $a): array => $a->summary() + ['keywords' => $a->keywords, 'headings' => $a->headings()],
                array_filter($visible, fn (HelpArticle $a): bool => $a->topic === $slug),
            ));
            if ($articles !== []) {
                $topics[] = ['slug' => $slug, 'icon' => $topic['icon'], 'articles' => $articles];
            }
        }

        return Inertia::render('Help/Index', [
            'topics' => $topics,
            'startHere' => array_values(array_filter(array_map(
                fn (string $key): ?array => isset($visible[$key]) ? $visible[$key]->summary() : null,
                $this->help->startHere(),
            ))),
            'fallback' => $this->help->isFallback($locale),
            'contentLocale' => $this->help->contentLocale($locale),
        ]);
    }

    public function show(Request $request, string $topic, string $slug): Response
    {
        $locale = app()->getLocale();
        $article = $this->help->find("{$topic}/{$slug}", $request->user(), $locale);
        abort_if($article === null, 404);

        $visible = $this->help->visibleFor($request->user(), $locale);

        return Inertia::render('Help/Show', [
            'article' => $article->summary() + ['blocks' => $article->blocks, 'keywords' => $article->keywords],
            'related' => array_values(array_filter(array_map(
                fn (string $key): ?array => isset($visible[$key]) ? $visible[$key]->summary() : null,
                $article->related,
            ))),
            'topic' => ['slug' => $topic, 'icon' => $this->help->topics()[$topic]['icon'] ?? 'document'],
            'fallback' => $this->help->isFallback($locale),
            'contentLocale' => $this->help->contentLocale($locale),
        ]);
    }

    public function panel(Request $request): JsonResponse
    {
        $articles = $this->help->forRoute((string) $request->query('route', ''), $request->user(), app()->getLocale());

        return response()->json(['articles' => array_map(fn (HelpArticle $a): array => $a->summary(), $articles)]);
    }

    public function searchIndex(Request $request): JsonResponse
    {
        $articles = $this->help->visibleFor($request->user(), app()->getLocale());

        return response()->json(['articles' => array_values(array_map(
            fn (HelpArticle $a): array => $a->summary() + ['keywords' => $a->keywords, 'headings' => $a->headings()],
            $articles,
        ))]);
    }

    /** Before signing in nobody can open /help, so the login article has its own page. */
    public function login(): Response
    {
        $locale = app()->getLocale();
        $article = $this->help->article('getting-started/login', $locale);
        abort_if($article === null, 404);

        return Inertia::render('Auth/LoginHelp', [
            'article' => $article->summary() + ['blocks' => $article->blocks],
            'contentLocale' => $this->help->contentLocale($locale),
        ]);
    }
}
