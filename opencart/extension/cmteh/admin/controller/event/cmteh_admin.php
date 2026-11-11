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
        require_once DIR_EXTENSION . 'cmteh/system/AdminCapabilities.php';
        $data['menus']=$this->applicableMenus($data['menus'] ?? []);
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
        $leads = $this->businessLink('Заявки и расценки','requests');
        if ($leads) $menus[] = ['id'=>'menu-requests','icon'=>'fas fa-inbox','name'=>'Обращения','href'=>'','children'=>[$leads]];
        foreach (['contacts'=>['Контакты','fas fa-address-book'], 'reports'=>['Отчёты','fas fa-chart-bar']] as $view=>[$label,$icon]) {
            $link=$this->businessLink($label,$view);
            if ($link) $menus[]=['id'=>'menu-'.$view,'icon'=>$icon,'name'=>$label,'href'=>$link['href'],'children'=>[]];
        }
        $sources=$this->businessLink('Источники обращений','sources');
        if ($sources) {
            if ($marketing) $marketing[0]['children'][]=$sources;
            else $marketing[]=['id'=>'menu-marketing','icon'=>'fas fa-share-alt','name'=>'Маркетинг','href'=>'','children'=>[$sources]];
        }
        $capabilities=$this->businessLink('Возможности и доработки','capabilities');
        if ($capabilities) $settings[]=['id'=>'menu-capabilities','icon'=>'fas fa-circle-info','name'=>$capabilities['name'],'href'=>$capabilities['href'],'children'=>[]];
        $notifications=$this->link('Системные уведомления платформы','tool/notification');
        if ($notifications) $settings[]=['id'=>'menu-platform-notifications','icon'=>'fas fa-bell','name'=>$notifications['name'],'href'=>$notifications['href'],'children'=>[]];
        $site = $this->link('Настройки сайта', 'extension/cmteh/module/cmteh_catalog', 'site');
        if ($site) $settings[] = ['id'=>'menu-site','icon'=>'fas fa-building','name'=>'Настройки сайта','href'=>$site['href'],'children'=>[]];
        $data['menus'] = array_merge($menus, $marketing, $settings);
        $route = 'extension/cmteh/common/column_left';
    }

    private function applicableMenus(array $menus): array
    {
        $allowed=\Cmteh\AdminCapabilities::nativeRoutes(); $result=[];
        foreach ($menus as $menu) {
            if (($menu['id'] ?? '') === 'menu-dashboard') { $result[]=$menu; continue; }
            $children=$this->applicableChildren($menu['children'] ?? [],$allowed);
            if (!$children) continue;
            $menu['children']=$children;
            if ($menu['id']==='menu-extension') $menu['name']='Обслуживание платформы';
            $result[]=$menu;
        }
        return $result;
    }

    private function applicableChildren(array $children,array $allowed): array
    {
        $result=[];
        foreach ($children as $child) {
            $nested=$this->applicableChildren($child['children'] ?? [],$allowed);
            $query=[]; parse_str((string)parse_url(html_entity_decode($child['href'] ?? ''),PHP_URL_QUERY),$query);
            $route=$query['route'] ?? '';
            if (isset($allowed[$route]) || in_array($route,['catalog/product','catalog/category','catalog/manufacturer'],true)) {
                if (isset($allowed[$route])) $child['name']=$allowed[$route];
                $child['children']=$nested; $result[]=$child;
            } elseif ($nested) { $child['children']=$nested; $child['href']=''; $result[]=$child; }
        }
        return $result;
    }

    private function businessLink(string $name,string $view): ?array
    {
        $link=$this->link($name,'extension/cmteh/module/cmteh_leads');
        if ($link) $link['href']=$this->url->link('extension/cmteh/module/cmteh_leads',['user_token'=>$this->session->data['user_token'],'view'=>$view]);
        return $link;
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
        $data['cmteh_requests']=$this->businessLink('Обращения','requests')['href'] ?? '';
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
        require_once DIR_EXTENSION . 'cmteh/system/AdminCapabilities.php';
        $data['capabilities']=\Cmteh\AdminCapabilities::all();
        if ($this->user->hasPermission('access','extension/cmteh/module/cmteh_leads')) {
            try {
                require_once DIR_EXTENSION . 'cmteh/system/Database.php';
                require_once DIR_EXTENSION . 'cmteh/system/AdminBusiness.php';
                $business=new \Cmteh\AdminBusiness(\Cmteh\Database::connectFromOpenCart(),DB_PREFIX);
                $data['summary']=$business->dashboard();
                $data['status_labels']=['pending'=>'В очереди','sending'=>'Передаётся почтовому серверу','retry'=>'Повторная отправка','sent'=>'Передано почтовому серверу','failed'=>'Не доставлено','unknown'=>'Нет состояния'];
                $data['business_reports']=$this->businessLink('Отчёты по обращениям','reports')['href'];
            } catch (\Throwable $e) { $data['business_error']='Данные обращений временно недоступны.'; }
        }
        $route = 'extension/cmteh/common/dashboard';
    }

    public function marketing(string &$route,array &$data,string &$code,string &$output): void
    {
        if (!$this->authenticated() || !$this->user->hasPermission('access','marketing/marketing')) return;
        if (!in_array($route,['marketing/marketing','marketing/marketing_list','marketing/marketing_form','marketing/marketing_report'],true)) return;
        if ($route==='marketing/marketing_report') {
            $this->load->model('marketing/marketing');
            $this->load->model('setting/store');
            $id=(int)($this->request->get['marketing_id'] ?? 0);
            $page=($this->request->get['route'] ?? '')==='marketing/marketing.report'
                ? max(1,(int)($this->request->get['page'] ?? 1)) : 1;
            $data['reports']=[];
            foreach ($this->model_marketing_marketing->getReports($id,($page-1)*10,10) as $report) {
                $store=(int)$report['store_id']===0 ? 'СМ ТЕХНО'
                    : html_entity_decode($this->model_setting_store->getStore((int)$report['store_id'])['name'] ?? 'Другой магазин',ENT_QUOTES,'UTF-8');
                $data['reports'][]=['ip'=>$report['ip'],'store'=>$store,'country'=>$report['country'],
                    'date_added'=>date($this->language->get('datetime_format'),strtotime($report['date_added']))];
            }
            $route='extension/cmteh/marketing/marketing_report';
            return;
        }
        $data['cmteh_can_modify']=$this->user->hasPermission('modify','marketing/marketing');
        // Native stores Request::clean's entities. Own templates consistently escape
        // their text, while all navigation uses array arguments, never raw filter args.
        foreach (['name','description','code','filter_name','filter_code','filter_date_from','filter_date_to'] as $field) {
            if (is_string($data[$field] ?? null)) $data[$field]=html_entity_decode($data[$field],ENT_QUOTES,'UTF-8');
        }
        $params=['user_token'=>$this->session->data['user_token']];
        foreach (['filter_name','filter_code','filter_date_from','filter_date_to'] as $field) {
            if (is_string($this->request->get[$field] ?? null)) $params[$field]=html_entity_decode($this->request->get[$field],ENT_QUOTES,'UTF-8');
        }
        $sort=$this->request->get['sort'] ?? 'm.name';
        $params['sort']=in_array($sort,['m.name','m.code','m.date_added'],true) ? $sort : 'm.name';
        $params['order']=($this->request->get['order'] ?? '')==='DESC' ? 'DESC' : 'ASC';
        $page=$this->request->get['page'] ?? '1';
        $params['page']=is_string($page) && preg_match('/^[0-9]{1,9}$/D',$page) ? max(1,(int)$page) : 1;
        foreach (['add'=>'marketing/marketing.form','action'=>'marketing/marketing.list','back'=>'marketing/marketing'] as $key=>$target) $data[$key]=$this->url->link($target,$params);
        foreach (['delete','save'] as $key) $data[$key]=$this->url->link('marketing/marketing.'.$key,['user_token'=>$params['user_token']]);
        foreach ($data['breadcrumbs'] ?? [] as $index=>$breadcrumb) {
            if (str_contains($breadcrumb['href'],'route=marketing/marketing')) $data['breadcrumbs'][$index]['href']=$this->url->link('marketing/marketing',$params);
        }
        foreach (['name','code','date_added'] as $field) $data['sort_'.$field]=$this->url->link('marketing/marketing.list',array_replace($params,['sort'=>'m.'.$field,'order'=>$params['order']==='ASC'?'DESC':'ASC']));
        if (isset($data['marketings'])) {
            $this->load->model('marketing/marketing');
            foreach ($data['marketings'] as &$campaign) {
                foreach (['name','code'] as $field) $campaign[$field]=html_entity_decode($campaign[$field],ENT_QUOTES,'UTF-8');
                $campaign['tracked_requests']=$this->model_marketing_marketing->getTotalReports((int)$campaign['marketing_id']);
                $campaign['edit']=$this->url->link('marketing/marketing.form',array_replace($params,['marketing_id'=>(int)$campaign['marketing_id']]));
            }
            unset($campaign);
            $this->load->model('marketing/marketing');
            $filter=[];
            foreach (['filter_name','filter_code','filter_date_from','filter_date_to'] as $field) $filter[$field]=$this->request->get[$field] ?? '';
            $pagination=$params; unset($pagination['page']);
            $data['pagination']=$this->load->controller('common/pagination',[
                'total'=>$this->model_marketing_marketing->getTotalMarketings($filter),
                'page'=>$params['page'],'limit'=>(int)$this->config->get('config_pagination_admin'),
                'url'=>$this->url->link('marketing/marketing.list',$pagination).'&amp;page={page}',
            ]);
        }
        $data['cmteh_sources']=$this->businessLink('Источники обращений','sources')['href'] ?? '';
        $data['cmteh_store']=rtrim(HTTP_CATALOG,'/').'/';
        $route='extension/cmteh/'.$route;
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
            'common/pagination', 'error/permission', 'error/not_found', 'marketing/marketing'], true) || !is_file($file)) {
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
