<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Module;

use Cmteh\Database;
use Cmteh\Lead;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehLeads extends Controller
{
    private const ROUTE = 'extension/cmteh/module/cmteh_leads';
    private const STATUS_LABELS = [
        'pending' => 'В очереди',
        'retry' => 'Ожидает повторной отправки',
        'sent' => 'Отправлено',
        'failed' => 'Не доставлено',
    ];

    public function index(): void
    {
        $this->response->addHeader('Cache-Control: private, no-store');
        $this->response->addHeader('Referrer-Policy: no-referrer');
        $requestToken = $this->request->get['user_token'] ?? null;
        $sessionToken = $this->session->data['user_token'] ?? null;
        if (!$this->user->isLogged() || !is_string($sessionToken) || $sessionToken === ''
            || !is_string($requestToken) || !hash_equals($sessionToken, $requestToken)
            || !$this->user->hasPermission('access', self::ROUTE)) {
            $this->fail(403, 'Недостаточно прав для просмотра заявок.');

            return;
        }

        $view = $this->request->get['view'] ?? '';
        if (!is_string($view) || !in_array($view, ['', 'requests', 'contacts', 'reports', 'sources', 'capabilities'], true)) {
            $this->fail(400, 'Неизвестный раздел.'); return;
        }
        if ($view !== '' || !isset($this->request->get['id']) && !isset($this->request->get['type'])) {
            $this->business($view ?: 'requests'); return;
        }

        $id = $this->request->get['id'] ?? null;
        if ($id !== null && (!is_string($id)
            || !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $id))) {
            $this->fail(404, 'Заявка не найдена.');

            return;
        }

        $requestedPage = $this->request->get['page'] ?? null;
        $page = is_string($requestedPage) && preg_match('/^[0-9]{1,9}$/D', $requestedPage)
            ? max(1, (int) $requestedPage)
            : 1;
        $query = ['user_token' => $sessionToken];
        if (($this->request->get['type'] ?? '') === 'quote') {
            $this->quotes($id, $page, $query);
            return;
        }
        try {
            require_once DIR_EXTENSION . 'cmteh/system/Lead.php';
            $service = new Lead(Database::connectFromOpenCart(), DB_PREFIX);
            $data = [
                'status_labels' => self::STATUS_LABELS,
                'lead' => null,
                'quotes' => $this->url->link(self::ROUTE, $query + ['type' => 'quote']),
                'back' => $this->url->link(self::ROUTE, $query + ['page' => $page]),
            ];
            if ($id !== null) {
                $data['lead'] = $service->find($id);
                if ($data['lead'] === null) {
                    $this->fail(404, 'Заявка не найдена.');

                    return;
                }
            } else {
                $result = $service->list($page);
                $data += $result;
                $page = $result['page'];
                $data['previous'] = $page > 1
                    ? $this->url->link(self::ROUTE, $query + ['page' => $page - 1])
                    : '';
                $data['next'] = $page < $result['pages']
                    ? $this->url->link(self::ROUTE, $query + ['page' => $page + 1])
                    : '';
                foreach ($data['rows'] as &$row) {
                    $row['href'] = $this->url->link(self::ROUTE, $query + ['id' => $row['public_id'], 'page' => $page]);
                }
                unset($row);
            }

            $this->document->setTitle('Заявки СМ ТЕХНО');
            $data['header'] = $this->load->controller('common/header');
            $data['column_left'] = $this->load->controller('common/column_left');
            $data['footer'] = $this->load->controller('common/footer');
            $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh_leads', $data));
        } catch (Throwable $error) {
            $this->log->write('СМ ТЕХНО: не удалось открыть заявки (' . get_class($error) . ')');
            $this->fail(503, 'Не удалось открыть заявки. Попробуйте позже.');
        }
    }

    private function business(string $view): void
    {
        if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'GET') { $this->fail(405, 'Используйте GET.'); return; }
        try {
            require_once DIR_EXTENSION . 'cmteh/system/Database.php';
            require_once DIR_EXTENSION . 'cmteh/system/AdminBusiness.php';
            require_once DIR_EXTENSION . 'cmteh/system/AdminCapabilities.php';
            // OpenCart Request::clean has escaped GET once; restore input before the
            // parameterized read model and escape exactly once at the Twig boundary.
            $input=$this->request->get;
            foreach (['search','from','to','type','status'] as $key) if (is_string($input[$key] ?? null)) $input[$key]=html_entity_decode($input[$key],ENT_QUOTES,'UTF-8');
            $filters = \Cmteh\AdminBusiness::filters($input);
            $query = ['user_token'=>$this->session->data['user_token'], 'view'=>$view] + $filters;
            $requestedPage=$this->request->get['page'] ?? '1';
            $page=is_string($requestedPage) && preg_match('/^[0-9]{1,9}$/D',$requestedPage) ? max(1,(int)$requestedPage) : 1;
            $titles = ['requests'=>'Заявки и расценки', 'contacts'=>'Контакты обращений', 'reports'=>'Отчёты по обращениям', 'sources'=>'Источники обращений', 'capabilities'=>'Возможности и доработки'];
            $data = ['view'=>$view, 'heading_title'=>$titles[$view], 'filters'=>$filters, 'status_labels'=>self::STATUS_LABELS + ['sending'=>'Передаётся почтовому серверу', 'unknown'=>'Нет состояния'],
                'capabilities'=>\Cmteh\AdminCapabilities::all(), 'navigation'=>[], 'rows'=>[], 'unavailable'=>[]];
            $data['status_labels']['sent'] = 'Передано почтовому серверу';
            foreach ($titles as $key=>$label) $data['navigation'][]=['name'=>$label,'href'=>$this->url->link(self::ROUTE,['user_token'=>$query['user_token'],'view'=>$key])];
            if ($view !== 'capabilities') {
                $service = new \Cmteh\AdminBusiness(Database::connectFromOpenCart(), DB_PREFIX);
                $data['summary']=$service->dashboard($filters);
                $result = match($view) { 'reports'=>$service->reports($filters,$page), 'sources'=>$service->sources($filters,$page), default=>$service->contacts($filters,$page) };
                $data=array_replace($data,$result);
                foreach ($data['rows'] as &$row) if (isset($row['public_id'])) $row['href']=$this->url->link(self::ROUTE,['user_token'=>$query['user_token'],'type'=>$row['type'],'id'=>$row['public_id']]);
                unset($row);
                $data['previous']=$data['page']>1 ? $this->url->link(self::ROUTE,array_replace($query,['page'=>$data['page']-1])) : '';
                $data['next']=$data['page']<$data['pages'] ? $this->url->link(self::ROUTE,array_replace($query,['page'=>$data['page']+1])) : '';
            }
            $data['action']='index.php'; $data['user_token']=$query['user_token']; $data['route']=self::ROUTE;
            $this->document->setTitle($titles[$view]);
            $data['header']=$this->load->controller('common/header');
            $data['column_left']=$this->load->controller('common/column_left');
            $data['footer']=$this->load->controller('common/footer');
            $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh_business',$data));
        } catch (\InvalidArgumentException $e) { $this->fail(400,$e->getMessage()); }
        catch (Throwable $e) { $this->log->write('СМ ТЕХНО: сводка обращений недоступна (' . get_class($e) . ')'); $this->fail(503,'Данные обращений временно недоступны.'); }
    }

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($message);
    }

    private function quotes(?string $id, int $page, array $query): void
    {
        try {
            require_once DIR_EXTENSION . 'cmteh/system/Quote.php';
            $service = new \Cmteh\Quote(Database::connectFromOpenCart(), DB_PREFIX);
            $query['type'] = 'quote';
            $action = $this->request->get['action'] ?? '';
            if (!is_string($action) || !in_array($action, ['', 'download', 'retry', 'deleteAttachment'], true)) { $this->fail(400, 'Неизвестное действие.'); return; }
            if ($action !== '') {
                if ($id === null) { $this->fail(404, 'Расценка не найдена.'); return; }
                if ($action === 'download') {
                    if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'GET') { $this->fail(405, 'Используйте GET.'); return; }
                    $file = $service->attachment($id);
                    if ($file === null) { $this->fail(404, 'Файл не найден.'); return; }
                    $this->response->addHeader('Content-Type: ' . $file['mime']);
                    $this->response->addHeader('X-Content-Type-Options: nosniff');
                    $this->response->addHeader("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($file['filename']));
                    $this->response->addHeader('Content-Length: ' . strlen($file['bytes']));
                    // OpenCart response compression would change original attachment bytes/length.
                    $this->response->setCompression(0);
                    $this->response->setOutput($file['bytes']); return;
                }
                if (($this->request->server['REQUEST_METHOD'] ?? '') !== 'POST') { $this->fail(405, 'Используйте POST.'); return; }
                if (!$this->user->hasPermission('modify', self::ROUTE)) { $this->fail(403, 'Недостаточно прав для изменения расценок.'); return; }
                $ok = $action === 'retry' ? $service->retry($id) : $service->deleteAttachment($id);
                if (!$ok) { $this->fail(409, 'Действие недоступно для текущего состояния расценки.'); return; }
                $this->response->redirect($this->url->link(self::ROUTE, $query + ['id' => $id])); return;
            }
            $data = ['quote' => null, 'status_labels' => self::STATUS_LABELS + ['sending' => 'Передаётся почтовому серверу'],
                'back' => $this->url->link(self::ROUTE, $query),
                'leads' => $this->url->link(self::ROUTE, ['user_token' => $query['user_token']]),
                'can_modify' => $this->user->hasPermission('modify', self::ROUTE)];
            $data['status_labels']['sent'] = 'Передано почтовому серверу';
            if ($id !== null) {
                $data['quote'] = $service->find($id);
                if ($data['quote'] === null) { $this->fail(404, 'Расценка не найдена.'); return; }
                foreach (['download', 'retry', 'deleteAttachment'] as $name) { $data[$name] = $this->url->link(self::ROUTE, $query + ['id' => $id, 'action' => $name]); }
            } else {
                $data += $service->list($page); $page = $data['page'];
                foreach ($data['rows'] as &$row) { $row['href'] = $this->url->link(self::ROUTE, $query + ['id' => $row['public_id'], 'page' => $page]); } unset($row);
                $data['previous'] = $page > 1 ? $this->url->link(self::ROUTE, $query + ['page' => $page-1]) : '';
                $data['next'] = $page < $data['pages'] ? $this->url->link(self::ROUTE, $query + ['page' => $page+1]) : '';
            }
            $this->document->setTitle('Расценка списков');
            $data['header'] = $this->load->controller('common/header');
            $data['column_left'] = $this->load->controller('common/column_left');
            $data['footer'] = $this->load->controller('common/footer');
            $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh_quotes', $data));
        } catch (Throwable $e) { $this->log->write('СМ ТЕХНО: расценка недоступна (' . get_class($e) . ')'); $this->fail(503, 'Расценки временно недоступны.'); }
    }
}
