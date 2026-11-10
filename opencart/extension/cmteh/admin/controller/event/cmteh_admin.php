<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Event;

use Opencart\System\Engine\Controller;

/** OpenCart 4.1.0.4 view/language events; never changes the data language or ACL. */
final class CmtehAdmin extends Controller
{
    public function menu(string &$route, array &$data, string &$code): void
    {
        if (!$this->user->isLogged()) {
            return;
        }
        $children = $this->links();
        if ($children) {
            array_unshift($data['menus'], [
                'id' => 'menu-cmteh', 'icon' => 'fas fa-building', 'name' => 'СМ ТЕХНО',
                'href' => '', 'children' => $children,
            ]);
        }
    }

    public function links(): array
    {
        $base = 'extension/cmteh/module/';
        $items = [
            ['Главная', $base . 'cmteh', ''],
            ['Товары', $base . 'cmteh_catalog', 'product'],
            ['Категории', $base . 'cmteh_catalog', 'category'],
            ['Бренды', $base . 'cmteh_catalog', 'brand'],
            ['Страницы', $base . 'cmteh_catalog', 'page'],
            ['Главная страница', $base . 'cmteh_catalog', 'home'],
            ['Статьи', $base . 'cmteh_catalog', 'article'],
            ['Меню и подвал', $base . 'cmteh_catalog', 'navigation'],
            ['Заявки', $base . 'cmteh_leads', ''],
            ['Настройки сайта', $base . 'cmteh_catalog', 'site'],
            ['Товары OpenCart', 'catalog/product', ''],
            ['Категории OpenCart', 'catalog/category', ''],
            ['Бренды OpenCart', 'catalog/manufacturer', ''],
        ];
        $links = [];
        foreach ($items as [$name, $route, $type]) {
            if ($this->user->hasPermission('access', $route)) {
                $query = ['user_token' => $this->session->data['user_token']];
                if ($type !== '') {
                    $query['type'] = $type;
                }
                $links[] = ['name' => $name, 'href' => $this->url->link($route, $query), 'children' => []];
            }
        }
        return $links;
    }

    public function language(string &$route, string &$prefix, string &$code, array &$output): void
    {
        // startup/language loads default before DB events are registered. Apply the
        // common vocabulary at the next language load, without changing its code/ID.
        $_ = [];
        require DIR_EXTENSION . 'cmteh/admin/language/ru-ru/default.php';
        foreach ($_ as $key => $value) {
            $this->language->set($key, $value);
            $output[$key] = $value;
        }
        // Only fixed native routes are eligible; language callbacks never load themselves.
        $file = DIR_EXTENSION . 'cmteh/admin/language/ru-ru/' . $route . '.php';
        if (!in_array($route, ['default', 'catalog/product', 'catalog/category', 'catalog/manufacturer',
            'common/login', 'common/header', 'common/column_left', 'common/footer', 'common/filemanager',
            'common/pagination', 'error/permission', 'error/not_found'], true) || !is_file($file)) {
            return;
        }
        $_ = [];
        require $file;
        foreach ($_ as $key => $value) {
            $key = $prefix !== '' ? $prefix . '_' . $key : $key;
            $this->language->set($key, $value);
            $output[$key] = $value;
        }
    }
}
