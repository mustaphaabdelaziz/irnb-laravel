<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Country;
use App\Models\CountryState;
use App\Models\CountryStateCommune;
use App\Models\EquipmentCatalog;
use App\Models\EquipmentHistory;
use App\Models\EquipmentItem;
use App\Models\MemberJob;
use App\Models\Player;
use App\Models\PlayerSubscription;
use App\Models\Position;
use App\Models\Subscription;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WebsiteConfig;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Throwable;

#[Signature('irnb:import-mongo-json {path? : Directory containing exported collections as JSON files} {--lookups-only : Import only lookup/config tables}')]
#[Description('Import MongoDB JSON exports into the IRNB SQL schema')]
class ImportMongoJsonData extends Command
{
    /** @var array<string, int> */
    private array $categoryMap = [];

    /** @var array<string, int> */
    private array $jobMap = [];

    /** @var array<string, int> */
    private array $positionMap = [];

    /** @var array<string, int> */
    private array $subscriptionMap = [];

    /** @var array<string, int> */
    private array $playerMap = [];

    /** @var array<string, int> */
    private array $userMap = [];

    /** @var array<string, int> */
    private array $transactionMap = [];

    /** @var array<string, int> */
    private array $equipmentCatalogMap = [];

    /** @var array<string, int> */
    private array $equipmentItemMap = [];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $basePath = (string) ($this->argument('path') ?: storage_path('app/mongo-export'));

        if (! File::isDirectory($basePath)) {
            $this->error('Import directory not found: '.$basePath);

            return self::FAILURE;
        }

        $this->info('Starting Mongo JSON import from: '.$basePath);

        try {
            DB::beginTransaction();

            $this->importCountries($basePath);
            $this->importCategories($basePath);
            $this->importJobs($basePath);
            $this->importPositions($basePath);
            $this->importSubscriptions($basePath);

            if (! $this->option('lookups-only')) {
                $this->importUsers($basePath);
                $this->importPlayers($basePath);
                $this->importTransactions($basePath);
                $this->importPlayerSubscriptions($basePath);
                $this->importEquipmentCatalogs($basePath);
                $this->importEquipmentItems($basePath);
                $this->importEquipmentHistories($basePath);
                $this->importWebsiteConfig($basePath);
            }

            DB::commit();
            $this->info('Import completed successfully.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            DB::rollBack();
            $this->error('Import failed: '.$exception->getMessage());
            $this->line($exception->getTraceAsString());

            return self::FAILURE;
        }
    }

    private function importCountries(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'countries.json');
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['country'] ?? $row['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            $country = Country::updateOrCreate(
                ['name' => $name],
                [
                    'code' => $this->nullableString($row['code'] ?? null),
                    'flag' => $this->nullableString($row['flag'] ?? null),
                ]
            );

            $states = $this->ensureArray($row['states'] ?? []);
            foreach ($states as $state) {
                if (! is_array($state)) {
                    continue;
                }

                $externalId = isset($state['id']) ? (int) $state['id'] : null;
                $stateName = trim((string) ($state['name'] ?? ''));

                if ($stateName === '') {
                    continue;
                }

                $countryState = CountryState::updateOrCreate(
                    [
                        'country_id' => $country->id,
                        'external_id' => $externalId,
                    ],
                    [
                        'name' => $stateName,
                        'ar_name' => $this->nullableString($state['ar_name'] ?? null),
                        'longitude' => $this->nullableString($state['longitude'] ?? null),
                        'latitude' => $this->nullableString($state['latitude'] ?? null),
                    ]
                );

                foreach ($this->ensureArray($state['communes'] ?? []) as $commune) {
                    $communeName = trim((string) $commune);
                    if ($communeName === '') {
                        continue;
                    }

                    CountryStateCommune::updateOrCreate([
                        'country_state_id' => $countryState->id,
                        'name' => $communeName,
                    ]);
                }
            }

            $count++;
        }

        $this->info('Countries imported: '.$count);
    }

    private function importCategories(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'categories.json');
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $category = Category::updateOrCreate(
                ['name' => $name],
                ['description' => $this->nullableString($row['description'] ?? null)]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->categoryMap[$oldId] = $category->id;
            }

            $count++;
        }

        $this->info('Categories imported: '.$count);
    }

    private function importJobs(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'jobs.json');
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $job = MemberJob::updateOrCreate(
                ['name' => $name],
                ['description' => $this->nullableString($row['description'] ?? null)]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->jobMap[$oldId] = $job->id;
            }

            $count++;
        }

        $this->info('Jobs imported: '.$count);
    }

    private function importPositions(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'positions.json');
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $abbr = trim((string) ($row['abbreviation'] ?? strtoupper(substr($name, 0, 3))));

            $position = Position::updateOrCreate(
                ['name' => $name],
                ['abbreviation' => $abbr]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->positionMap[$oldId] = $position->id;
            }

            $count++;
        }

        $this->info('Positions imported: '.$count);
    }

    private function importSubscriptions(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'subscriptions.json');
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $amount = is_array($row['amount'] ?? null) ? $row['amount'] : [];
            $year = (int) ($row['year'] ?? now()->year);

            $subscription = Subscription::updateOrCreate(
                [
                    'name' => $name,
                    'year' => $year,
                ],
                [
                    'amount_student' => (float) ($amount['student'] ?? 0),
                    'amount_worker' => (float) ($amount['worker'] ?? 0),
                    'details' => $this->nullableString($row['details'] ?? null),
                    'is_mandatory' => $this->toBoolean($row['isMandatory'] ?? false),
                    'is_active' => $this->toBoolean($row['isActive'] ?? true),
                ]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->subscriptionMap[$oldId] = $subscription->id;
            }

            $categoryIds = [];
            foreach ($this->ensureArray($row['categoryPlayer'] ?? []) as $categoryRef) {
                $categoryOldId = $this->extractMongoId($categoryRef);
                if ($categoryOldId !== null && isset($this->categoryMap[$categoryOldId])) {
                    $categoryIds[] = $this->categoryMap[$categoryOldId];
                }
            }

            $subscription->categories()->sync($categoryIds);

            $count++;
        }

        $this->info('Subscriptions imported: '.$count);
    }

    private function importUsers(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'users.json',
            'user.json',
        ]);
        $count = 0;

        foreach ($rows as $row) {
            $legacyId = $this->extractMongoId($row['_id'] ?? null);
            $email = $this->resolveImportedUserEmail($row, $legacyId);

            $firstname = $this->normalizeName((string) ($row['firstname'] ?? ''));
            $lastname = $this->normalizeName((string) ($row['lastname'] ?? ''));
            $fallbackName = trim((string) ($row['name'] ?? $row['username'] ?? 'Imported User'));
            $displayName = trim($firstname.' '.$lastname);

            if ($displayName === '') {
                $displayName = $fallbackName !== '' ? $fallbackName : 'Imported User';
            }

            $membershipId = $this->nullableString($row['membershipID'] ?? $row['membership_id'] ?? null);
            if ($membershipId !== null && User::query()->where('membership_id', $membershipId)->where('email', '!=', $email)->exists()) {
                $membershipId = null;
            }

            $jobId = null;
            $jobOldId = $this->extractMongoId($row['job'] ?? null);
            if ($jobOldId !== null && isset($this->jobMap[$jobOldId])) {
                $jobId = $this->jobMap[$jobOldId];
            }

            $categoryId = null;
            $categoryOldId = $this->extractMongoId($row['category'] ?? null);
            if ($categoryOldId !== null && isset($this->categoryMap[$categoryOldId])) {
                $categoryId = $this->categoryMap[$categoryOldId];
            }

            $privileges = $this->normalizeStringList($row['privileges'] ?? []);
            if ($privileges === []) {
                $privileges = ['user'];
            }

            $health = is_array($row['health'] ?? null) ? $row['health'] : null;

            $user = User::query()->firstOrNew(['email' => $email]);
            $user->fill([
                'name' => $displayName,
                'membership_id' => $membershipId,
                'firstname' => $firstname !== '' ? $firstname : null,
                'lastname' => $lastname !== '' ? $lastname : null,
                'phones' => $this->normalizePhones($row['phones'] ?? []),
                'birthdate' => $this->parseDate($row['birthdate'] ?? null),
                'gender' => $this->nullableString($row['gender'] ?? null) ?? 'Male',
                'picture_url' => $this->nullableString($row['picture']['url'] ?? null),
                'picture_filename' => $this->nullableString($row['picture']['filename'] ?? null),
                'is_user' => $this->toBoolean($row['isUser'] ?? $row['is_user'] ?? true),
                'is_active' => $this->toBoolean($row['isActive'] ?? $row['is_active'] ?? true),
                'approved' => $this->toBoolean($row['approved'] ?? false),
                'state' => $this->nullableString($row['state'] ?? null) ?? 'Unknown',
                'city' => $this->nullableString($row['city'] ?? null) ?? 'Unknown',
                'is_student' => $this->toBoolean($row['isStudent'] ?? $row['is_student'] ?? true),
                'member_job_id' => $jobId,
                'category_id' => $categoryId,
                'team' => $this->nullableString($row['team'] ?? null),
                'position' => $this->nullableString($row['position'] ?? null),
                'skill_level' => max(1, min(10, (int) ($row['skillLevel'] ?? $row['skill_level'] ?? 5))),
                'health' => $health,
                'privileges' => $privileges,
                'preferred_lng' => $this->nullableString($row['preferedLng'] ?? $row['preferred_lng'] ?? null) ?? 'ar',
                'logged_in_at' => $this->parseDate($row['loggedInAt'] ?? $row['logged_in_at'] ?? null),
            ]);

            if (! $user->exists) {
                // Legacy password hashes are intentionally not migrated; users reset passwords post-cutover.
                $user->password = Hash::make(Str::random(48));
            }

            $emailVerifiedAt = $this->parseDate($row['emailVerifiedAt'] ?? $row['email_verified_at'] ?? null);
            if ($emailVerifiedAt !== null) {
                $user->email_verified_at = $emailVerifiedAt;
            }

            $user->save();

            if ($legacyId !== null) {
                $this->userMap[$legacyId] = $user->id;
            }

            $count++;
        }

        $this->info('Users imported: '.$count);
    }

    private function importPlayers(string $basePath): void
    {
        $rows = $this->loadCollection($basePath, 'players.json');
        $count = 0;

        foreach ($rows as $row) {
            $firstname = $this->normalizeName((string) ($row['firstname'] ?? ''));
            if ($firstname === '') {
                continue;
            }

            $membershipId = trim((string) ($row['membershipID'] ?? ''));
            if ($membershipId === '') {
                $membershipId = $this->generateMembershipId((int) ($row['joinYear'] ?? now()->year));
            }

            $status = is_array($row['status'] ?? null) ? $row['status'] : [];
            $health = is_array($row['health'] ?? null) ? $row['health'] : [];
            $blood = is_array($health['blood'] ?? null) ? $health['blood'] : [];

            $jobId = null;
            $jobOldId = $this->extractMongoId($row['job'] ?? null);
            if ($jobOldId !== null && isset($this->jobMap[$jobOldId])) {
                $jobId = $this->jobMap[$jobOldId];
            }

            $categoryId = null;
            $categoryOldId = $this->extractMongoId($row['category'] ?? null);
            if ($categoryOldId !== null && isset($this->categoryMap[$categoryOldId])) {
                $categoryId = $this->categoryMap[$categoryOldId];
            }

            $positionId = null;
            $positionOldId = $this->extractMongoId($row['position'] ?? null);
            if ($positionOldId !== null && isset($this->positionMap[$positionOldId])) {
                $positionId = $this->positionMap[$positionOldId];
            }

            $player = Player::updateOrCreate(
                ['membership_id' => $membershipId],
                [
                    'firstname' => $firstname,
                    'lastname' => $this->normalizeName((string) ($row['lastname'] ?? '')),
                    'nickname' => $this->normalizeName((string) ($row['nickname'] ?? '')),
                    'father' => $this->normalizeName((string) ($row['father'] ?? '')),
                    'grandfather' => $this->normalizeName((string) ($row['grandfather'] ?? '')),
                    'birthdate' => $this->parseDate($row['birthdate'] ?? null),
                    'gender' => $this->nullableString($row['gender'] ?? null) ?? 'Male',
                    'picture_url' => $this->nullableString($row['picture']['url'] ?? null),
                    'picture_filename' => $this->nullableString($row['picture']['filename'] ?? null),
                    'phones' => $this->normalizePhones($row['phones'] ?? []),
                    'email' => $this->nullableString($row['email'] ?? null),
                    'status_class' => $this->nullableString($status['class'] ?? null),
                    'status_value' => $this->nullableString($status['value'] ?? null),
                    'state' => $this->nullableString($row['state'] ?? null) ?? 'Unknown',
                    'city' => $this->nullableString($row['city'] ?? null) ?? 'Unknown',
                    'is_student' => $this->toBoolean($row['isStudent'] ?? true),
                    'member_job_id' => $jobId,
                    'join_year' => (int) ($row['joinYear'] ?? now()->year),
                    'archived' => $this->toBoolean($row['archived'] ?? false),
                    'category_id' => $categoryId,
                    'position_id' => $positionId,
                    'team' => $this->nullableString($row['team'] ?? null),
                    'skill_level' => max(1, min(10, (int) ($row['skillLevel'] ?? 5))),
                    'health_medical_conditions' => $this->nullableString($health['medicalConditions'] ?? null),
                    'health_blood_group_rhesus' => $this->nullableString($blood['groupRhesus'] ?? null),
                    'outstanding_debt' => (float) ($row['outstandingDebt'] ?? 0),
                ]
            );

            $player->emergencyContacts()->delete();
            foreach ($this->ensureArray($health['emergencyContact'] ?? []) as $contact) {
                if (! is_array($contact)) {
                    continue;
                }

                $contactName = trim((string) ($contact['name'] ?? ''));
                if ($contactName === '') {
                    continue;
                }

                $player->emergencyContacts()->create([
                    'name' => $contactName,
                    'relationship' => $this->nullableString($contact['relationship'] ?? null),
                    'phones' => $this->normalizePhones($contact['phone'] ?? []),
                ]);
            }

            $player->achievements()->delete();
            foreach ($this->ensureArray($row['achievements'] ?? []) as $achievement) {
                if (! is_array($achievement)) {
                    continue;
                }

                $title = trim((string) ($achievement['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $player->achievements()->create([
                    'title' => $title,
                    'date' => $this->parseDate($achievement['date'] ?? null),
                    'description' => $this->nullableString($achievement['description'] ?? null),
                ]);
            }

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->playerMap[$oldId] = $player->id;
            }

            $count++;
        }

        $this->info('Players imported: '.$count);
    }

    private function importTransactions(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'transactions.json',
            'transaction.json',
        ]);
        $count = 0;

        foreach ($rows as $row) {
            $transactionDate = $this->parseDate(
                $row['transactionDate'] ?? $row['transaction_date'] ?? $row['createdAt'] ?? null
            ) ?? now();

            if ((int) $transactionDate->year < 2000) {
                $transactionDate = $this->parseDate($row['createdAt'] ?? $row['updatedAt'] ?? null) ?? $transactionDate;
            }

            $relatedTo = is_array($row['relatedTo'] ?? null) ? $row['relatedTo'] : [];
            $relatedEntityType = $this->normalizeEntityType(
                $this->nullableString($relatedTo['entityType'] ?? $row['related_entity_type'] ?? null)
            );
            $relatedEntityId = $this->resolveRelatedEntityId(
                $relatedEntityType,
                $relatedTo['entityId'] ?? $row['related_entity_id'] ?? null
            );

            $attributes = [
                'amount' => (float) ($row['amount'] ?? 0),
                'transaction_date' => $transactionDate,
                'transaction_type' => $this->normalizeTransactionType($row['transactionType'] ?? $row['transaction_type'] ?? null),
                'category' => $this->nullableString($row['category'] ?? null) ?? 'miscellaneous',
                'sub_category' => $this->nullableString($row['subCategory'] ?? $row['sub_category'] ?? null),
                'payment_method' => $this->nullableString($row['payment']['method'] ?? $row['payment_method'] ?? null) ?? 'cash',
                'payment_account' => $this->nullableString($row['payment']['account'] ?? $row['payment_account'] ?? null),
                'related_entity_type' => $relatedEntityType,
                'related_entity_id' => $relatedEntityId,
                'description' => $this->nullableString($row['description'] ?? null),
                'recorded_by_user_id' => $this->resolveUserId($row['recordedBy'] ?? $row['recorded_by_user_id'] ?? null),
                'received_by_user_id' => $this->resolveUserId($row['receivedBy'] ?? $row['received_by_user_id'] ?? null),
                'status' => $this->normalizePaymentStatus($row['status'] ?? null),
                'archived' => $this->toBoolean($row['archived'] ?? false),
                'receipt_url' => $this->nullableString($row['receipt']['url'] ?? $row['receipt_url'] ?? null),
                'receipt_filename' => $this->nullableString($row['receipt']['filename'] ?? $row['receipt_filename'] ?? null),
                'fiscal_year' => $this->resolveFiscalYear($row['year'] ?? $row['fiscal_year'] ?? null, $transactionDate),
            ];

            $identity = [
                'amount' => $attributes['amount'],
                'transaction_date' => $transactionDate,
                'category' => $attributes['category'],
                'related_entity_type' => $attributes['related_entity_type'],
                'related_entity_id' => $attributes['related_entity_id'],
                'description' => $attributes['description'],
            ];

            $transaction = Transaction::query()->updateOrCreate($identity, $attributes);

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->transactionMap[$oldId] = $transaction->id;
            }

            $count++;
        }

        $this->info('Transactions imported: '.$count);
    }

    private function importPlayerSubscriptions(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'player_subscriptions.json',
            'playerSubscriptions.json',
            'playersubscriptions.json',
        ]);
        $count = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $playerLegacyId = $this->extractMongoId($row['player'] ?? null);
            $playerId = $playerLegacyId !== null ? ($this->playerMap[$playerLegacyId] ?? null) : null;

            if ($playerId === null) {
                $skipped++;

                continue;
            }

            $subscriptionLegacyId = $this->extractMongoId($row['subscription'] ?? null);
            $subscriptionId = $subscriptionLegacyId !== null
                ? ($this->subscriptionMap[$subscriptionLegacyId] ?? null)
                : null;

            $transactionLegacyId = $this->extractMongoId($row['transaction'] ?? null);
            $transactionId = $transactionLegacyId !== null
                ? ($this->transactionMap[$transactionLegacyId] ?? null)
                : null;

            $year = (int) ($row['year'] ?? now()->year);
            if ($year < 1900 || $year > 9999) {
                $year = (int) now()->year;
            }

            PlayerSubscription::query()->updateOrCreate(
                [
                    'player_id' => $playerId,
                    'transaction_id' => $transactionId,
                    'year' => $year,
                ],
                [
                    'subscription_id' => $subscriptionId,
                    'status_at_time' => $this->normalizeStatusAtTime($row['statusAtTime'] ?? $row['status_at_time'] ?? null),
                    'amount_owed' => (float) ($row['amountOwed'] ?? $row['amount_owed'] ?? 0),
                    'amount_paid' => (float) ($row['amountPaid'] ?? $row['amount_paid'] ?? 0),
                    'is_legacy' => $this->toBoolean($row['isLegacy'] ?? $row['is_legacy'] ?? false),
                    'due_date' => $this->parseDate($row['dueDate'] ?? $row['due_date'] ?? null),
                ]
            );

            $count++;
        }

        $this->info('Player subscriptions imported: '.$count);

        if ($skipped > 0) {
            $this->warn('Player subscriptions skipped (missing player mapping): '.$skipped);
        }
    }

    private function importEquipmentCatalogs(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'equipment_catalogs.json',
            'equipmentcatalogs.json',
        ]);
        $count = 0;

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $category = $this->nullableString($row['category'] ?? null) ?? 'Uncategorized';
            $brand = $this->nullableString($row['brand'] ?? null);

            $catalog = EquipmentCatalog::query()->updateOrCreate(
                [
                    'name' => $name,
                    'category' => $category,
                    'brand' => $brand,
                ],
                [
                    'description' => $this->nullableString($row['description'] ?? null),
                    'specifications' => is_array($row['specifications'] ?? null) ? $row['specifications'] : null,
                    'purchase_price' => isset($row['purchasePrice']) ? (float) $row['purchasePrice'] : (isset($row['purchase_price']) ? (float) $row['purchase_price'] : null),
                    'picture_url' => $this->nullableString($row['picture']['url'] ?? $row['picture_url'] ?? null),
                    'picture_filename' => $this->nullableString($row['picture']['filename'] ?? $row['picture_filename'] ?? null),
                    'item_count' => max(0, (int) ($row['itemCount'] ?? $row['item_count'] ?? 0)),
                ]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->equipmentCatalogMap[$oldId] = $catalog->id;
            }

            $count++;
        }

        $this->info('Equipment catalogs imported: '.$count);
    }

    private function importEquipmentItems(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'equipment_items.json',
            'equipmentitems.json',
        ]);
        $count = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $catalogLegacyId = $this->extractMongoId($row['catalogId'] ?? $row['catalog_id'] ?? null);
            $catalogId = $catalogLegacyId !== null ? ($this->equipmentCatalogMap[$catalogLegacyId] ?? null) : null;

            if ($catalogId === null) {
                $skipped++;

                continue;
            }

            $uniqueIdentifier = $this->nullableString($row['uniqueIdentifier'] ?? $row['unique_identifier'] ?? null);
            if ($uniqueIdentifier === null) {
                $uniqueIdentifier = 'EQ-'.$catalogId.'-'.strtoupper(Str::random(8));
            }

            $purchaseDate = $this->parseDate($row['purchaseDate'] ?? $row['purchase_date'] ?? null)?->toDateString()
                ?? now()->toDateString();

            $item = EquipmentItem::query()->updateOrCreate(
                ['unique_identifier' => $uniqueIdentifier],
                [
                    'catalog_id' => $catalogId,
                    'purchase_date' => $purchaseDate,
                    'status' => $this->normalizeEquipmentStatus($row['status'] ?? null),
                    'condition' => $this->normalizeEquipmentCondition($row['condition'] ?? null),
                    'location' => $this->nullableString($row['location'] ?? null),
                    'purchase_transaction_id' => $this->resolveTransactionId($row['purchaseTransaction'] ?? $row['purchase_transaction_id'] ?? null),
                    'rented_to_player_id' => $this->resolvePlayerId($row['rentedTo'] ?? $row['rented_to_player_id'] ?? null),
                    'last_checkout_date' => $this->parseDate($row['lastCheckoutDate'] ?? $row['last_checkout_date'] ?? null),
                    'due_date' => $this->parseDate($row['dueDate'] ?? $row['due_date'] ?? null)?->toDateString(),
                    'notes' => $this->nullableString($row['notes'] ?? null),
                ]
            );

            $oldId = $this->extractMongoId($row['_id'] ?? null);
            if ($oldId !== null) {
                $this->equipmentItemMap[$oldId] = $item->id;
            }

            $count++;
        }

        EquipmentCatalog::query()->each(function (EquipmentCatalog $catalog): void {
            $catalog->update([
                'item_count' => $catalog->items()->count(),
            ]);
        });

        $this->info('Equipment items imported: '.$count);

        if ($skipped > 0) {
            $this->warn('Equipment items skipped (missing catalog mapping): '.$skipped);
        }
    }

    private function importEquipmentHistories(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'equipment_histories.json',
            'equipmenthistories.json',
            'histories.json',
        ]);
        $count = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $itemLegacyId = $this->extractMongoId($row['itemId'] ?? $row['item_id'] ?? null);
            $itemId = $itemLegacyId !== null ? ($this->equipmentItemMap[$itemLegacyId] ?? null) : null;

            if ($itemId === null) {
                $skipped++;

                continue;
            }

            $eventTimestamp = $this->parseDate($row['timestamp'] ?? $row['eventTimestamp'] ?? $row['event_timestamp'] ?? $row['createdAt'] ?? null)
                ?? now();

            EquipmentHistory::query()->updateOrCreate(
                [
                    'item_id' => $itemId,
                    'event_type' => $this->nullableString($row['eventType'] ?? $row['event_type'] ?? null) ?? 'Imported',
                    'event_timestamp' => $eventTimestamp,
                ],
                [
                    'user_id' => $this->resolveUserId($row['userId'] ?? $row['user_id'] ?? null),
                    'details' => is_array($row['details'] ?? null) ? $row['details'] : null,
                ]
            );

            $count++;
        }

        $this->info('Equipment histories imported: '.$count);

        if ($skipped > 0) {
            $this->warn('Equipment histories skipped (missing item mapping): '.$skipped);
        }
    }

    private function importWebsiteConfig(string $basePath): void
    {
        $rows = $this->loadFirstAvailableCollection($basePath, [
            'website_configs.json',
            'websiteconfigs.json',
        ]);

        if ($rows === []) {
            return;
        }

        $row = $rows[0];
        $basicInfo = is_array($row['basicInfo'] ?? null) ? $row['basicInfo'] : [];
        $contactInfo = is_array($row['contactInfo'] ?? null) ? $row['contactInfo'] : [];

        $config = WebsiteConfig::singleton();
        $config->fill([
            'singleton_key' => 1,
            'club_name' => is_array($basicInfo['clubName'] ?? null) ? $basicInfo['clubName'] : $config->club_name,
            'club_short_name' => $this->nullableString($basicInfo['clubShortName'] ?? null) ?? $config->club_short_name,
            'tagline' => is_array($basicInfo['tagline'] ?? null) ? $basicInfo['tagline'] : $config->tagline,
            'description' => is_array($basicInfo['description'] ?? null) ? $basicInfo['description'] : $config->description,
            'founding_date' => $this->parseDate($basicInfo['foundingDate'] ?? null)?->toDateString(),
            'founder' => $this->nullableString($basicInfo['founder'] ?? null),
            'motto' => is_array($basicInfo['motto'] ?? null) ? $basicInfo['motto'] : null,
            'contact_email' => $this->nullableString($contactInfo['email'] ?? null),
            'contact_phone' => $this->nullableString($contactInfo['phone'] ?? null),
            'contact_mobile' => $this->nullableString($contactInfo['mobile'] ?? null),
            'contact_fax' => $this->nullableString($contactInfo['fax'] ?? null),
            'contact_website' => $this->nullableString($contactInfo['website'] ?? null),
            'contact_address' => is_array($contactInfo['address'] ?? null) ? $contactInfo['address'] : null,
            'social_media' => is_array($row['socialMedia'] ?? null) ? $row['socialMedia'] : null,
            'banking_info' => is_array($row['bankingInfo'] ?? null) ? $row['bankingInfo'] : null,
            'legal_info' => is_array($row['legalInfo'] ?? null) ? $row['legalInfo'] : null,
            'leadership' => is_array($row['leadership'] ?? null) ? $row['leadership'] : null,
            'branding' => is_array($row['branding'] ?? null) ? $row['branding'] : null,
            'facilities' => is_array($row['facilities'] ?? null) ? $row['facilities'] : null,
            'settings' => is_array($row['settings'] ?? null) ? $row['settings'] : null,
            'seo' => is_array($row['seo'] ?? null) ? $row['seo'] : null,
            'documents' => is_array($row['documents'] ?? null) ? $row['documents'] : null,
            'last_modified_by_user_id' => $this->resolveUserId($row['lastModifiedBy'] ?? $row['last_modified_by_user_id'] ?? null),
        ]);
        $config->save();

        $this->info('Website config imported: 1');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadCollection(string $basePath, string $fileName): array
    {
        $filePath = $this->resolveCollectionFilePath($basePath, $fileName);

        if ($filePath === null || ! File::exists($filePath)) {
            $this->warn('File not found, skipping: '.$fileName);

            return [];
        }

        $raw = trim((string) File::get($filePath));
        if ($raw === '') {
            return [];
        }

        $records = [];

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                $this->warn('Invalid JSON array in '.$fileName);

                return [];
            }

            foreach ($decoded as $doc) {
                if (is_array($doc)) {
                    $records[] = $this->normalizeMongoDocument($doc);
                }
            }

            return $records;
        }

        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $records[] = $this->normalizeMongoDocument($decoded);
            }
        }

        return $records;
    }

    /**
     * @param  list<string>  $fileNames
     * @return list<array<string, mixed>>
     */
    private function loadFirstAvailableCollection(string $basePath, array $fileNames): array
    {
        foreach ($fileNames as $fileName) {
            if ($this->resolveCollectionFilePath($basePath, $fileName) !== null) {
                return $this->loadCollection($basePath, $fileName);
            }
        }

        $this->warn('File not found, skipping: '.implode(' | ', $fileNames));

        return [];
    }

    private function resolveCollectionFilePath(string $basePath, string $fileName): ?string
    {
        $directPath = $basePath.DIRECTORY_SEPARATOR.$fileName;
        if (File::exists($directPath)) {
            return $directPath;
        }

        $allJsonFiles = File::glob($basePath.DIRECTORY_SEPARATOR.'*.json') ?: [];
        $fileNameLower = strtolower($fileName);
        $strictSuffix = '.'.$fileNameLower;

        $matched = [];
        foreach ($allJsonFiles as $path) {
            $baseName = strtolower(basename($path));

            if ($baseName === $fileNameLower || str_ends_with($baseName, $strictSuffix)) {
                $matched[] = $path;
            }
        }

        if ($matched !== []) {
            sort($matched);

            return $matched[0];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function normalizeMongoDocument(array $document): array
    {
        $normalized = [];

        foreach ($document as $key => $value) {
            $normalized[$key] = $this->normalizeMongoValue($value);
        }

        return $normalized;
    }

    private function normalizeMongoValue(mixed $value): mixed
    {
        if (is_array($value)) {
            if (array_key_exists('$oid', $value)) {
                return (string) $value['$oid'];
            }

            if (array_key_exists('$numberInt', $value)) {
                return (int) $value['$numberInt'];
            }

            if (array_key_exists('$numberLong', $value)) {
                return (int) $value['$numberLong'];
            }

            if (array_key_exists('$numberDouble', $value)) {
                return (float) $value['$numberDouble'];
            }

            if (array_key_exists('$date', $value)) {
                return $this->parseDate($value['$date']);
            }

            $normalized = [];
            foreach ($value as $k => $v) {
                $normalized[$k] = $this->normalizeMongoValue($v);
            }

            return $normalized;
        }

        return $value;
    }

    private function extractMongoId(mixed $value): ?string
    {
        $normalized = $this->normalizeMongoValue($value);

        if (is_scalar($normalized) && (string) $normalized !== '') {
            return (string) $normalized;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resolveImportedUserEmail(array $row, ?string $legacyId): string
    {
        $email = strtolower((string) ($this->nullableString($row['email'] ?? null) ?? ''));
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $email;
        }

        $token = $legacyId
            ?? $this->nullableString($row['membershipID'] ?? null)
            ?? $this->nullableString($row['username'] ?? null)
            ?? Str::random(12);

        $token = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $token) ?? 'user');
        $token = trim($token, '-');
        if ($token === '') {
            $token = 'user';
        }

        return 'import-'.$token.'@migrated.local';
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        $items = [];

        foreach ($this->ensureArray($value) as $item) {
            $cleaned = trim((string) $item);
            if ($cleaned !== '') {
                $items[] = $cleaned;
            }
        }

        return array_values(array_unique($items));
    }

    private function normalizeEntityType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'player' => 'Player',
            'user' => 'User',
            default => $value,
        };
    }

    private function resolveRelatedEntityId(?string $entityType, mixed $value): ?int
    {
        if ($entityType === null) {
            return null;
        }

        $legacyId = $this->extractMongoId($value);
        if ($legacyId === null) {
            if (is_numeric($value)) {
                return (int) $value;
            }

            return null;
        }

        return match (strtolower($entityType)) {
            'player' => $this->playerMap[$legacyId] ?? null,
            'user' => $this->userMap[$legacyId] ?? null,
            default => null,
        };
    }

    private function resolveUserId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && strlen((string) $value) <= 10) {
            return (int) $value;
        }

        $legacyId = $this->extractMongoId($value);
        if ($legacyId === null) {
            return null;
        }

        return $this->userMap[$legacyId] ?? null;
    }

    private function normalizeTransactionType(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        if (in_array($normalized, ['expense', 'debit', 'out', 'outflow'], true)) {
            return 'expense';
        }

        return 'income';
    }

    private function normalizePaymentStatus(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'paid' => 'Paid',
            'partial', 'partially_paid', 'partially-paid' => 'Partial',
            'exempt', 'waived' => 'Exempt',
            default => 'Unpaid',
        };
    }

    private function normalizeStatusAtTime(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'student' => 'student',
            'worker', 'employee' => 'worker',
            default => 'unknown',
        };
    }

    private function resolveFiscalYear(mixed $value, Carbon $transactionDate): int
    {
        if (is_numeric($value)) {
            $year = (int) $value;
            if ($year >= 1900 && $year <= 9999) {
                return $year;
            }
        }

        return (int) $transactionDate->year;
    }

    private function resolveTransactionId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && strlen((string) $value) <= 12) {
            return (int) $value;
        }

        $legacyId = $this->extractMongoId($value);
        if ($legacyId === null) {
            return null;
        }

        return $this->transactionMap[$legacyId] ?? null;
    }

    private function resolvePlayerId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value) && strlen((string) $value) <= 12) {
            return (int) $value;
        }

        $legacyId = $this->extractMongoId($value);
        if ($legacyId === null) {
            return null;
        }

        return $this->playerMap[$legacyId] ?? null;
    }

    private function normalizeEquipmentStatus(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'available' => 'Available',
            'rented' => 'Rented',
            'under repair', 'under_repair', 'repair' => 'Under Repair',
            'out of service', 'out_of_service' => 'Out of Service',
            'lost' => 'Lost',
            'retired', 'disposed' => 'Retired',
            default => 'Available',
        };
    }

    private function normalizeEquipmentCondition(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'new' => 'New',
            'good' => 'Good',
            'fair' => 'Fair',
            'poor' => 'Poor',
            'damaged' => 'Damaged',
            default => 'Good',
        };
    }

    private function normalizeName(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return list<string>
     */
    private function normalizePhones(mixed $value): array
    {
        $phones = [];
        foreach ($this->ensureArray($value) as $item) {
            $phone = trim((string) $item);
            if ($phone !== '') {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return list<mixed>
     */
    private function ensureArray(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_array($value)) {
            return array_values($value);
        }

        return [$value];
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function toBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        $normalized = strtolower(trim((string) $value));

        return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
    }

    private function parseDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof Carbon) {
            return $value;
        }

        if (is_array($value) && array_key_exists('$numberLong', $value)) {
            return Carbon::createFromTimestampMs((int) $value['$numberLong']);
        }

        if (is_numeric($value)) {
            $timestamp = (int) $value;

            if ($timestamp > 9999999999) {
                return Carbon::createFromTimestampMs($timestamp);
            }

            if ($timestamp > 1000000000) {
                return Carbon::createFromTimestamp($timestamp);
            }
        }

        try {
            return Carbon::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function generateMembershipId(int $year): string
    {
        $prefix = str_pad((string) max(1900, min(9999, $year)), 4, '0', STR_PAD_LEFT);

        do {
            $candidate = $prefix.str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        } while (Player::query()->where('membership_id', $candidate)->exists());

        return $candidate;
    }
}
