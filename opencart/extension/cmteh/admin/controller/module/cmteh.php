<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Module;

use Cmteh\Catalog;
use Cmteh\Database;
use Cmteh\Lead;
use Opencart\System\Engine\Controller;
use Throwable;

final class Cmteh extends Controller
{
    private const ROUTE = 'extension/cmteh/module/cmteh';
    private const EVENT_CODE = 'cmteh_storefront_route';
    private const NATIVE_CATALOG_ACTIONS = [
        'category' => ['addCategory', 'editCategory', 'deleteCategory'],
        'manufacturer' => ['addManufacturer', 'editManufacturer', 'deleteManufacturer'],
        'product' => ['addProduct', 'editProduct', 'deleteProduct'],
    ];

    public function index(): void
    {
        $token = 'user_token=' . $this->session->data['user_token'];
        require_once DIR_EXTENSION . 'cmteh/admin/controller/event/cmteh_admin.php';
        $navigation = new \Opencart\Admin\Controller\Extension\Cmteh\Event\CmtehAdmin($this->registry);

        $this->document->setTitle('СМ ТЕХНО');
        $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh', [
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'links' => $navigation->links(),
            'missing_catalog' => !$this->user->hasPermission('access', 'extension/cmteh/module/cmteh_catalog'),
            'missing_leads' => !$this->user->hasPermission('access', 'extension/cmteh/module/cmteh_leads'),
            'permissions' => $this->user->hasPermission('access', 'user/user_permission')
                ? $this->url->link('user/user_permission', $token) : '',
            'upgrade' => $this->user->hasPermission('modify', self::ROUTE)
                ? $this->url->link(self::ROUTE . '.upgrade', $token)
                : '',
        ]));
    }

    public function install(): void
    {
        if (($this->request->get['route'] ?? '') !== 'extension/module.install'
            || !$this->user->hasPermission('modify', 'extension/module')) {
            throw new \RuntimeException('Module installation is forbidden.');
        }

        $this->applyChanges();
    }

    private function applyChanges(): void
    {
        $this->load->model('setting/event');
        foreach ($this->inventoryEvents() as $code => [$trigger, $action, $priority]) {
            if (!$this->model_setting_event->getEventByCode($code)) $this->model_setting_event->addEvent([
                'code' => $code, 'description' => 'СМ ТЕХНО: защита складского учёта', 'trigger' => $trigger,
                'action' => $action, 'status' => true, 'sort_order' => $priority,
            ]);
        }

        $this->load->model('setting/setting');
        $this->model_setting_setting->editValue('config', 'config_error_display', '0');
        $this->config->set('config_error_display', false);
        $this->migrate();

        if (!$this->model_setting_event->getEventByCode(self::EVENT_CODE)) {
            $this->model_setting_event->addEvent([
                'code' => self::EVENT_CODE,
                'description' => 'СМ ТЕХНО: маршрутизация витрины',
                'trigger' => 'catalog/controller/common/home/before',
                'action' => 'extension/cmteh/storefront/router.route',
                'status' => true,
                'sort_order' => 0,
            ]);
        }

        foreach ($this->nativeCatalogEvents() as $code => [$trigger, $action]) {
            if (!$this->model_setting_event->getEventByCode($code)) {
                $this->model_setting_event->addEvent([
                    'code' => $code,
                    'description' => 'СМ ТЕХНО: синхронизация каталога OpenCart',
                    'trigger' => $trigger,
                    'action' => $action,
                    'status' => true,
                    'sort_order' => 0,
                ]);
            }
        }
        foreach ($this->adminEvents() as $code => [$trigger, $action]) {
            // Re-register our own hooks to repair stale versions without touching other events.
            $this->model_setting_event->deleteEventByCode($code);
            $this->model_setting_event->addEvent([
                'code' => $code, 'description' => 'СМ ТЕХНО: русская панель управления',
                'trigger' => $trigger, 'action' => $action, 'status' => true, 'sort_order' => 1000,
            ]);
        }
        $this->clearCompiledTemplates();

        if (!touch(Database::privateDir() . '/storefront.ready')) {
            throw new \RuntimeException('Cannot activate the storefront.');
        }
    }

    public function upgrade(): void
    {
        $this->response->addHeader('Content-Type: application/json; charset=utf-8');
        if ($this->request->server['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            $this->response->addHeader('Allow: POST');
            $this->response->setOutput('{"error":"Требуется POST"}');

            return;
        }
        if (!$this->user->hasPermission('modify', self::ROUTE)) {
            http_response_code(403);
            $this->response->setOutput('{"error":"Недостаточно прав"}');

            return;
        }

        try {
            $this->applyChanges();
            $this->response->setOutput('{"success":"Обновление завершено"}');
        } catch (Throwable $exception) {
            $this->log->write('СМ ТЕХНО: не удалось применить миграции (' . get_class($exception) . ')');
            http_response_code(500);
            $this->response->setOutput('{"error":"Не удалось обновить СМ ТЕХНО"}');
        }
    }

    public function uninstall(): void
    {
        if (($this->request->get['route'] ?? '') !== 'extension/module.uninstall'
            || !$this->user->hasPermission('modify', 'extension/module')) {
            throw new \RuntimeException('Module removal is forbidden.');
        }

        require_once DIR_EXTENSION . 'cmteh/system/Database.php';
        $marker = Database::privateDir() . '/storefront.ready';
        if (is_file($marker) && !unlink($marker)) {
            throw new \RuntimeException('Cannot deactivate the storefront.');
        }

        $this->load->model('setting/event');
        $this->model_setting_event->deleteEventByCode(self::EVENT_CODE);
        foreach (array_keys($this->nativeCatalogEvents()) as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
        foreach (array_keys($this->inventoryEvents()) as $code) $this->model_setting_event->deleteEventByCode($code);
        foreach (array_keys($this->adminEvents()) as $code) {
            $this->model_setting_event->deleteEventByCode($code);
        }
    }

    private function adminEvents(): array
    {
        return [
            'cmteh_admin_menu' => ['admin/view/common/column_left/before', 'extension/cmteh/event/cmteh_admin.menu'],
            'cmteh_admin_header' => ['admin/view/common/header/before', 'extension/cmteh/event/cmteh_admin.header'],
            'cmteh_admin_dashboard' => ['admin/view/common/dashboard/before', 'extension/cmteh/event/cmteh_admin.dashboard'],
            'cmteh_admin_marketing' => ['admin/view/marketing/marketing*/before', 'extension/cmteh/event/cmteh_admin.marketing'],
            'cmteh_admin_language' => ['admin/language/*/after', 'extension/cmteh/event/cmteh_admin.language'],
        ];
    }

    private function nativeCatalogEvents(): array
    {
        $handler = 'extension/cmteh/event/cmteh_catalog';
        $events = ['cmteh_native_guard_category_delete' => [
            'admin/controller/catalog/category.delete/before', $handler . '.guardCategoryDelete',
        ]];
        foreach (self::NATIVE_CATALOG_ACTIONS as $model => $methods) {
            foreach ($methods as $method) {
                $events['cmteh_native_' . $method] = ["admin/model/catalog/$model.$method/after", $handler . '.synchronize'];
            }
            foreach (['save', 'delete'] as $method) {
                $events['cmteh_native_report_' . $model . '_' . $method] = [
                    "admin/controller/catalog/$model.$method/after", $handler . '.reportSyncFailure',
                ];
            }
        }
        return $events;
    }

    private function inventoryEvents(): array
    {
        $admin='extension/cmteh/event/cmteh_catalog';
        $catalog='extension/cmteh/event/cmteh_inventory';
        $events=[
            'cmteh_inventory_delete_batch'=>['admin/controller/catalog/product.delete/before',$admin.'.guardProductDelete',-10000],
            'cmteh_inventory_native_form'=>['admin/view/catalog/product_form/before',$admin.'.inventoryForm',1000],
            'cmteh_inventory_order_guard'=>['catalog/model/checkout/order.addHistory/before',$catalog.'.guardOrder',-10000],
        ];
        foreach (['addProduct','editProduct','copyProduct','deleteProduct','addVariant','editVariant','addOptionValue'] as $method) {
            $events['cmteh_inventory_native_'.$method]=['admin/model/catalog/product.'.$method.'/before',$admin.'.guardProductModel',-10000];
        }
        foreach (['editQuantity','editOptionQuantity'] as $method) {
            $events['cmteh_inventory_native_'.$method]=['catalog/model/catalog/product.'.$method.'/before',$catalog.'.guardQuantity',-10000];
        }
        return $events;
    }

    private function clearCompiledTemplates(): void
    {
        $directory = DIR_CACHE . 'template';
        if (!is_dir($directory)) {
            return;
        }
        // Extracted templates can be older than compiled PHP from the prior release.
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->isLink() || $file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            $source = @file_get_contents($path);
            if (is_string($source) && str_contains($source, 'return "extension/cmteh/')
                && !@unlink($path) && is_file($path)) {
                throw new \RuntimeException('Cannot invalidate the compiled CMTEH template.');
            }
        }
    }

    private function migrate(): void
    {
        $system = DIR_EXTENSION . 'cmteh/system/';
        require_once $system . 'Database.php';

        $pdo = Database::connectFromOpenCart();
        $private = Database::privateDir();

        if (is_file($system . 'Catalog.php')) {
            require_once $system . 'Catalog.php';
            $catalog = new Catalog($pdo, DB_PREFIX, (int) $this->config->get('config_language_id'), 0, $private);
            $catalog->migrate();
            $catalog->migrateEditorialContent();
        }
        if (is_file($system . 'Lead.php')) {
            require_once $system . 'Lead.php';
            (new Lead($pdo, DB_PREFIX))->migrate();
        }
        if (is_file($system . 'Quote.php')) {
            require_once $system . 'Quote.php';
            (new \Cmteh\Quote($pdo, DB_PREFIX))->migrate();
        }
        require_once $system . 'InventorySchema.php';
        \Cmteh\InventorySchema::migrate($pdo, DB_PREFIX);
        require_once $system . 'InventoryImport.php';
        \Cmteh\InventoryImport::migrate($pdo, DB_PREFIX);
    }
}
