<?php

namespace App\Console\Commands;

use App\Services\Help\HelpLibrary;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class HelpCheck extends Command
{
    protected $signature = 'help:check {--locale=ar}';

    protected $description = 'Validate Help Center articles: syntax, screenshots, ring targets, related links, page route names';

    public function handle(HelpLibrary $help): int
    {
        $locale = (string) $this->option('locale');
        $problems = $help->problems($locale);

        foreach ($help->all($locale) as $article) {
            foreach ($article->pages as $page) {
                if (! Route::has($page)) {
                    $problems[] = "{$article->key()}: page \"{$page}\" is not a route name";
                }
            }
        }

        $count = count($help->all($locale));
        if ($problems === []) {
            $this->info("Help content OK — {$count} article(s).");

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error($problem);
        }
        $this->line(count($problems)." problem(s) in {$count} article(s).");

        return self::FAILURE;
    }
}
