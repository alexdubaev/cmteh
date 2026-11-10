<?php
declare(strict_types=1);
namespace Cmteh;
use PDO;
use RuntimeException;
use Throwable;
require_once __DIR__ . '/Quote.php';

final class QuoteOutbox
{
    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) { throw new \InvalidArgumentException('Invalid quote prefix.'); }
    }

    public function run(callable $send, int $limit = 5): array
    {
        $t = $this->prefix . 'cmteh_quote';
        $lock = hash('sha256', 'cmteh-quote-outbox:' . $this->pdo->query('SELECT DATABASE()')->fetchColumn() . ':' . $this->prefix);
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?,0)'); $stmt->execute([$lock]); $acquired = $stmt->fetchColumn();
        if ($acquired === null || $acquired === false) { throw new RuntimeException('Quote worker lock unavailable.'); }
        $counts = ['busy' => (int) $acquired !== 1, 'sent' => 0, 'retry' => 0, 'failed' => 0];
        if ($counts['busy']) { return $counts; }
        try {
            // A claim is persisted before transport. Expired claims retain their attempt budget.
            $counts['failed'] = (int) $this->pdo->query("SELECT COUNT(*) FROM {$t}_outbox
                WHERE status='sending' AND attempts>=3 AND available_at<=UTC_TIMESTAMP()")->fetchColumn();
            $this->pdo->exec("UPDATE {$t}_outbox SET status=IF(attempts>=3,'failed','retry'),last_error='delivery_not_confirmed'
                WHERE status='sending' AND available_at<=UTC_TIMESTAMP()");
            $limit = max(1, min(10, $limit));
            $jobs = $this->pdo->query("SELECT q.public_id,o.quote_id,o.attempts FROM {$t}_outbox o
                JOIN {$t} q ON q.quote_id=o.quote_id WHERE o.status IN ('pending','retry')
                AND o.available_at<=UTC_TIMESTAMP() AND o.attempts<3 ORDER BY o.available_at,o.quote_id LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);
            $service = new Quote($this->pdo, $this->prefix); $start = hrtime(true);
            foreach ($jobs as $job) {
                if (hrtime(true) - $start > 15000000000) { break; }
                $attempt = (int) $job['attempts'] + 1;
                $this->pdo->prepare("UPDATE {$t}_outbox SET status='sending',attempts=?,
                    available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 300 SECOND),last_error='delivery_not_confirmed' WHERE quote_id=?")
                    ->execute([$attempt,$job['quote_id']]);
                try {
                    $quote = $service->find($job['public_id']);
                    $quote['attachment'] = $service->attachment($job['public_id']);
                    if ($quote['input_kind'] === 'file' && $quote['attachment'] === null) { throw new RuntimeException('Original unavailable.'); }
                    $send($quote);
                } catch (Throwable $e) {
                    $status = $attempt < 3 ? 'retry' : 'failed'; $delay = [60,300,1800][$attempt-1];
                    $this->pdo->prepare("UPDATE {$t}_outbox SET status=?,available_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL ? SECOND)
                        WHERE quote_id=?")->execute([$status,$delay,$job['quote_id']]);
                    $counts[$status]++; continue;
                }
                // Failure after MTA acceptance leaves sending+lease: original remains, retry keeps Message-ID.
                $this->pdo->prepare("UPDATE {$t}_outbox SET status='sent',sent_at=UTC_TIMESTAMP(),last_error=NULL WHERE quote_id=?")
                    ->execute([$job['quote_id']]);
                $counts['sent']++;
            }
            return $counts;
        } finally { $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lock]); }
    }
}
