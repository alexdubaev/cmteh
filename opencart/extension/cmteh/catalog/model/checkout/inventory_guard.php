<?php
declare(strict_types=1);
namespace Opencart\Catalog\Model\Extension\Cmteh\Checkout;

use Opencart\System\Engine\Model;
use Throwable;

final class InventoryGuard extends Model
{
    public function addHistory(int $order_id,int $order_status_id,string $comment='',bool $notify=false,bool $override=false): int
    {
        $guard=$this->registry->get('cmteh_inventory_native');
        if (!$guard || !$guard->active()) throw new \LogicException('Order inventory guard was not prepared');
        try {
            $result=$this->registry->get('fallback_model_checkout_order')->addHistory($order_id,$order_status_id,$comment,$notify,$override);
            $guard->finish(true);
            return $result;
        } catch (Throwable $error) {
            if ($guard->active()) { try { $guard->finish(false); } catch (Throwable) {} }
            throw $error;
        }
    }
}
