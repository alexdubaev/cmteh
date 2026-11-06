<?php

declare(strict_types=1);

namespace Cmteh;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class LeadOutbox
{
    private const RETRY_SECONDS = [60, 300, 1800];

    public function __construct(private readonly PDO $pdo, private readonly string $prefix)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{0,40}$/D', $prefix)) {
            throw new InvalidArgumentException('Invalid lead table prefix.');
        }
    }

    /**
     * The transport receives a public lead ID, without contact information.
     *
     * @return array{busy: bool, sent: int, retry: int, failed: int}
     */
    public function run(callable $send, int $limit = 5): array
    {
        $table = $this->prefix . 'cmteh_lead';
        $database = $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $lock = hash('sha256', "cmteh-lead-outbox:$database:$this->prefix");
        $acquire = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $acquire->execute([$lock]);
        $acquired = $acquire->fetchColumn();
        $counts = ['busy' => false, 'sent' => 0, 'retry' => 0, 'failed' => 0];
        if ($acquired === null || $acquired === false) {
            throw new RuntimeException('Lead worker could not acquire the database lock.');
        }
        if ((int) $acquired !== 1) {
            $counts['busy'] = true;

            return $counts;
        }

        try {
            $start = hrtime(true);
            $limit = min(10, max(1, $limit));
            $jobs = $this->pdo->query("SELECT o.lead_id, o.attempts, l.public_id
                FROM {$table}_outbox o INNER JOIN {$table} l ON l.lead_id = o.lead_id
                WHERE o.status IN ('pending', 'retry') AND o.available_at <= UTC_TIMESTAMP()
                ORDER BY o.available_at, o.lead_id LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);

            $claim = $this->pdo->prepare("UPDATE {$table}_outbox
                SET attempts = ?, status = ?, available_at = DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND),
                    last_error = 'delivery_not_confirmed'
                WHERE lead_id = ?");
            $sent = $this->pdo->prepare("UPDATE {$table}_outbox
                SET status = 'sent', sent_at = UTC_TIMESTAMP(), last_error = NULL WHERE lead_id = ?");
            foreach ($jobs as $job) {
                if (hrtime(true) - $start >= 15000000000) {
                    break;
                }
                $attempt = (int) $job['attempts'];
                $delay = self::RETRY_SECONDS[$attempt] ?? null;
                if ($delay === null) {
                    throw new RuntimeException('Invalid lead attempt count.');
                }

                // Persist the attempt before contacting SMTP. A crash cannot create unbounded retries.
                $status = $attempt + 1 < count(self::RETRY_SECONDS) ? 'retry' : 'failed';
                $claim->execute([$attempt + 1, $status, $delay, $job['lead_id']]);
                try {
                    $send($job['public_id']);
                } catch (Throwable $error) {
                    $counts[$status]++;
                    continue;
                }
                $sent->execute([$job['lead_id']]);
                $counts['sent']++;
            }

            return $counts;
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }
}
