<?php

declare(strict_types=1);

require '/var/www/html/config.php';
require_once __DIR__ . '/../extension/cmteh/system/Lead.php';
require_once __DIR__ . '/../extension/cmteh/system/LeadOutbox.php';
require_once __DIR__ . '/../extension/cmteh/system/LeadMail.php';

use Cmteh\Database;
use Cmteh\Lead;
use Cmteh\LeadMail;
use Cmteh\LeadOutbox;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    echo "PASS: $message\n";
}

function payload(): array
{
    return [
        'request_key' => '4c6c5a2f-7ec9-4f01-803e-3435f4fe8d7a',
        'name' => 'Пример Покупателя',
        'phone' => '+7 (000) 000-00-00',
        'email' => '',
        'company' => 'Тестовая компания',
        'message' => 'Проверка сохранения',
        'page_url' => 'http://localhost/request',
        'request_items' => [['article' => '001A', 'quantity' => 2, 'price' => 1]],
    ];
}

function submit(Lead $lead, array $payload, string $ip = '192.0.2.1'): array
{
    return $lead->submit(json_encode($payload, JSON_THROW_ON_ERROR), $ip, ['content-type' => 'application/json; charset=UTF-8']);
}

function countRows(PDO $pdo, string $table): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
}

function race(PDO $pdo, string $prefix, string $key): array
{
    $table = $prefix . 'cmteh_lead';
    $children = [];
    try {
        // A gap lock keeps both real MySQL insert attempts in flight until we observe them.
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $gap = $pdo->prepare("SELECT lead_id FROM $table WHERE request_key = ? FOR UPDATE");
        $gap->execute([$key]);

        foreach (['192.0.2.10', '192.0.2.11'] as $ip) {
            $process = proc_open(
                [PHP_BINARY, __FILE__, '--race-child', $prefix, $key, $ip],
                [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
                $pipes
            );
            if (!is_resource($process)) {
                throw new RuntimeException('Could not launch the parallel submission.');
            }
            $children[] = [$process, $pipes];
            stream_set_timeout($pipes[1], 8);
            stream_set_timeout($pipes[2], 8);
            if (fgets($pipes[1]) !== "ready\n") {
                throw new RuntimeException('A parallel connection did not become ready.');
            }
        }
        foreach ($children as [, $pipes]) {
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);
        }

        $until = hrtime(true) + 3000000000;
        do {
            $waiting = 0;
            foreach ($pdo->query('SHOW PROCESSLIST')->fetchAll(PDO::FETCH_ASSOC) as $session) {
                if (preg_match('/^INSERT INTO ' . preg_quote($table, '/') . '\s*\(/i', (string) $session['Info'])) {
                    $waiting++;
                }
            }
            if ($waiting >= 2) {
                break;
            }
            usleep(20000);
        } while (hrtime(true) < $until);

        check($waiting === 2, 'Two independent connections reached the same MySQL insert concurrently');
        $pdo->commit();

        $results = [];
        foreach ($children as [$process, $pipes]) {
            $raw = fgets($pipes[1]);
            if ($raw === false) {
                throw new RuntimeException('A parallel submission did not complete.');
            }
            $results[] = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        foreach ($children as [$process, $pipes]) {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            if (proc_get_status($process)['running']) {
                proc_terminate($process);
            }
            proc_close($process);
        }
    }
}

if (($argv[1] ?? '') === '--race-child') {
    [, , $prefix, $key, $ip] = $argv;
    if (!preg_match('/^lt_[a-f0-9]{12}_$/D', $prefix)) {
        exit(2);
    }
    $lead = new Lead(Database::connectFromOpenCart(), $prefix);
    $data = array_replace(payload(), ['request_key' => $key]);
    echo "ready\n";
    fflush(STDOUT);
    if (fgets(STDIN) !== "go\n") {
        exit(2);
    }
    echo json_encode(submit($lead, $data, $ip), JSON_THROW_ON_ERROR) . "\n";
    exit;
}

$pdo = Database::connectFromOpenCart();
$prefix = 'lt_' . bin2hex(random_bytes(6)) . '_';
$table = $prefix . 'cmteh_lead';
$headers = ['content-type' => 'application/json; charset=UTF-8'];
$lead = new Lead($pdo, $prefix);
$payload = payload();
$captureRoot = sys_get_temp_dir() . '/' . $prefix . 'capture';
$captureFile = $captureRoot . '/lead-test-capture/notices.ndjson';

try {
    $lead->migrate();
    $lead->migrate();
    $accepted = submit($lead, $payload);
    check($accepted['status'] === 200 && $accepted['body']['replayed'] === false
        && is_string($accepted['body']['id']), 'A new request is accepted and acknowledged with an ID');
    $id = $accepted['body']['id'];
    check(countRows($pdo, $table) === 1 && countRows($pdo, $table . '_outbox') === 1,
        'A persisted request includes one outbox record');
    $detail = $lead->find($id);
    check(count($detail['items']) === 1 && $detail['items'][0]['article'] === '001A'
        && (int) $detail['items'][0]['quantity'] === 2 && $detail['company'] === $payload['company']
        && $detail['message'] === $payload['message'] && $detail['status'] === 'pending',
        'The private detail retains the article, quantity, company, comment and delivery status');
    check($lead->list(999)['rows'][0]['public_id'] === $id && $lead->find('00000000-0000-0000-0000-000000000000') === null,
        'The manager list and detail handle page bounds and a missing ID');

    $duplicate = array_replace($payload, [
        'request_key' => strtoupper($payload['request_key']),
        'phone' => '+70000000000',
        'name' => '  ' . $payload['name'] . '  ',
        'request_items' => [['quantity' => 2, 'article' => '001A', 'price' => 1000]],
    ]);
    check(submit($lead, array_reverse($duplicate, true)) === [
        'status' => 200, 'body' => ['id' => $id, 'replayed' => true],
    ] && countRows($pdo, $table) === 1 && countRows($pdo, $table . '_outbox') === 1,
        'A normalized replay preserves one request and outbox, independent of field order or supplied price');

    $different = array_replace($payload, ['message' => 'Другой комментарий']);
    check(submit($lead, $different)['status'] === 409 && $lead->find($id)['message'] === $payload['message'],
        'Changed content with the same key is rejected with 409');

    $raceKey = 'c072ee21-7503-4b9e-b626-dd3150796aa5';
    [$one, $two] = race($pdo, $prefix, $raceKey);
    check($one['status'] === 200 && $two['status'] === 200
        && $one['body']['id'] === $two['body']['id'] && $one['body']['replayed'] !== $two['body']['replayed']
        && countRows($pdo, $table) === 2 && countRows($pdo, $table . '_item') === 2
        && countRows($pdo, $table . '_outbox') === 2,
        'Parallel identical requests store one lead, one snapshot and one notification');

    $contact = ['name' => 'Тестовый Контакт', 'email' => 'test@example.invalid', 'page_url' => 'http://localhost/contacts'];
    $acceptedContact = submit($lead, $contact, '192.0.2.2');
    check($acceptedContact['status'] === 200 && $lead->find($acceptedContact['body']['id'])['items'] === [],
        'The email-only contact form and omitted request key remain supported');
    check(submit($lead, array_replace($contact, ['page_url' => 'http://localhost/request']), '192.0.2.2')['status'] === 400,
        'An empty cart request is rejected');
    check($lead->submit(str_repeat('x', 512001), '192.0.2.2', $headers)['status'] === 413
        && $lead->submit('{}', '192.0.2.2', ['content-type' => 'text/plain'])['status'] === 415,
        'The byte limit and JSON content type are enforced');
    foreach ([
        ['website' => 'bot.example.invalid'],
        ['attachments' => []],
        ['request_key' => 'invalid-uuid'],
        ['request_items' => [['article' => str_repeat('x', 129), 'quantity' => 1]]],
        ['request_items' => [['article' => '001A', 'quantity' => '1']]],
        ['request_items' => array_fill(0, 101, ['article' => '001A', 'quantity' => 1])],
        ['name' => 'Я'],
        ['phone' => 'abcdefg'],
        ['page_url' => 'javascript:alert(1)'],
        ['message' => str_repeat('x', 3000)],
    ] as $position => $invalid) {
        if (submit($lead, array_replace($payload, $invalid), '192.0.2.3')['status'] !== 400) {
            throw new RuntimeException("Invalid lead case $position was accepted.");
        }
    }
    check(countRows($pdo, $table) === 3, 'Invalid shapes, limits, attachments and spam do not create records');

    $limited = false;
    for ($run = 0; $run < 3 && !$limited; $run++) {
        $minute = intdiv(time(), 60);
        $address = '198.51.100.' . ($run + 1);
        for ($attempt = 0; $attempt < 12; $attempt++) {
            if ($lead->submit('{', $address, $headers)['status'] !== 400) {
                throw new RuntimeException('Rate limit rejected a request before the quota.');
            }
        }
        $throttled = $lead->submit('{', $address, $headers);
        $limited = intdiv(time(), 60) === $minute && $throttled['status'] === 429
            && (int) $throttled['headers']['Retry-After'] >= 1;
    }
    check($limited, 'A client exceeding its quota receives 429 and Retry-After');

    // Force the final outbox insert to fail after the lead and items were written.
    $pdo->exec("RENAME TABLE {$table}_outbox TO {$table}_outbox_unavailable");
    try {
        $failed = submit($lead, array_replace($payload, [
            'request_key' => 'd3c67ab8-faca-4d82-8452-15169b92d7bd',
        ]), '192.0.2.4');
        check($failed === ['status' => 503, 'body' => [
            'error' => 'Сохранение заявки не подтверждено. Повторите отправку.',
        ]] && countRows($pdo, $table) === 3 && countRows($pdo, $table . '_item') === 2,
            'An outbox write failure rolls back the lead and items and returns a safe error');
    } finally {
        $pdo->exec("RENAME TABLE {$table}_outbox_unavailable TO {$table}_outbox");
    }

    $pdo->exec("UPDATE {$table}_outbox SET available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR)");
    $due = $pdo->prepare("UPDATE {$table}_outbox o JOIN $table l USING (lead_id)
        SET o.available_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE l.public_id = ?");
    $due->execute([$id]);
    $worker = new LeadOutbox($pdo, $prefix);
    $concurrent = new LeadOutbox(Database::connectFromOpenCart(), $prefix);
    $overlap = null;
    $broken = static function () use (&$overlap, $concurrent): void {
        $overlap = $concurrent->run(static function (): void {
            throw new RuntimeException('Unexpected concurrent delivery.');
        });
        throw new RuntimeException('Simulated SMTP outage.');
    };
    $result = $worker->run($broken);
    $status = $lead->find($id);
    check($result['retry'] === 1 && $overlap === ['busy' => true, 'sent' => 0, 'retry' => 0, 'failed' => 0]
        && $status['status'] === 'retry' && (int) $status['attempts'] === 1 && $status['sent_at'] === null,
        'An SMTP failure keeps the lead and schedules one retry while a concurrent worker skips the queue');

    $capture = LeadMail::capture($captureRoot, 'notices.ndjson');
    check($worker->run($capture)['sent'] === 0 && !is_file($captureFile),
        'The worker respects the retry delay');
    $due->execute([$id]);
    $delivered = $worker->run($capture);
    $status = $lead->find($id);
    check($delivered['sent'] === 1 && $status['status'] === 'sent' && (int) $status['attempts'] === 2
        && $status['sent_at'] !== null && json_decode(trim(file_get_contents($captureFile)), true) === ['id' => $id],
        'A retry delivers exactly one captured notification and updates the manager status');

    $failedId = $one['body']['id'];
    for ($attempt = 0; $attempt < 3; $attempt++) {
        $due->execute([$failedId]);
        $result = $worker->run($broken);
    }
    $status = $lead->find($failedId);
    check($result['failed'] === 1 && $status['status'] === 'failed' && (int) $status['attempts'] === 3
        && $worker->run($capture)['sent'] === 0 && countRows($pdo, $table) === 3,
        'After three delivery failures the worker stops retrying and preserves all requests');
} finally {
    foreach (['outbox_unavailable', 'outbox', 'item', 'rate', 'schema'] as $suffix) {
        $pdo->exec("DROP TABLE IF EXISTS {$table}_{$suffix}");
    }
    $pdo->exec("DROP TABLE IF EXISTS $table");
    if (is_file($captureFile)) {
        unlink($captureFile);
    }
    if (is_dir(dirname($captureFile))) {
        rmdir(dirname($captureFile));
    }
    if (is_dir($captureRoot)) {
        rmdir($captureRoot);
    }
}
