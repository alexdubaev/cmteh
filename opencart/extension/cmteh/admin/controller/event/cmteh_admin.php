<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Event;

use Opencart\System\Engine\Controller;

/** OpenCart 4.1.0.4 view/language events; never changes the data language or ACL. */
final class CmtehAdmin extends Controller
{
    private function authenticated(): bool
    {
        $token = $this->session->data['user_token'] ?? '';
        return $this->user->isLogged() && $token !== ''
            && hash_equals((string)$token, (string)($this->request->get['user_token'] ?? ''));
    }

    /** Preserve the native ACL-filtered system/tool tree; replace ordinary entry points. */
    public function menu(string &$route, array &$data, string &$code, string &$output): void
    {
        if (!$this->authenticated()) return;
        $menus = [];
        $catalog = [];
        $content = [];
        $settings = [];
        $marketing = [];
        foreach ($data['menus'] ?? [] as $menu) {
            if ($menu['id'] === 'menu-dashboard') {
                $menu['name'] = 'Главная'; $menus[] = $menu; continue;
            }
            if ($menu['id'] === 'menu-catalog') {
                foreach ($menu['children'] as $child) {
                    if (!preg_match('~[?&]route=catalog/(product|category|manufacturer)(?:&|$)~', html_entity_decode($child['href']))) {
                        $catalog[] = $child;
                    }
                }
                continue;
            }
            if ($menu['id'] === 'menu-cms') { $content = $menu['children']; continue; }
            // Native commerce/customer widgets are not connected to the request workflow.
            // Their applicability is explained separately; direct native routes keep their ACL.
            if (in_array($menu['id'], ['menu-sale','menu-customer','menu-report'], true)) continue;
            if ($menu['id'] === 'menu-marketing') {
                $menu['children'] = array_values(array_filter($menu['children'],
                    fn(array $child): bool => str_contains(html_entity_decode($child['href']), 'route=marketing/marketing')));
                if ($menu['children']) { $menu['name'] = 'Маркетинг'; $marketing[] = $menu; }
                continue;
            }
            if ($menu['id'] === 'menu-system') { $menu['name'] = 'Настройки'; $settings[] = $menu; continue; }
            $settings[] = $menu;
        }
        $main = [];
        foreach (['product'=>['Товары','catalog/product'], 'category'=>['Категории','catalog/category'],
            'brand'=>['Бренды','catalog/manufacturer']] as $type => [$label, $native]) {
            $link = $this->link($label, 'extension/cmteh/module/cmteh_catalog', $type)
                ?? $this->link($label, $native);
            if ($link) $main[] = $link;
        }
        if ($catalog) $main[] = ['name'=>'Дополнительные инструменты', 'href'=>'', 'children'=>$catalog];
        if ($main) $menus[] = ['id'=>'menu-catalog','icon'=>'fas fa-tags','name'=>'Каталог','href'=>'','children'=>$main];
        foreach (['page'=>'Страницы','home'=>'Главная страница','article'=>'Статьи','navigation'=>'Меню и подвал'] as $type=>$label) {
            $link = $this->link($label, 'extension/cmteh/module/cmteh_catalog', $type);
            if ($link) $content[] = $link;
        }
        if ($content) $menus[] = ['id'=>'menu-cms','icon'=>'fas fa-file-alt','name'=>'Содержимое','href'=>'','children'=>$content];
        $leads = $this->link('Заявки и расценки', 'extension/cmteh/module/cmteh_leads');
        if ($leads) $menus[] = ['id'=>'menu-requests','icon'=>'fas fa-inbox','name'=>'Обращения','href'=>'','children'=>[$leads]];
        $site = $this->link('Настройки сайта', 'extension/cmteh/module/cmteh_catalog', 'site');
        if ($site) $settings[] = ['id'=>'menu-site','icon'=>'fas fa-building','name'=>'Настройки сайта','href'=>$site['href'],'children'=>[]];
        $data['menus'] = array_merge($menus, $marketing, $settings);
        $route = 'extension/cmteh/common/column_left';
    }

    private function link(string $name, string $route, string $type = ''): ?array
    {
        if (!$this->authenticated() || !$this->user->hasPermission('access', $route)) return null;
        $query = ['user_token'=>$this->session->data['user_token']];
        if ($type !== '') $query['type'] = $type;
        return ['name'=>$name,'href'=>$this->url->link($route,$query),'children'=>[]];
    }

    /** Reusable ACL-filtered operational links, shared with the module and business dashboard. */
    public function links(): array
    {
        $links = [];
        foreach (['product'=>'Товары','category'=>'Категории','brand'=>'Бренды','page'=>'Страницы',
            'home'=>'Главная страница','article'=>'Статьи','navigation'=>'Меню и подвал','site'=>'Настройки сайта'] as $type=>$name) {
            $native = ['product'=>'catalog/product','category'=>'catalog/category','brand'=>'catalog/manufacturer'][$type] ?? '';
            $link = $this->link($name,'extension/cmteh/module/cmteh_catalog',$type)
                ?? ($native ? $this->link($name,$native) : null);
            if ($link) $links[] = $link;
        }
        $leads = $this->link('Заявки', 'extension/cmteh/module/cmteh_leads');
        if ($leads) $links[] = $leads;
        return $links;
    }

    public function header(string &$route, array &$data, string &$code, string &$output): void
    {
        // Native controller owns logged/home/profile/logout/assets and session decisions.
        $data['cmteh_username'] = $this->authenticated() ? $this->user->getUsername() : '';
        // Native flag URLs live under the protected language directory. Keep the native
        // selector/handlers and accessible language name, without opening that directory.
        if (is_string($data['language'] ?? null)) {
            $data['language'] = preg_replace('~<img\b[^>]*\balt="([^"]*)"[^>]*>~i',
                '<i class="fa-solid fa-language" role="img" aria-label="$1" title="$1"></i>', $data['language']);
        }
        $route = 'extension/cmteh/common/header';
    }

    public function dashboard(string &$route, array &$data, string &$code, string &$output): void
    {
        if (!$this->authenticated()) return;
        $data['heading_title'] = 'Рабочий стол СМ ТЕХНО';
        if (!empty($data['breadcrumbs'])) $data['breadcrumbs'][array_key_last($data['breadcrumbs'])]['text'] = 'Рабочий стол';
        $data['cmteh_links'] = $this->links();
        $route = 'extension/cmteh/common/dashboard';
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
