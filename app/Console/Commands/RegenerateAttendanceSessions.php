<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Services\Attendance\GenerationMarks;
use Illuminate\Console\Command;

/**
 * The calendar generates a month's planned sessions once and then skips it
 * until a schedule, closure or session change forgets it. If sessions were
 * changed behind the app's back (direct SQL, a partial restore), this makes
 * the next calendar view generate every month (or one category's) again.
 */
class RegenerateAttendanceSessions extends Command
{
    protected $signature = 'attendance:regenerate {--category= : Only this category id}';

    protected $description = 'Forget which months of planned training sessions were generated, so the next calendar view generates them again (existing sessions are kept).';

    public function handle(): int
    {
        $categoryId = $this->option('category');
        if ($categoryId !== null && ! Category::whereKey((int) $categoryId)->exists()) {
            $this->error("No category with id {$categoryId}.");

            return self::FAILURE;
        }

        $forgotten = GenerationMarks::forgetAll($categoryId === null ? null : (int) $categoryId);
        $this->info("{$forgotten} generated month(s) forgotten; they are generated again when next opened.");

        return self::SUCCESS;
    }
}
