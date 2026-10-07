<?php

namespace Tests\Feature\Console;

use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ImportMongoJsonDataTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_imports_transactions_and_player_subscriptions_with_legacy_id_mapping(): void
    {
        $directory = storage_path('framework/testing/mongo-import-'.uniqid('', true));
        File::ensureDirectoryExists($directory);

        try {
            $this->writeImportFixtures($directory);

            $this->artisan('irnb:import-mongo-json', [
                'path' => $directory,
            ])->assertSuccessful();

            $player = Player::query()->where('membership_id', '2024000001')->firstOrFail();
            $user = User::query()->where('email', 'manager@example.com')->firstOrFail();
            $transaction = Transaction::query()->where('description', 'Annual membership charge')->firstOrFail();
            $playerSubscription = PlayerSubscription::query()->firstOrFail();

            $this->assertSame('Player', $transaction->related_entity_type);
            $this->assertSame($player->id, $transaction->related_entity_id);
            $this->assertSame($user->id, $transaction->recorded_by_user_id);
            $this->assertSame('Partial', $transaction->status);
            $this->assertSame(2024, $transaction->fiscal_year);

            $this->assertSame($player->id, $playerSubscription->player_id);
            $this->assertSame($transaction->id, $playerSubscription->transaction_id);
            $this->assertSame('student', $playerSubscription->status_at_time);
            $this->assertSame(2000.0, (float) $playerSubscription->amount_owed);
            $this->assertSame(1000.0, (float) $playerSubscription->amount_paid);
        } finally {
            File::deleteDirectory($directory);
        }
    }

    private function writeImportFixtures(string $directory): void
    {
        $this->writeJson($directory.'/categories.json', [
            [
                '_id' => ['$oid' => 'cat-1'],
                'name' => 'U17',
                'description' => 'Under 17',
            ],
        ]);

        $this->writeJson($directory.'/jobs.json', [
            [
                '_id' => ['$oid' => 'job-1'],
                'name' => 'Student',
                'description' => 'Student job',
            ],
        ]);

        $this->writeJson($directory.'/positions.json', [
            [
                '_id' => ['$oid' => 'pos-1'],
                'name' => 'Forward',
                'abbreviation' => 'FWD',
            ],
        ]);

        $this->writeJson($directory.'/subscriptions.json', [
            [
                '_id' => ['$oid' => 'sub-1'],
                'name' => 'Annual Membership',
                'year' => 2024,
                'amount' => [
                    'student' => 2000,
                    'worker' => 3000,
                ],
                'isMandatory' => true,
                'isActive' => true,
                'categoryPlayer' => [
                    ['$oid' => 'cat-1'],
                ],
            ],
        ]);

        $this->writeJson($directory.'/users.json', [
            [
                '_id' => ['$oid' => 'user-1'],
                'email' => 'manager@example.com',
                'firstname' => 'Club',
                'lastname' => 'Manager',
                'isUser' => true,
                'isActive' => true,
                'approved' => true,
                'phones' => ['0555000000'],
                'privileges' => ['admin'],
                'job' => ['$oid' => 'job-1'],
                'category' => ['$oid' => 'cat-1'],
            ],
        ]);

        $this->writeJson($directory.'/players.json', [
            [
                '_id' => ['$oid' => 'player-1'],
                'membershipID' => '2024000001',
                'firstname' => 'Samir',
                'lastname' => 'Benali',
                'isStudent' => true,
                'joinYear' => 2024,
                'job' => ['$oid' => 'job-1'],
                'category' => ['$oid' => 'cat-1'],
                'position' => ['$oid' => 'pos-1'],
                'phones' => ['0666000000'],
            ],
        ]);

        $this->writeJson($directory.'/transactions.json', [
            [
                '_id' => ['$oid' => 'txn-1'],
                'amount' => 1000,
                'transactionDate' => ['$date' => '2024-02-01T10:00:00.000Z'],
                'transactionType' => 'income',
                'category' => 'subscription',
                'subCategory' => 'annual',
                'payment' => [
                    'method' => 'cash',
                    'account' => '/',
                ],
                'relatedTo' => [
                    'entityType' => 'Player',
                    'entityId' => ['$oid' => 'player-1'],
                ],
                'description' => 'Annual membership charge',
                'recordedBy' => ['$oid' => 'user-1'],
                'status' => 'Partial',
                'year' => 2024,
            ],
        ]);

        $this->writeJson($directory.'/playerSubscriptions.json', [
            [
                '_id' => ['$oid' => 'ps-1'],
                'player' => ['$oid' => 'player-1'],
                'subscription' => ['$oid' => 'sub-1'],
                'transaction' => ['$oid' => 'txn-1'],
                'year' => 2024,
                'statusAtTime' => 'student',
                'amountOwed' => 2000,
                'amountPaid' => 1000,
                'isLegacy' => false,
                'dueDate' => ['$date' => '2024-03-01T00:00:00.000Z'],
            ],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
