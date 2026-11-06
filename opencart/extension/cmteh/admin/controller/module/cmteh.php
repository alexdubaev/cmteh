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

    public function index(): void
    {
        $token = 'user_token=' . $this->session->data['user_token'];
        $catalog = $this->url->link('extension/cmteh/module/cmteh_catalog', $token);
        $leads = $this->url->link('extension/cmteh/module/cmteh_leads', $token);

        $this->document->setTitle('СМ ТЕХНО');
        $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh', [
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'catalog' => $this->user->hasPermission('access', 'extension/cmteh/module/cmteh_catalog') ? $catalog : '',
            'leads' => $this->user->hasPermission('access', 'extension/cmteh/module/cmteh_leads') ? $leads : '',
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
        $this->load->model('setting/setting');
        $this->model_setting_setting->editValue('config', 'config_error_display', '0');
        $this->config->set('config_error_display', false);
        $this->migrate();
        $this->load->model('setting/event');

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
    }

    private function migrate(): void
    {
        $system = DIR_EXTENSION . 'cmteh/system/';
        require_once $system . 'Database.php';

        $pdo = Database::connectFromOpenCart();
        $private = Database::privateDir();

        if (is_file($system . 'Catalog.php')) {
            require_once $system . 'Catalog.php';
            (new Catalog($pdo, DB_PREFIX, (int) $this->config->get('config_language_id'), 0, $private))->migrate();
        }
        if (is_file($system . 'Lead.php')) {
            require_once $system . 'Lead.php';
            (new Lead($pdo, DB_PREFIX))->migrate();
        }
    }
}
