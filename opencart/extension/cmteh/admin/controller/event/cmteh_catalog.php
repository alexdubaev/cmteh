<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Event;

use Cmteh\Database;
use Cmteh\NativeCatalog;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehCatalog extends Controller
{
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
