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
        try {
            require_once DIR_EXTENSION . 'cmteh/system/Lead.php';
            $service = new Lead(Database::connectFromOpenCart(), DB_PREFIX);
            $data = [
                'status_labels' => self::STATUS_LABELS,
                'lead' => null,
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

    private function fail(int $status, string $message): void
    {
        http_response_code($status);
        $this->response->addHeader('Content-Type: text/plain; charset=utf-8');
        $this->response->setOutput($message);
    }
}
