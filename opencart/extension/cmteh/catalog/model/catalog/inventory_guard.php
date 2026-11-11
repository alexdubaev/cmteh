<?php
declare(strict_types=1);
namespace Opencart\Catalog\Model\Extension\Cmteh\Catalog;

use Cmteh\InventoryNativeGuard;
use Opencart\System\Engine\Model;

final class InventoryGuard extends Model
{
    public function editQuantity(int $product_id,int $quantity): void
    {
        $this->registry->get('cmteh_inventory_native')->run([$product_id],function(array $states) use ($product_id,$quantity): void {
            InventoryNativeGuard::rejectManaged($states);
            $this->registry->get('fallback_model_catalog_product')->editQuantity($product_id,$quantity);
        });
    }

    public function editOptionQuantity(int $product_id,int $product_option_id,int $product_option_value_id,int $quantity): void
    {
        $this->registry->get('cmteh_inventory_native')->run([$product_id],function(array $states) use ($product_id,$product_option_id,$product_option_value_id,$quantity): void {
            InventoryNativeGuard::rejectManaged($states);
            $this->registry->get('fallback_model_catalog_product')->editOptionQuantity($product_id,$product_option_id,$product_option_value_id,$quantity);
        });
    }
}
