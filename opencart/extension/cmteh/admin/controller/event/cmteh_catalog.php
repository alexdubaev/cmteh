<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Event;

use Cmteh\Database;
use Cmteh\NativeCatalog;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehCatalog extends Controller
{
    public function guardProductModel(string &$route,array &$args): void
    {
        require_once DIR_EXTENSION . 'cmteh/system/InventoryNativeGuard.php';
        if (!$this->registry->has('cmteh_inventory_native')) $this->registry->set('cmteh_inventory_native',new \Cmteh\InventoryNativeGuard($this->db,DB_PREFIX));
        $method=substr($route,strrpos($route,'.')+1);
        if (in_array($method,['addProduct','editProduct','copyProduct','deleteProduct','addVariant','editVariant','addOptionValue'],true)) {
            $route='extension/cmteh/catalog/inventory_guard.'.$method;
        }
    }

    public function guardProductDelete(string &$route,array &$args): void
    {
        if ($this->user->isLogged() && $this->user->hasPermission('modify','catalog/product')) $route='extension/cmteh/event/cmteh_catalog.inventoryDelete';
    }

    public function inventoryDelete(): void
    {
        require_once DIR_EXTENSION . 'cmteh/system/InventoryNativeGuard.php';
        if (!$this->user->isLogged() || !$this->user->hasPermission('modify','catalog/product')) {
            http_response_code(403); $this->response->setOutput('{"error":"Недостаточно прав"}'); return;
        }
        try {
            if (($this->request->server['REQUEST_METHOD']??'')!=='POST') throw new \Cmteh\InventoryException('invalid_input','Требуется POST.',405);
            $ids=[];
            foreach ((array)($this->request->post['selected']??[]) as $id) {
                if (!is_scalar($id) || !ctype_digit((string)$id) || (int)$id<1 || (int)$id>2147483647) throw new \Cmteh\InventoryException('invalid_input','Некорректный список товаров.');
                $ids[]=(int)$id;
            }
            $route='catalog/product.deleteProduct'; $args=[]; $this->guardProductModel($route,$args);
            $guard=$this->registry->get('cmteh_inventory_native');
            $guard->run($ids,function(array $states): void {
                \Cmteh\InventoryNativeGuard::rejectManaged($states);
                $this->factory->controller('catalog/product')->delete();
            });
            foreach ($ids as $id) { $route='catalog/product.deleteProduct'; $args=[$id]; $output=null; $this->synchronize($route,$args,$output); }
        } catch (\Cmteh\InventoryException $error) {
            http_response_code($error->status);
            $this->response->addHeader('Content-Type: application/json; charset=utf-8');
            $this->response->setOutput(json_encode(['error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        }
    }

    public function inventoryForm(string &$route,array &$data): void
    {
        $id=(int)($this->request->get['product_id']??0);
        if ($id<1 || !$this->user->isLogged()) return;
        $ready=$this->db->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='".$this->db->escape(DB_PREFIX.'cmteh_inventory_product')."'")->num_rows;
        if (!$ready || !$this->db->query('SELECT product_id FROM `'.DB_PREFIX.'cmteh_inventory_product` WHERE product_id='.$id)->num_rows) return;
        $stock=$this->db->query('SELECT COALESCE(SUM(on_hand),0) AS on_hand,COALESCE(SUM(reserved),0) AS reserved,COALESCE(SUM(on_hand-reserved),0) AS available FROM `'.DB_PREFIX.'cmteh_inventory_balance` WHERE product_id='.$id)->row;
        $link=$this->user->hasPermission('access','extension/cmteh/module/cmteh_catalog')
            ? $this->url->link('extension/cmteh/module/cmteh_catalog.inventory',['user_token'=>$this->session->data['user_token'],'product_id'=>$id],true) : '';
        $json=json_encode($stock+['url'=>$link],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
        $data['footer']=($data['footer']??'').'<script type="application/json" id="cmteh-inventory-guard">'.$json.'</script><script src="../extension/cmteh/admin/view/javascript/cmteh_inventory_guard.js"></script>';
    }

    public function guardCategoryDelete(string &$route, array &$args): void
    {
        if ($route !== 'catalog/category.delete' || !$this->user->isLogged()
            || !$this->user->hasPermission('modify', 'catalog/category')) {
            return;
        }

        $error = '';
        $status = 200;
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') {
            $error = 'Для удаления категории требуется POST.';
            $status = 405;
        } else {
            $selected = (array) ($this->request->post['selected'] ?? []);
            $ids = [];
            foreach ($selected as $id) {
                if (!is_scalar($id) || !ctype_digit((string) $id) || (int) $id < 1 || (int) $id > 2147483647) {
                    $error = 'Некорректный список категорий для удаления.';
                    break;
                }
                $ids[] = (int) $id;
            }
            if ($error === '' && $ids !== []) {
                require_once DIR_EXTENSION . 'cmteh/system/Database.php';
                try {
                    // Check the entire batch before OpenCart can recursively delete its first item.
                    $pdo = Database::connectFromOpenCart();
                    $marks = implode(',', array_fill(0, count($ids), '?'));
                    $query = $pdo->prepare('SELECT 1 FROM `' . DB_PREFIX . 'category` WHERE parent_id IN (' . $marks . ') LIMIT 1');
                    $query->execute($ids);
                    $hasChildren = $query->fetchColumn() !== false;
                    $query = $pdo->prepare('SELECT 1 FROM `' . DB_PREFIX . 'product_to_category` WHERE category_id IN (' . $marks . ') LIMIT 1');
                    $query->execute($ids);
                    if ($hasChildren || $query->fetchColumn() !== false) {
                        $error = 'Категории связаны с товарами или дочерними категориями. Сначала снимите связи. Ничего не удалено.';
                    }
                } catch (Throwable $exception) {
                    $this->log->write('СМ ТЕХНО: не удалось проверить связи категорий (' . get_class($exception) . ')');
                    $error = 'Не удалось проверить связи категорий. Ничего не удалено. Повторите попытку.';
                }
            }
        }
        if ($error !== '') {
            // The 4.1 loader ignores before-event return values; redirect the controller instead.
            $this->registry->set('cmteh_category_delete_denied', (object) ['error' => $error, 'status' => $status]);
            $route = 'extension/cmteh/event/cmteh_catalog.rejectCategoryDelete';
        }
    }

    public function rejectCategoryDelete(): void
    {
        $denial = $this->registry->get('cmteh_category_delete_denied');
        $status = $denial->status ?? 403;
        if ($status !== 200) {
            http_response_code($status);
        }
        if ($status === 405) {
            $this->response->addHeader('Allow: POST');
        }
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        $this->response->setOutput(json_encode(['error' => $denial->error ?? 'Удаление категории отклонено.'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private const ROUTES = [
        'catalog/category.addCategory' => ['category', 'add'],
        'catalog/category.editCategory' => ['category', 'edit'],
        'catalog/category.deleteCategory' => ['category', 'delete'],
        'catalog/manufacturer.addManufacturer' => ['brand', 'add'],
        'catalog/manufacturer.editManufacturer' => ['brand', 'edit'],
        'catalog/manufacturer.deleteManufacturer' => ['brand', 'delete'],
        'catalog/product.addProduct' => ['product', 'add'],
        'catalog/product.editProduct' => ['product', 'edit'],
        'catalog/product.deleteProduct' => ['product', 'delete'],
    ];

    public function synchronize(string &$route = '', array &$args = [], mixed &$output = null): void
    {
        if ($this->registry->has('cmteh_inventory_native') && $this->registry->get('cmteh_inventory_native')->active()) return;
        if ($this->registry->has('cmteh_inventory_native') && $this->registry->get('cmteh_inventory_native')->projectionDeferred()) return;
        if (!isset(self::ROUTES[$route]) || !$this->user->isLogged()) {
            return;
        }

        [$type, $operation] = self::ROUTES[$route];
        $id = $operation === 'add' ? $output : ($args[0] ?? null);
        if (is_string($id) && strlen($id) <= 10 && ctype_digit($id)) {
            $id = (int) $id;
        }
        if (!is_int($id) || $id < 1 || $id > 2147483647) {
            return;
        }

        require_once DIR_EXTENSION . 'cmteh/system/Database.php';
        require_once DIR_EXTENSION . 'cmteh/system/NativeCatalog.php';

        try {
            $catalog = new NativeCatalog(
                Database::connectFromOpenCart(),
                DB_PREFIX,
                (int) $this->config->get('config_language_id'),
                0,
                (string) $this->config->get('config_currency'),
            );
            $catalog->synchronize($type, $id, $operation === 'delete');
        } catch (Throwable $error) {
            $this->log->write('СМ ТЕХНО: ошибка синхронизации ' . $type . ' #' . $id . ' (' . get_class($error) . ')');
            $this->registry->set('cmteh_catalog_sync_warning', (object) [
                'message' => $operation === 'delete'
                    ? 'Запись удалена в OpenCart. Не удалось завершить обновление витрины.'
                    : 'Запись #' . $id . ' сохранена в OpenCart. Повторно сохраните эту же запись для обновления витрины.',
            ]);
        }
    }

    public function reportSyncFailure(): void
    {
        $warning = $this->registry->get('cmteh_catalog_sync_warning')->message ?? null;
        if (!is_string($warning) || $warning === '') {
            return;
        }

        $result = json_decode($this->response->getOutput(), true);
        if (!is_array($result) || !isset($result['success'])) {
            return;
        }
        // Keep the native ID so OpenCart turns the add form into an edit form.
        unset($result['success']);
        $result['error']['warning'] = $warning;
        $this->response->setOutput(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
