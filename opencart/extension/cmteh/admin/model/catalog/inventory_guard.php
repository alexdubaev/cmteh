<?php
declare(strict_types=1);
namespace Opencart\Admin\Model\Extension\Cmteh\Catalog;

use Cmteh\InventoryNativeGuard;
use Opencart\System\Engine\Model;

final class InventoryGuard extends Model
{
    private function guard(): InventoryNativeGuard { return $this->registry->get('cmteh_inventory_native'); }
    private function native(): object { return $this->registry->get('fallback_model_catalog_product'); }
    private function sync(string $method,int $id): void
    {
        if ($this->guard()->active()) return;
        if ($this->guard()->projectionDeferred()) {
            $this->registry->set('cmteh_catalog_sync_warning',(object)['message'=>'Товар сохранён внутри внешней транзакции. После её завершения повторно сохраните товар для обновления витрины.']);
            return;
        }
        $controller=$this->factory->controller('extension/cmteh/event/cmteh_catalog');
        $route='catalog/product.'.$method; $args=[$id]; $output=$id;
        $controller->synchronize($route,$args,$output);
    }

    public function editProduct(int $product_id,array $data): void
    {
        $ids=[$product_id];
        $this->guard()->run($ids,function(array $states) use ($product_id,$data): void {
            if (!empty($states[$product_id]['managed'])) {
                foreach ($data['product_option']??[] as $option) foreach ($option['product_option_value']??[] as $value) {
                    if (!empty($value['subtract'])) InventoryNativeGuard::rejectManaged($states);
                }
                $data['quantity']=$this->guard()->available($product_id);
            }
            $this->native()->editProduct($product_id,$data);
        });
        $this->sync('editProduct',$product_id);
    }

    public function copyProduct(int $product_id): int
    {
        $id=$this->guard()->run([$product_id],function(array $states) use ($product_id): int {
            $this->registry->set('cmteh_inventory_copy_managed',(object)['active'=>!empty($states[$product_id]['managed'])]);
            try { return $this->native()->copyProduct($product_id); }
            finally { $this->registry->set('cmteh_inventory_copy_managed',(object)['active'=>false]); }
        });
        if ($id>0) $this->sync('addProduct',$id);
        return $id;
    }

    public function addProduct(array $data): int
    {
        $id=$this->guard()->run([(int)($data['master_id']??0)],function(array $states) use ($data): int {
            InventoryNativeGuard::rejectManaged($states);
            if ($this->registry->get('cmteh_inventory_copy_managed')->active??false) $data['quantity']=0;
            return $this->native()->addProduct($data);
        });
        $this->sync('addProduct',$id);
        return $id;
    }

    public function deleteProduct(int $product_id): void
    {
        $this->guard()->run([$product_id],function(array $states) use ($product_id): void {
            InventoryNativeGuard::rejectManaged($states); $this->native()->deleteProduct($product_id);
        });
        $this->sync('deleteProduct',$product_id);
    }

    public function addVariant(int $master_id,array $data): int
    {
        $id=$this->guard()->run([$master_id],function(array $states) use ($master_id,$data): int {
            InventoryNativeGuard::rejectManaged($states); return $this->native()->addVariant($master_id,$data);
        });
        if ($id>0) $this->sync('addProduct',$id);
        return $id;
    }

    public function editVariant(int $master_id,int $product_id,array $data): void
    {
        $this->guard()->run([$master_id,$product_id],function(array $states) use ($master_id,$product_id,$data): void {
            InventoryNativeGuard::rejectManaged($states); $this->native()->editVariant($master_id,$product_id,$data);
        });
        $this->sync('editProduct',$product_id);
    }

    public function addOptionValue(int $product_id,int $product_option_id,int $option_id,array $data): int
    {
        return $this->guard()->run([$product_id],function(array $states) use ($product_id,$product_option_id,$option_id,$data): int {
            if (!empty($data['subtract'])) InventoryNativeGuard::rejectManaged($states);
            return $this->native()->addOptionValue($product_id,$product_option_id,$option_id,$data);
        });
    }
}
