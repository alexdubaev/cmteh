<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Event;

use Cmteh\Database;
use Cmteh\NativeCatalog;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehCatalog extends Controller
{
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
