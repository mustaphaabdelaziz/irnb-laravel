<?php

/*
 * Grow a COPY of the club database to test how pages scale.
 *
 *   php scripts/perf/scale.php <dbCopy> [playerMultiplier=10] [seasons=5]
 *
 * Clones the players (with their branches and documents), then for every
 * season: one subscription with an obligation per player (paid for most past
 * seasons), payments and expenses, three held sessions a week per category
 * with a mark per player (≈1.5% excused-for-injury, ≈1% not training), and a
 * growing activity log. 10 × 5 seasons ≈ 1,900 players, 1M marks, 150 MB.
 *
 * Planner statistics are NOT gathered: the club databases have none
 * (sqlite_stat1 never exists), and with them sqlite picks other indexes.
 */

$root = dirname(__DIR__, 2);
[$path, $multiplier, $seasons] = [$argv[1] ?? null, max(1, (int) ($argv[2] ?? 10)), max(1, (int) ($argv[3] ?? 5))];
$real = $path ? realpath($path) : false;
if ($real === false || str_starts_with(strtolower($real), strtolower(realpath($root)))) {
    fwrite(STDERR, "Give the path of a database COPY outside the project.\n");
    exit(1);
}

mt_srand(42);
$db = new PDO('sqlite:'.$real);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('PRAGMA journal_mode=OFF; PRAGMA synchronous=OFF; PRAGMA foreign_keys=OFF');
$db->beginTransaction();
$started = microtime(true);
$columns = fn (string $table) => array_values(array_filter(array_column($db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name'), fn ($c) => $c !== 'id'));

// Players, with their branches and documents.
$original = $db->query('select id from players')->fetchAll(PDO::FETCH_COLUMN);
$playerColumns = $columns('players');
$copyPlayer = $db->prepare('insert into players ('.implode(',', array_map(fn ($c) => "\"$c\"", $playerColumns)).') select '
    .implode(',', array_map(fn ($c) => $c === 'membership_id' ? '(900000000 + :k * 10000 + id)' : "\"$c\"", $playerColumns)).' from players where id = :id');
$copyBranches = $db->prepare('insert or ignore into branch_player (branch_id, player_id) select branch_id, :new from branch_player where player_id = :old');
$documentColumns = $columns('player_documents');
$copyDocuments = $db->prepare('insert or ignore into player_documents ('.implode(',', $documentColumns).') select '
    .implode(',', array_map(fn ($c) => $c === 'player_id' ? ':new' : $c, $documentColumns)).' from player_documents where player_id = :old');
for ($k = 1; $k < $multiplier; $k++) {
    foreach ($original as $id) {
        $copyPlayer->execute([':k' => $k, ':id' => $id]);
        $new = (int) $db->lastInsertId();
        $copyBranches->execute([':new' => $new, ':old' => $id]);
        $copyDocuments->execute([':new' => $new, ':old' => $id]);
    }
}

// Seasons: fiscal years, one subscription each, obligations and payments, expenses.
$currentYear = (int) $db->query('select max(year) from fiscal_years')->fetchColumn(); // the season's end year
$template = (int) $db->query("select id from subscriptions where kind = 'annual' and year = $currentYear order by id limit 1")->fetchColumn();
$players = $db->query('select id, category_id from players where archived = 0')->fetchAll(PDO::FETCH_KEY_PAIR);
$assigned = array_flip($db->query("select player_id || '-' || year from player_subscriptions")->fetchAll(PDO::FETCH_COLUMN));
$obligation = $db->prepare("insert into player_subscriptions (player_id, subscription_id, year, status_at_time, amount_owed, amount_paid, is_mandatory, created_at, updated_at) values (?, ?, ?, 'student', 1000, ?, 1, ?, ?)");
$payment = $db->prepare("insert into transactions (amount, transaction_date, transaction_type, category, payment_method, related_entity_type, related_entity_id, recorded_by_user_id, status, archived, fiscal_year, fiscal_year_id, finance_category_id, finance_account_id, player_subscription_id, created_at, updated_at) values (1000, ?, 'income', 'subscription', 'cash', 'Player', ?, 2, 'Paid', 0, ?, ?, (select finance_category_id from transactions where category = 'subscription' limit 1), 1, ?, ?, ?)");
$link = $db->prepare('update player_subscriptions set transaction_id = ? where id = ?');
for ($s = 0; $s < $seasons; $s++) {
    $year = $currentYear - $s;
    $db->exec("insert or ignore into fiscal_years (year, start_date, end_date, status, opening_balance, created_at, updated_at) values ($year, '".($year - 1)."-09-01 00:00:00', '$year-08-31 00:00:00', '".($s ? 'closed' : 'open')."', 0, datetime('now'), datetime('now'))");
    $fiscalYear = (int) $db->query("select id from fiscal_years where year = $year")->fetchColumn();
    $subscription = $template;
    if ($s > 0 && $template) {
        $db->exec("insert or ignore into subscriptions (name, year, amount_student, amount_worker, details, is_mandatory, is_active, created_at, updated_at, kind) select name, $year, amount_student, amount_worker, details, is_mandatory, 0, created_at, updated_at, kind from subscriptions where id = $template");
        $subscription = (int) $db->query("select id from subscriptions where year = $year and name = (select name from subscriptions where id = $template)")->fetchColumn();
    }
    foreach ($players as $player => $category) {
        if (isset($assigned["$player-$year"])) {
            continue;
        }
        $paid = mt_rand(1, 100) <= ($s === 0 ? 40 : 85);
        $date = sprintf('%d-%02d-%02d 10:00:00', $year - 1, mt_rand(9, 12), mt_rand(1, 28));
        $obligation->execute([$player, $subscription ?: null, $year, $paid ? 1000 : 0, $date, $date]);
        $obligationId = (int) $db->lastInsertId();
        if ($paid) {
            $payment->execute([$date, $player, $year, $fiscalYear ?: null, $obligationId, $date, $date]);
            $link->execute([(int) $db->lastInsertId(), $obligationId]);
        }
    }
    for ($e = 0; $e < 150 * max(1, intdiv($multiplier, 2)); $e++) {
        $date = sprintf('%d-%02d-%02d 12:00:00', $year - 1 + mt_rand(0, 1), mt_rand(1, 12), mt_rand(1, 28));
        $db->exec('insert into transactions (amount, transaction_date, transaction_type, category, payment_method, recorded_by_user_id, status, archived, fiscal_year, fiscal_year_id, finance_account_id, created_at, updated_at) values ('.mt_rand(500, 50000).", '$date', 'expense', 'equipment', 'cash', 2, 'Paid', 0, $year, ".($fiscalYear ?: 'null').", 1, '$date', '$date')");
    }
}

// Training: three held sessions a week per category, September to June, one mark per player.
$byCategory = [];
foreach ($players as $player => $category) {
    if ($category) {
        $byCategory[$category][] = $player;
    }
}
$session = $db->prepare("insert or ignore into training_sessions (category_id, date, start_time, end_time, kind, state, created_at, updated_at) values (?, ?, '17:00', '18:30', 'regular', 'held', ?, ?)");
$mark = $db->prepare('insert or ignore into attendances (training_session_id, player_id, status, reason, recorded_by, category_id, created_at, updated_at) values (?, ?, ?, ?, 2, ?, ?, ?)');
$reasons = ['school', 'illness', 'family', 'other'];
$today = new DateTimeImmutable('today');
foreach ($byCategory as $category => $members) {
    for ($s = 0; $s < $seasons; $s++) {
        $end = min(new DateTimeImmutable(($currentYear - $s).'-06-30'), $today);
        for ($day = new DateTimeImmutable(($currentYear - 1 - $s).'-09-01'); $day <= $end; $day = $day->modify('+1 day')) {
            if (! in_array((int) $day->format('N'), [1, 3, 5], true)) {
                continue;
            }
            $date = $day->format('Y-m-d');
            $session->execute([$category, $date, "$date 20:00:00", "$date 20:00:00"]);
            if ($session->rowCount() === 0) {
                continue;
            }
            $sessionId = (int) $db->lastInsertId();
            foreach ($members as $player) {
                $roll = mt_rand(1, 1000);
                [$status, $reason] = match (true) {
                    $roll <= 750 => ['present', null],
                    $roll <= 765 => ['absent_excused', 'injury'],
                    $roll <= 775 => ['not_training', null],
                    $roll <= 875 => ['absent_excused', $reasons[$roll % 4]],
                    default => ['absent_unexcused', null],
                };
                $mark->execute([$sessionId, $player, $status, $reason, $category, "$date 20:00:00", "$date 20:00:00"]);
            }
        }
    }
}

// A growing activity log.
$log = $db->prepare("insert into activity_logs (user_id, action, subject_type, subject_id, properties, occurred_at, created_at) values (2, ?, 'Player', ?, '{}', ?, ?)");
$actions = ['player_updated', 'document_received', 'payment_recorded', 'page_viewed'];
$ids = array_keys($players);
for ($i = 0; $i < 30000 * max(1, intdiv($seasons, 2)); $i++) {
    $at = date('Y-m-d H:i:s', time() - mt_rand(0, $seasons * 365 * 86400));
    $log->execute([$actions[$i % 4], $ids[array_rand($ids)], $at, $at]);
}

$db->commit();
foreach (['players', 'player_subscriptions', 'transactions', 'training_sessions', 'attendances', 'activity_logs'] as $table) {
    printf("%-22s %8d\n", $table, $db->query("select count(*) from $table")->fetchColumn());
}
printf("done in %.0f s, %.0f MB\n", microtime(true) - $started, filesize($real) / 1048576);
