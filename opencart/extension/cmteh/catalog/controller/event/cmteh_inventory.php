<?php
declare(strict_types=1);
namespace Opencart\Catalog\Controller\Extension\Cmteh\Event;

use Cmteh\InventoryNativeGuard;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehInventory extends Controller
{
    private function guard(): InventoryNativeGuard
    {
        require_once DIR_EXTENSION . 'cmteh/system/InventoryNativeGuard.php';
        if (!$this->registry->has('cmteh_inventory_native')) $this->registry->set('cmteh_inventory_native',new InventoryNativeGuard($this->db,DB_PREFIX));
        return $this->registry->get('cmteh_inventory_native');
    }

    public function guardQuantity(string &$route,array &$args): void
    {
        $this->guard();
        $route='extension/cmteh/catalog/inventory_guard.'.substr($route,strrpos($route,'.')+1);
    }

    public function guardOrder(string &$route,array &$args): void
    {
        // This priority precedes native mail/activity/statistics hooks, not merely the SQL model.
        $guard=$this->guard();
        $guard->begin('order');
        try {
            $id=(int)($args[0]??0);
            $orderRows=$this->db->query('SELECT product_id FROM `'.DB_PREFIX.'order_product` WHERE order_id='.$id.' ORDER BY product_id FOR UPDATE')->rows;
            $ids=array_column($orderRows,'product_id');
            foreach ($orderRows as $row) {
                $product=$this->db->query('SELECT master_id FROM `'.DB_PREFIX.'product` WHERE product_id='.(int)$row['product_id'])->row;
                if (!empty($product['master_id'])) $ids[]=(int)$product['master_id'];
            }
            InventoryNativeGuard::rejectManaged($guard->lock($ids));
            $route='extension/cmteh/checkout/inventory_guard.addHistory';
        } catch (Throwable $error) {
            try { $guard->finish(false); } catch (Throwable) {}
            throw $error;
        }
    }
}
