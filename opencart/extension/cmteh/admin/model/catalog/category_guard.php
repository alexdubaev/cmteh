<?php

declare(strict_types=1);

namespace Opencart\Admin\Model\Extension\Cmteh\Catalog;

use Cmteh\InventoryException;
use Cmteh\InventoryNativeGuard;
use Opencart\System\Engine\Model;

final class CategoryGuard extends Model
{
    private bool $rewritingHierarchy = false;

    private function guard(): InventoryNativeGuard
    {
        return $this->registry->get('cmteh_inventory_native');
    }

    private function native(): object
    {
        return $this->registry->get('fallback_model_catalog_category') ?? $this->factory->model('catalog/category');
    }

    private function parentExists(int $parent, int $child = 0): void
    {
        if ($parent < 0) throw new InventoryException('category_missing', 'Некорректная родительская категория.', 409);
        $seen = [];
        while ($parent > 0) {
            if ($parent === $child || isset($seen[$parent])) {
                throw new InventoryException('category_cycle', 'Недопустимая иерархия категорий.', 409);
            }
            $seen[$parent] = true;
            $row = $this->db->query('SELECT parent_id FROM `' . DB_PREFIX . 'category` WHERE category_id=' . $parent . ' LOCK IN SHARE MODE')->row;
            if (!$row) throw new InventoryException('category_missing', 'Родительская категория была удалена. Обновите форму.', 409);
            $parent = (int) $row['parent_id'];
        }
    }

    private function rewriteHierarchy(callable $write): void
    {
        if ($this->rewritingHierarchy) {
            $write();
            return;
        }
        $this->guard()->run([], function () use ($write): void {
            // OpenCart repairs the descendant closure table as part of reparenting.
            $this->db->query('SELECT category_id FROM `' . DB_PREFIX . 'category` ORDER BY category_id FOR UPDATE');
            $this->rewritingHierarchy = true;
            try {
                $write();
            } finally {
                $this->rewritingHierarchy = false;
            }
        });
    }

    public function assertDeletable(array $ids): void
    {
        if (!$this->guard()->active()) throw new \LogicException('Category deletion requires a native transaction');
        if ($ids === []) return;
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);
        $list = implode(',', $ids);
        $this->db->query('SELECT category_id FROM `' . DB_PREFIX . "category` WHERE category_id IN ($list) ORDER BY category_id FOR UPDATE");
        $queries = [
            'category' => "parent_id IN ($list)",
            'product_to_category' => "category_id IN ($list)",
            'category_path' => "path_id IN ($list) AND category_id<>path_id",
        ];
        foreach ($queries as $table => $condition) {
            if ($this->db->query('SELECT 1 FROM `' . DB_PREFIX . $table . "` WHERE $condition LIMIT 1 LOCK IN SHARE MODE")->num_rows) {
                throw new InventoryException('category_linked', 'Категории связаны с товарами или дочерними категориями. Сначала снимите связи. Ничего не удалено.', 200);
            }
        }
    }

    private function synchronize(string $method, int $id): void
    {
        if ($this->guard()->active()) return;
        if ($this->guard()->projectionDeferred()) {
            $this->registry->set('cmteh_catalog_sync_warning', (object) [
                'message' => 'Изменение категории выполнялось внутри внешней транзакции. После её завершения повторите сохранение для обновления витрины.',
            ]);
            return;
        }
        $route = 'catalog/category.' . $method;
        $args = [$id];
        $output = $id;
        $this->factory->controller('extension/cmteh/event/cmteh_catalog')->synchronize($route, $args, $output);
    }

    public function addCategory(array $data): int
    {
        $id = $this->guard()->run([], function () use ($data): int {
            $this->parentExists((int) ($data['parent_id'] ?? 0));
            return $this->native()->addCategory($data);
        });
        $this->synchronize('addCategory', $id);
        return $id;
    }

    public function editCategory(int $category_id, array $data): void
    {
        $this->rewriteHierarchy(function () use ($category_id, $data): void {
            if (!$this->db->query('SELECT category_id FROM `' . DB_PREFIX . 'category` WHERE category_id=' . $category_id . ' FOR UPDATE')->num_rows) {
                throw new InventoryException('category_missing', 'Категория была удалена. Обновите форму.', 409);
            }
            $this->parentExists((int) ($data['parent_id'] ?? 0), $category_id);
            $this->native()->editCategory($category_id, $data);
        });
        $this->synchronize('editCategory', $category_id);
    }

    public function deleteCategory(int $category_id): void
    {
        $this->guard()->run([], function () use ($category_id): void {
            if (!($this->registry->get('cmteh_category_controller')->deletingBatch ?? false)) {
                $this->assertDeletable([$category_id]);
            }
            $this->native()->deleteCategory($category_id);
        });
        $this->synchronize('deleteCategory', $category_id);
    }

    public function repairCategories(int $parent_id = 0): void
    {
        $this->rewriteHierarchy(fn() => $this->native()->repairCategories($parent_id));
    }
}
