<?php
declare(strict_types=1);
namespace Cmteh;

use PDO;
use Throwable;
use InvalidArgumentException;
use RuntimeException;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/QuoteInput.php';

final class Quote
{
    public const JSON_BYTES = 8388608;
    public const QUOTA_BYTES = 268435456;
    private string $table;

    public function __construct(private readonly PDO $pdo, string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) { throw new InvalidArgumentException('Invalid quote prefix.'); }
        $this->table = $prefix . 'cmteh_quote';
    }

    public function migrate(): void
    {
        $t = $this->table;
        $o = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t} (
            quote_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            request_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(100) NOT NULL, phone VARCHAR(40), email VARCHAR(254), message TEXT,
            page_url VARCHAR(1000), input_kind ENUM('text','file') NOT NULL, list_text MEDIUMTEXT,
            filename VARCHAR(180), mime VARCHAR(100), file_hash CHAR(64) CHARACTER SET ascii,
            file_size INT UNSIGNED NOT NULL, attachment_deleted_at DATETIME, created_at DATETIME NOT NULL
        ) $o");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t}_attachment (
            quote_id BIGINT UNSIGNED NOT NULL PRIMARY KEY, bytes MEDIUMBLOB NOT NULL,
            FOREIGN KEY (quote_id) REFERENCES {$t}(quote_id)
        ) $o");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t}_outbox (
            quote_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            status ENUM('pending','sending','retry','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0, available_at DATETIME NOT NULL,
            sent_at DATETIME, last_error VARCHAR(64) CHARACTER SET ascii,
            KEY due(status,available_at,quote_id), FOREIGN KEY (quote_id) REFERENCES {$t}(quote_id)
        ) $o");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t}_quota (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY, used_bytes BIGINT UNSIGNED NOT NULL
        ) $o");
        $this->pdo->exec("INSERT IGNORE INTO {$t}_quota VALUES(1,0)");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$t}_rate (
            window_minute BIGINT UNSIGNED NOT NULL, scope_key BINARY(32) NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL, PRIMARY KEY(window_minute,scope_key)
        ) $o");
    }

    public function submit(string $raw, string $clientAddress, array $headers = []): array
    {
        if (strlen($raw) > self::JSON_BYTES) { return self::error(413, 'Расценка слишком большая (до 8 МиБ).'); }
        if (strtolower(trim(explode(';', (string) ($headers['content-type'] ?? ''), 2)[0])) !== 'application/json') {
            return self::error(415, 'Отправьте расценку в формате JSON.');
        }
        try {
            if (!$this->allowAttempt($clientAddress)) { return self::error(429, 'Слишком много запросов. Повторите через минуту.') + ['headers' => ['Retry-After' => '60']]; }
            [$key, $data] = QuoteInput::parse($raw);
            return $this->save($key, $data);
        } catch (InvalidArgumentException $e) { return self::error(400, $e->getMessage()); }
        catch (Throwable $e) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            error_log('CMTEH quote save failed (' . get_class($e) . ')');
            return self::error(503, 'Сохранение расценки не подтверждено. Повторите отправку.');
        }
    }

    private function save(string $key, array $data): array
    {
        $t = $this->table;
        $bytes = $data['bytes'];
        unset($data['bytes']);
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $id = LeadInput::uuid();
        $this->pdo->beginTransaction();
        // Lock quota first: one bounded transaction covers key, payload and job; replays cost zero.
        $used = (int) $this->pdo->query("SELECT used_bytes FROM {$t}_quota WHERE id=1 FOR UPDATE")->fetchColumn();
        $find = $this->pdo->prepare("SELECT public_id,fingerprint FROM {$t} WHERE request_key=? FOR UPDATE");
        $find->execute([$key]);
        $saved = $find->fetch(PDO::FETCH_ASSOC);
        if ($saved) {
            $this->pdo->commit();
            return hash_equals($saved['fingerprint'], $fingerprint)
                ? ['status' => 200, 'body' => ['id' => $saved['public_id'], 'replayed' => true]]
                : self::error(409, 'Идентификатор уже относится к другой расценке.');
        }
        $size = $data['file_size'] + strlen($data['list_text'] ?? '');
        if ($used + $size > self::QUOTA_BYTES) {
            $this->pdo->rollBack();
            return self::error(503, 'Приём расценок временно заполнен. Свяжитесь с нами или повторите позже.');
        }
        $columns = implode(',', array_keys($data));
        $marks = implode(',', array_fill(0, count($data), '?'));
        $insert = $this->pdo->prepare("INSERT INTO {$t}(public_id,request_key,fingerprint,{$columns},created_at)
            VALUES(?,?,?,{$marks},UTC_TIMESTAMP())");
        $insert->execute([$id, $key, $fingerprint, ...array_values($data)]);
        $quoteId = $this->pdo->lastInsertId();
        if ($bytes !== null) {
            $insert = $this->pdo->prepare("INSERT INTO {$t}_attachment VALUES(?,?)");
            $insert->bindValue(1, $quoteId);
            $insert->bindValue(2, $bytes, PDO::PARAM_LOB);
            $insert->execute();
        }
        $this->pdo->prepare("INSERT INTO {$t}_outbox(quote_id,available_at) VALUES(?,UTC_TIMESTAMP())")->execute([$quoteId]);
        $this->pdo->prepare("UPDATE {$t}_quota SET used_bytes=used_bytes+? WHERE id=1")->execute([$size]);
        $this->pdo->commit();
        return ['status' => 200, 'body' => ['id' => $id, 'replayed' => false]];
    }

    public function list(int $page): array
    {
        $t = $this->table;
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$t}")->fetchColumn();
        $pages = max(1, (int) ceil($total / 50));
        $page = max(1, min($page, $pages)); $offset = ($page - 1) * 50;
        $rows = $this->pdo->query("SELECT q.public_id,q.name,q.phone,q.email,q.input_kind,q.filename,q.created_at,o.status,o.attempts
            FROM {$t} q JOIN {$t}_outbox o ON o.quote_id=q.quote_id ORDER BY q.quote_id DESC LIMIT 50 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);
        return compact('rows', 'total', 'page', 'pages');
    }

    public function find(string $publicId): ?array
    {
        $t = $this->table;
        $stmt = $this->pdo->prepare("SELECT q.public_id,q.name,q.phone,q.email,q.message,q.page_url,q.input_kind,q.list_text,
            q.filename,q.mime,q.file_hash,q.file_size,q.attachment_deleted_at,q.created_at,o.status,o.attempts,o.available_at,o.sent_at
            FROM {$t} q JOIN {$t}_outbox o ON o.quote_id=q.quote_id WHERE q.public_id=?");
        $stmt->execute([$publicId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function attachment(string $publicId): ?array
    {
        $t = $this->table;
        $stmt = $this->pdo->prepare("SELECT q.filename,q.mime,q.file_hash,q.file_size,a.bytes
            FROM {$t} q JOIN {$t}_attachment a ON a.quote_id=q.quote_id WHERE q.public_id=?");
        $stmt->execute([$publicId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function retry(string $publicId): bool
    {
        $t = $this->table;
        $stmt = $this->pdo->prepare("UPDATE {$t}_outbox o JOIN {$t} q ON q.quote_id=o.quote_id
            SET o.status='retry',o.attempts=0,o.available_at=UTC_TIMESTAMP(),o.last_error=NULL
            WHERE q.public_id=? AND o.status='failed' AND q.attachment_deleted_at IS NULL");
        $stmt->execute([$publicId]); return $stmt->rowCount() === 1;
    }

    public function deleteAttachment(string $publicId): bool
    {
        $t = $this->table;
        $this->pdo->beginTransaction();
        try {
            $this->pdo->query("SELECT used_bytes FROM {$t}_quota WHERE id=1 FOR UPDATE")->fetchColumn();
            $stmt = $this->pdo->prepare("SELECT q.quote_id,q.file_size FROM {$t} q JOIN {$t}_outbox o ON o.quote_id=q.quote_id
                JOIN {$t}_attachment a ON a.quote_id=q.quote_id WHERE q.public_id=? AND o.status='sent' FOR UPDATE");
            $stmt->execute([$publicId]); $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) { $this->pdo->rollBack(); return false; }
            $this->pdo->prepare("DELETE FROM {$t}_attachment WHERE quote_id=?")->execute([$row['quote_id']]);
            $this->pdo->prepare("UPDATE {$t} SET attachment_deleted_at=UTC_TIMESTAMP() WHERE quote_id=?")->execute([$row['quote_id']]);
            $this->pdo->prepare("UPDATE {$t}_quota SET used_bytes=used_bytes-? WHERE id=1")->execute([$row['file_size']]);
            $this->pdo->commit(); return true;
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); } throw $e; }
    }

    private function allowAttempt(string $address): bool
    {
        $t = $this->table . '_rate'; $minute = intdiv(time(), 60);
        $ip = filter_var($address, FILTER_VALIDATE_IP) ? inet_pton($address) : '';
        if (strlen($ip) === 16) { $ip = str_starts_with($ip, str_repeat("\0", 10) . "\xff\xff") ? substr($ip,12) : substr($ip,0,8); }
        $this->pdo->beginTransaction();
        $allowed = true;
        foreach (['all' => 120, 'ip:' . $ip => 12] as $scope => $limit) {
            $hash = hash('sha256', $scope, true);
            $this->pdo->prepare("INSERT INTO {$t} VALUES(?,?,1) ON DUPLICATE KEY UPDATE hits=LEAST(hits+1,?)")->execute([$minute,$hash,$limit+1]);
            $stmt = $this->pdo->prepare("SELECT hits FROM {$t} WHERE window_minute=? AND scope_key=?");
            $stmt->execute([$minute,$hash]); $allowed = (int) $stmt->fetchColumn() <= $limit && $allowed;
        }
        $this->pdo->prepare("DELETE FROM {$t} WHERE window_minute<? ORDER BY window_minute LIMIT 256")->execute([$minute-1]);
        $this->pdo->commit(); return $allowed;
    }

    private static function error(int $status, string $message): array { return ['status' => $status, 'body' => ['error' => $message]]; }
}
