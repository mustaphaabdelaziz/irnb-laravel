<?php

namespace App\Console\Commands;

use App\Services\Finance\FinanceResetService;
use Illuminate\Console\Command;

class ResetFinance extends Command
{
    protected $signature = 'finance:reset {--force : Skip the typed confirmation}';

    protected $description = 'Delete every subscription, player obligation, transaction, transfer and budget (keeps registers, categories and fiscal years). Backs up the database first.';

    public function handle(FinanceResetService $reset): int
    {
        $this->warn('This permanently deletes ALL financial records:');
        $this->table(['What', 'Rows'], collect($reset->counts())->map(fn ($n, $k) => [$k, $n])->values()->all());
        $this->line('Player debts, register balances and fiscal-year figures are set back to 0.');

        if (! $this->option('force') && $this->ask('Type RESET to continue') !== 'RESET') {
            $this->info('Cancelled. Nothing was changed.');

            return self::FAILURE;
        }

        $backup = $reset->backup();
        $backup
            ? $this->info("Backup written to {$backup}")
            : $this->warn('Not a SQLite database: no automatic backup was made.');

        $reset->reset();
        $this->info('Financial data deleted. You can start fresh.');

        return self::SUCCESS;
    }
}
