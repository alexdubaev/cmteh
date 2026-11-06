<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class CatalogImport
{
    private const EMPTY_COUNTS = ['added' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => 0];
    private readonly string $checkpointTable;
    private readonly string $lockName;

    public function __construct(private readonly PDO $pdo, private readonly Catalog $catalog, string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,32}$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid database prefix');
        }
        $this->checkpointTable = "`{$prefix}cmteh_import`";
        $database = $pdo->query('SELECT DATABASE()')->fetchColumn();
        $this->lockName = 'cmteh-import-' . substr(hash('sha256', $database . ':' . $prefix), 0, 40);
    }

    public function preview(string $path, bool $allowUnpublish = false): array
    {
        $file = $this->open($path);
        try {
            $fingerprint = $this->fingerprint($file);
            return $this->inspect($file, $fingerprint, $allowUnpublish);
        } finally {
            fclose($file);
        }
    }

    public function apply(
        string $path, string $fingerprint, string $runKey, bool $resume = false,
        bool $allowUnpublish = false, int $batchSize = 200, ?int $maxBatches = null
    ): array {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runKey) || $batchSize < 1 || $batchSize > 1000 || ($maxBatches !== null && $maxBatches < 1)) {
            throw new InvalidArgumentException('Неверный ключ или размер пакета');
        }
        $file = $this->open($path);
        $locked = false;
        try {
            $actual = $this->fingerprint($file);
            if (!hash_equals($actual, $fingerprint)) {
                throw new InvalidArgumentException('Файл изменился после предпросмотра');
            }
            $locked = $this->execute('SELECT GET_LOCK(?, 0)', [$this->lockName])->fetchColumn() == 1;
            if (!$locked) {
                throw new RuntimeException('Импорт уже выполняется');
            }
            $state = $this->execute("SELECT * FROM {$this->checkpointTable} WHERE run_key = ?", [$runKey])->fetch(PDO::FETCH_ASSOC);
            if ($resume && (!$state || !hash_equals($state['fingerprint'], $fingerprint))) {
                throw new InvalidArgumentException('Контрольная точка для этого файла не найдена');
            }
            if (!$resume && $state) {
                throw new InvalidArgumentException('Ключ уже использован; для продолжения добавьте --resume');
            }
            $preview = $this->inspect($file, $fingerprint, $allowUnpublish);
            if ($preview['errors'] > 0) {
                throw new InvalidArgumentException('Исправьте ошибки предпросмотра перед применением');
            }
            if (!$state) {
                $state = ['next_offset' => 0, 'next_line' => 1, 'finished' => 0, 'counts' => json_encode(self::EMPTY_COUNTS)];
                $this->execute("INSERT INTO {$this->checkpointTable} (run_key, fingerprint, next_offset, next_line, counts, finished)
                    VALUES (?, ?, 0, 1, ?, 0)", [$runKey, $fingerprint, $state['counts']]);
            }
            $counts = json_decode($state['counts'], true, 16, JSON_THROW_ON_ERROR);
            $line = (int) $state['next_line'];
            $finished = (bool) $state['finished'];
            $nextOffset = (int) $state['next_offset'];
            if (fseek($file, $nextOffset) !== 0) {
                throw new RuntimeException('Не удалось прочитать контрольную точку');
            }

            $batches = 0;
            while (!$finished && ($maxBatches === null || $batches < $maxBatches)) {
                $this->pdo->beginTransaction();
                try {
                    for ($i = 0; $i < $batchSize; $i++) {
                        $raw = $this->line($file);
                        if ($raw === null) {
                            $finished = true;
                            break;
                        }
                        if (trim($raw) !== '') {
                            [$type, $data] = $this->record($raw);
                            $counts[$this->catalog->save($type, $data)]++;
                        }
                        $line++;
                    }
                    $nextOffset = ftell($file);
                    $this->execute("UPDATE {$this->checkpointTable} SET next_offset = ?, next_line = ?, counts = ?, finished = ? WHERE run_key = ?",
                        [$nextOffset, $line, json_encode($counts, JSON_THROW_ON_ERROR), (int) $finished, $runKey]);
                    $this->pdo->commit();
                    $batches++;
                } catch (Throwable $error) {
                    $this->pdo->rollBack();
                    $public = $error instanceof InvalidArgumentException ? ': ' . $error->getMessage() : '';
                    throw new RuntimeException("Строка $line: пакет отменён. Повторите с --resume$public", previous: $error);
                }
            }
            return $counts + ['run_key' => $runKey, 'sha256' => $fingerprint, 'next_line' => $line, 'finished' => $finished];
        } finally {
            if ($locked) {
                $this->execute('SELECT RELEASE_LOCK(?)', [$this->lockName]);
            }
            fclose($file);
        }
    }

    private function inspect($file, string $fingerprint, bool $allowUnpublish): array
    {
        rewind($file);
        $counts = self::EMPTY_COUNTS;
        $errors = [];
        $changes = [];
        $ids = [];
        $slugs = [];
        $line = 0;
        $withdrawalOnly = null;
        while (($raw = $this->line($file)) !== null) {
            $line++;
            if (trim($raw) === '') {
                continue;
            }
            try {
                [$type, $data] = $this->record($raw);
                if ($type === 'unpublish' && !$allowUnpublish) {
                    throw new InvalidArgumentException('Снятие с публикации требует --allow-unpublish');
                }
                $withdrawal = $type === 'unpublish';
                $withdrawalOnly ??= $withdrawal;
                if ($withdrawalOnly !== $withdrawal) {
                    throw new InvalidArgumentException('Снятие с публикации нужно передавать отдельным файлом');
                }
                $row = $this->catalog->validate($type, $data);
                $id = $row['id'] ?? $row['uuid'] ?? $row['path'];
                $key = "$type:$id";
                if (isset($ids[$key])) {
                    throw new InvalidArgumentException('Повтор исходного идентификатора в файле');
                }
                if (isset($row['slug'])) {
                    $slug = "$type:" . $row['slug'];
                    if (isset($slugs[$slug]) && $slugs[$slug] !== $id) {
                        throw new InvalidArgumentException('Повтор slug в файле');
                    }
                    $this->catalog->assertSlugAvailable($type, $row);
                    $slugs[$slug] = $id;
                }
                if ($type === 'product') {
                    foreach (['brand' => $row['brand_slug'], 'category' => $row['category_slug']] as $taxonomy => $value) {
                        if ($value !== null && !isset($slugs["$taxonomy:$value"]) && !$this->catalog->taxonomyExists($taxonomy, $value)) {
                            throw new InvalidArgumentException("Не найдено сопоставление $taxonomy: $value");
                        }
                    }
                }
                $ids[$key] = true;
                $exists = $this->catalog->existing($type, $row);
                if ($type === 'unpublish') {
                    $visible = $exists && ($this->catalog->editRecord('product', $id)['status'] ?? null) === 'published';
                    $counts[$visible ? 'updated' : 'skipped']++;
                    $changes[] = ['line' => $line, 'id' => $id, 'reason' => $visible ? 'снятие с публикации' : 'позиция отсутствует или уже скрыта'];
                } else {
                    $counts[$exists ? 'updated' : 'added']++;
                }
            } catch (InvalidArgumentException | \JsonException $error) {
                $counts['errors']++;
                if (count($errors) < 250) {
                    $errors[] = ['line' => $line, 'reason' => $error->getMessage()];
                }
            }
        }
        if ($counts['added'] + $counts['updated'] + $counts['skipped'] + $counts['errors'] === 0) {
            $counts['errors']++;
            $errors[] = ['line' => 0, 'reason' => 'В файле нет записей'];
        }
        return $counts + ['sha256' => $fingerprint, 'diagnostics' => $errors, 'publication_changes' => $changes];
    }

    private function record(string $raw): array
    {
        if (strlen($raw) > 1048576) {
            throw new InvalidArgumentException('Строка превышает 1 МиБ');
        }
        $row = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($row) || !is_string($row['type'] ?? null) || !is_array($row['data'] ?? null) || array_is_list($row['data'])) {
            throw new InvalidArgumentException('Ожидается объект NDJSON с type и data');
        }
        return [$row['type'], $row['data']];
    }

    private function line($file): ?string
    {
        $raw = fgets($file, 1048578);
        if ($raw === false && !feof($file)) {
            throw new RuntimeException('Ошибка чтения файла');
        }
        if ($raw !== false && strlen($raw) > 1048576 && !str_ends_with($raw, "\n")) {
            do {
                $tail = fgets($file, 1048578);
            } while ($tail !== false && !str_ends_with($tail, "\n"));
        }
        return $raw === false ? null : $raw;
    }

    private function open(string $path)
    {
        if (!is_file($path) || is_link($path)) {
            throw new InvalidArgumentException('Требуется локальный NDJSON файл');
        }
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Не удалось открыть входной файл');
        }
        if (!flock($file, LOCK_SH | LOCK_NB)) {
            fclose($file);
            throw new RuntimeException('Файл занят другой операцией');
        }
        return $file;
    }

    private function fingerprint($file): string
    {
        rewind($file);
        $hash = hash_init('sha256');
        hash_update_stream($hash, $file);
        return hash_final($hash);
    }

    private function execute(string $sql, array $params): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return $statement;
    }
}
