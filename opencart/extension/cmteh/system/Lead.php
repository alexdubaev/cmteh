<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/LeadInput.php';
require_once __DIR__ . '/Catalog.php';

final class Lead
{
    private const SCHEMA_VERSION = 2;
    private const GLOBAL_LIMIT = 240;
    private const CLIENT_LIMIT = 12;

    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid lead table prefix.');
        }
    }

    public function migrate(): void
    {
        $table = $this->prefix . 'cmteh_lead';
        $options = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table}_schema (
            id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
            version INT UNSIGNED NOT NULL
        ) $options");
        $version = (int) $this->pdo->query("SELECT version FROM {$table}_schema WHERE id = 1")->fetchColumn();
        if ($version > self::SCHEMA_VERSION) {
            throw new RuntimeException('Unsupported lead schema version.');
        }
        if ($version === self::SCHEMA_VERSION) {
            return;
        }

        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (
            lead_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            public_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            request_key CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
            fingerprint CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            name VARCHAR(100) NOT NULL,
            phone VARCHAR(40),
            email VARCHAR(254),
            company VARCHAR(1000),
            message TEXT,
            page_url VARCHAR(1000),
            created_at DATETIME NOT NULL
        ) $options");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table}_item (
            lead_id BIGINT UNSIGNED NOT NULL,
            position SMALLINT UNSIGNED NOT NULL,
            article VARCHAR(128) NOT NULL,
            quantity INT UNSIGNED NOT NULL,
            PRIMARY KEY (lead_id, position),
            FOREIGN KEY (lead_id) REFERENCES {$table} (lead_id)
        ) $options");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table}_outbox (
            lead_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
            status ENUM('pending','retry','sent','failed') NOT NULL DEFAULT 'pending',
            attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
            available_at DATETIME NOT NULL,
            sent_at DATETIME,
            last_error VARCHAR(64) CHARACTER SET ascii,
            KEY due (status, available_at, lead_id),
            FOREIGN KEY (lead_id) REFERENCES {$table} (lead_id)
        ) $options");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS {$table}_rate (
            window_minute BIGINT UNSIGNED NOT NULL,
            scope_key BINARY(32) NOT NULL,
            hits SMALLINT UNSIGNED NOT NULL,
            PRIMARY KEY (window_minute, scope_key)
        ) $options");
        $columns = $this->pdo->query("SHOW COLUMNS FROM {$table}_item")->fetchAll(PDO::FETCH_COLUMN);
        foreach (['product_id' => 'VARCHAR(128)', 'product_sku' => 'VARCHAR(128)',
            'product_brand' => 'VARCHAR(255)', 'product_title' => 'VARCHAR(255)'] as $column => $type) {
            if (!in_array($column, $columns, true)) {
                $this->pdo->exec("ALTER TABLE {$table}_item ADD COLUMN $column $type NULL");
            }
        }
        $this->pdo->exec("INSERT INTO {$table}_schema (id, version) VALUES (1, 2)
            ON DUPLICATE KEY UPDATE version = 2");
    }

    /**
     * The router reads at most 512001 bytes and verifies request method and origin.
     *
     * @return array{status: int, body: array, headers?: array<string, string>}
     */
    public function submit(string $raw, string $clientAddress, array $headers = []): array
    {
        if (strlen($raw) > 512000) {
            return self::error(413, 'Заявка слишком большая.');
        }
        $contentType = $headers['content-type'] ?? '';
        if (!is_string($contentType) || strtolower(trim(explode(';', $contentType, 2)[0])) !== 'application/json') {
            return self::error(415, 'Отправьте заявку в формате JSON.');
        }

        try {
            $now = time();
            if (!$this->allowAttempt($clientAddress, $now)) {
                return self::error(429, 'Слишком много запросов. Попробуйте чуть позже.')
                    + ['headers' => ['Retry-After' => (string) (60 - $now % 60)]];
            }
            [$key, $data] = LeadInput::parse($raw);

            return $this->save($key, $data);
        } catch (InvalidArgumentException $error) {
            return self::error(400, $error->getMessage());
        } catch (Throwable $error) {
            error_log('CMTEH lead failed (' . get_class($error) . ')');

            return self::error(503, 'Сохранение заявки не подтверждено. Повторите отправку.');
        }
    }

    public function list(int $page): array
    {
        $table = $this->prefix . 'cmteh_lead';
        $total = (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
        $pages = max(1, (int) ceil($total / 50));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * 50;
        $rows = $this->pdo->query("SELECT l.public_id, l.name, l.company, l.created_at, o.status, o.attempts
            FROM {$table} l INNER JOIN {$table}_outbox o ON o.lead_id = l.lead_id
            ORDER BY l.lead_id DESC LIMIT 50 OFFSET $offset")->fetchAll(PDO::FETCH_ASSOC);

        return ['rows' => $rows, 'page' => $page, 'pages' => $pages, 'total' => $total];
    }

    public function find(string $publicId): ?array
    {
        $table = $this->prefix . 'cmteh_lead';
        $query = $this->pdo->prepare("SELECT l.lead_id, l.public_id, l.name, l.phone, l.email,
            l.company, l.message, l.page_url, l.created_at, o.status, o.attempts, o.sent_at, o.available_at
            FROM {$table} l INNER JOIN {$table}_outbox o ON o.lead_id = l.lead_id
            WHERE l.public_id = ?");
        $query->execute([$publicId]);
        $lead = $query->fetch(PDO::FETCH_ASSOC);
        if (!$lead) {
            return null;
        }
        $items = $this->pdo->prepare("SELECT article, quantity, product_id, product_sku, product_brand, product_title FROM {$table}_item
            WHERE lead_id = ? ORDER BY position");
        $items->execute([$lead['lead_id']]);
        unset($lead['lead_id']);
        $lead['items'] = $items->fetchAll(PDO::FETCH_ASSOC);

        return $lead;
    }

    private function allowAttempt(string $address, int $now): bool
    {
        $table = $this->prefix . 'cmteh_lead_rate';
        $minute = intdiv($now, 60);
        $packed = filter_var($address, FILTER_VALIDATE_IP) ? inet_pton($address) : '';
        if (strlen($packed) === 16) {
            $packed = str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")
                ? substr($packed, 12)
                : substr($packed, 0, 8);
        }

        $this->pdo->beginTransaction();
        try {
            $global = $this->countAttempt($table, $minute, hash('sha256', 'all', true), self::GLOBAL_LIMIT);
            if ($global === 1) {
                $prune = $this->pdo->prepare("DELETE FROM {$table} WHERE window_minute < ?
                    ORDER BY window_minute LIMIT 512");
                $prune->execute([$minute - 1]);
            }
            $allowed = $global <= self::GLOBAL_LIMIT
                && $this->countAttempt(
                    $table,
                    $minute,
                    hash('sha256', 'ip:' . $packed, true),
                    self::CLIENT_LIMIT
                ) <= self::CLIENT_LIMIT;
            $this->pdo->commit();

            return $allowed;
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function countAttempt(string $table, int $minute, string $scope, int $limit): int
    {
        $update = $this->pdo->prepare("INSERT INTO {$table} (window_minute, scope_key, hits) VALUES (?, ?, 1)
            ON DUPLICATE KEY UPDATE hits = LEAST(hits + 1, ?)");
        $update->execute([$minute, $scope, $limit + 1]);
        $count = $this->pdo->prepare("SELECT hits FROM {$table} WHERE window_minute = ? AND scope_key = ?");
        $count->execute([$minute, $scope]);

        return (int) $count->fetchColumn();
    }

    private function save(string $key, array $data): array
    {
        $table = $this->prefix . 'cmteh_lead';
        $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $publicId = LeadInput::uuid();
        $this->pdo->beginTransaction();
        try {
            $insert = $this->pdo->prepare("INSERT INTO {$table}
                (public_id, request_key, fingerprint, name, phone, email, company, message, page_url, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE lead_id = lead_id");
            $insert->execute([$publicId, $key, $fingerprint, $data['name'], $data['phone'], $data['email'],
                $data['company'], $data['message'], $data['page_url']]);

            // The unique insert obtains the key lock. Read the winning row within that lock.
            $find = $this->pdo->prepare("SELECT lead_id, public_id, fingerprint FROM {$table}
                WHERE request_key = ? FOR UPDATE");
            $find->execute([$key]);
            $saved = $find->fetch(PDO::FETCH_ASSOC);
            if (!$saved) {
                throw new RuntimeException('Lead key was not persisted.');
            }
            if (!hash_equals($saved['fingerprint'], $fingerprint)) {
                $this->pdo->rollBack();

                return self::error(409, 'Этот идентификатор уже относится к другой заявке.');
            }

            $replayed = $saved['public_id'] !== $publicId;
            if (!$replayed) {
                $this->saveItems($table, (string) $saved['lead_id'], $data['items']);
                $notify = $this->pdo->prepare("INSERT INTO {$table}_outbox (lead_id, available_at)
                    VALUES (?, UTC_TIMESTAMP())");
                $notify->execute([$saved['lead_id']]);
            }
            $this->pdo->commit();

            return ['status' => 200, 'body' => ['id' => $saved['public_id'], 'replayed' => $replayed]];
        } catch (Throwable $error) {
            $this->rollback();
            throw $error;
        }
    }

    private function saveItems(string $table, string $leadId, array $items): void
    {
        $ids = array_column($items, 'product_id');
        $products = $ids ? (new Catalog($this->pdo, $this->prefix))->selectedProducts($ids) : [];
        foreach ($items as $item) {
            if (!isset($item['product_id'])) continue;
            $product = $products[$item['product_id']] ?? null;
            if (!$product || CatalogInput::normalize($product['sku']) !== CatalogInput::normalize($item['article'])) {
                throw new InvalidArgumentException('Выбранный товар недоступен или артикул изменился. Проверьте корзину.');
            }
        }
        $insert = $this->pdo->prepare("INSERT INTO {$table}_item
            (lead_id, position, article, quantity, product_id, product_sku, product_brand, product_title)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $position => $item) {
            $product = isset($item['product_id']) ? $products[$item['product_id']] : null;
            $insert->execute([$leadId, $position + 1, $item['article'], $item['quantity'],
                $product['id'] ?? null, $product['sku'] ?? null, $product['brand'] ?? null, $product['title'] ?? null]);
        }
    }

    private function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    private static function error(int $status, string $message): array
    {
        return ['status' => $status, 'body' => ['error' => $message]];
    }
}
