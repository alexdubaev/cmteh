<?php
declare(strict_types=1);
namespace Cmteh;

use PDO;
use Throwable;

require_once __DIR__ . '/InventoryException.php';

/** Uses the registry's actual SQL connection, including the original native writes. */
final class InventoryNativeGuard
{
    private ?object $scope = null;
    private ?bool $ready = null;
    private bool $projectionDeferred = false;

    public function __construct(private readonly object $db, private readonly string $prefix)
    {
        if (!preg_match('/^[A-Za-z0-9_]{0,32}$/D', $prefix)) throw new \InvalidArgumentException('Invalid prefix');
    }

    public function active(): bool { return $this->scope !== null; }
    public function projectionDeferred(): bool
    {
        if ($this->projectionDeferred && !$this->active() && !$this->transactionOpen()) $this->projectionDeferred=false;
        return $this->projectionDeferred;
    }

    private function transactionOpen(): bool
    {
        $this->db->query('SAVEPOINT cmteh_inventory_probe');
        try { $this->db->query('RELEASE SAVEPOINT cmteh_inventory_probe'); return true; } catch (Throwable) { return false; }
    }

    public function begin(string $purpose): void
    {
        if ($this->scope !== null) throw new InventoryException('native_busy', 'Не завершена предыдущая операция с остатками.', 409);
        // SAVEPOINT is a no-op outside a transaction. RELEASE then detects that case on 5.7/8.x.
        $joined = $this->transactionOpen();
        if ($joined) $this->db->query('SAVEPOINT cmteh_inventory_native');
        else $this->db->query('START TRANSACTION');
        $scope = $this->scope = (object) ['purpose'=>$purpose,'joined'=>$joined,'closed'=>false];
        $db = $this->db;
        register_shutdown_function(static function() use ($db,$scope): void {
            // A later before-event may throw before the wrapper is reached. Never commit that request.
            if (!$scope->closed) {
                try { $db->query($scope->joined ? 'ROLLBACK TO SAVEPOINT cmteh_inventory_native' : 'ROLLBACK'); } catch (Throwable) {}
                $scope->closed = true;
            }
        });
    }

    public function finish(bool $success): bool
    {
        if ($this->scope === null) throw new \LogicException('No native inventory scope');
        $scope = $this->scope;
        try {
            if (!$success) $this->db->query($scope->joined ? 'ROLLBACK TO SAVEPOINT cmteh_inventory_native' : 'ROLLBACK');
            elseif ($scope->joined) $this->db->query('RELEASE SAVEPOINT cmteh_inventory_native');
            else $this->db->query('COMMIT');
            $scope->closed=true;
            $this->projectionDeferred=$success && $scope->joined;
        } catch (Throwable $error) {
            try { $this->db->query($scope->joined ? 'ROLLBACK TO SAVEPOINT cmteh_inventory_native' : 'ROLLBACK'); $scope->closed=true; } catch (Throwable) {}
            throw $error;
        } finally { $this->scope=null; }
        return !$scope->joined;
    }

    public function run(array $ids, callable $callback): mixed
    {
        $owner = !$this->active();
        if ($owner) $this->begin('native');
        try {
            $states = $this->lock($ids);
            if ($owner && $this->scope->joined) foreach ($states as $row) if ($row['managed']) {
                throw new InventoryException('unsupported_native_transaction','Сохранение складского товара внутри внешней транзакции не поддерживается. Используйте штатную форму товара.',409);
            }
            $result = $callback($states);
            if ($owner) $this->finish(true);
            return $result;
        } catch (Throwable $error) {
            if ($owner && $this->active()) { try { $this->finish(false); } catch (Throwable) {} }
            throw $error;
        }
    }

    /** Native products first, then controls, in the same numeric order as Inventory. */
    public function lock(array $ids): array
    {
        if (!$this->active()) throw new \LogicException('Native inventory lock requires transaction');
        $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids,SORT_NUMERIC);
        $rows=[];
        foreach ($ids as $id) {
            if ($id<1) continue;
            $row=$this->db->query('SELECT product_id,quantity,master_id FROM `'.$this->prefix.'product` WHERE product_id='.$id.' FOR UPDATE')->row;
            if ($row) $rows[$id]=$row+['managed'=>false];
        }
        if ($this->ready === null) {
            $this->ready=(bool)$this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$this->db->escape($this->prefix.'cmteh_inventory_product')."'")->num_rows;
        }
        if ($this->ready) foreach ($rows as $id=>&$row) {
            $row['managed']=(bool)$this->db->query('SELECT product_id FROM `'.$this->prefix.'cmteh_inventory_product` WHERE product_id='.$id.' FOR UPDATE')->num_rows;
        }
        unset($row);
        return $rows;
    }

    public function available(int $id): int
    {
        $total=0;
        // Current read is essential when joining an already-open REPEATABLE READ transaction.
        foreach ($this->db->query('SELECT on_hand,reserved FROM `'.$this->prefix.'cmteh_inventory_balance` WHERE product_id='.$id.' ORDER BY warehouse_id FOR UPDATE')->rows as $row) {
            $total+=(int)$row['on_hand']-(int)$row['reserved'];
        }
        return $total;
    }

    public static function rejectManaged(array $states): void
    {
        foreach ($states as $row) if ($row['managed']) throw new InventoryException('managed_stock', 'Остатки и история этого товара управляются в разделе «Склады». Используйте складскую операцию или снимите товар с публикации.', 409);
    }

    /** CMTEH deletion runs on its own actual PDO transaction, before routes/relations change. */
    public static function assertDeletable(PDO $pdo,string $prefix,int $id): void
    {
        $q=$pdo->prepare('SELECT product_id FROM `'.$prefix.'product` WHERE product_id=? FOR UPDATE'); $q->execute([$id]);
        $q=$pdo->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'); $q->execute([$prefix.'cmteh_inventory_product']);
        if (!$q->fetchColumn()) return;
        $q=$pdo->prepare('SELECT product_id FROM `'.$prefix.'cmteh_inventory_product` WHERE product_id=? FOR UPDATE'); $q->execute([$id]);
        if ($q->fetchColumn()) throw new \InvalidArgumentException('Товар связан со складскими остатками и историей. Снимите его с публикации вместо удаления.');
    }
}
