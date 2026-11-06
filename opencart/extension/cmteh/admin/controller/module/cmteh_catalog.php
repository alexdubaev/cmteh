<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Module;

use Cmteh\Catalog;
use Cmteh\Database;
use InvalidArgumentException;
use JsonException;
use Opencart\System\Engine\Controller;
use Throwable;

final class CmtehCatalog extends Controller
{
    private const ROUTE = 'extension/cmteh/module/cmteh_catalog';
    private const TYPES = [
        'product' => 'Товары', 'category' => 'Категории', 'brand' => 'Бренды',
        'article' => 'Статьи', 'page' => 'Страницы', 'home' => 'Главная',
        'navigation' => 'Меню', 'site' => 'Контакты и настройки',
    ];
    private const FIELDS = [
        'title' => ['Заголовок', 'text'], 'h1' => ['Заголовок страницы', 'text'],
        'name' => ['Название бренда', 'text'], 'sku' => ['Артикул', 'text'], 'mpn' => ['MPN', 'text'],
        'brand' => ['Название бренда', 'text'], 'brand_slug' => ['Адрес бренда', 'text'], 'category_slug' => ['Адрес категории', 'text'],
        'price_status' => ['Режим цены', ['on_request' => 'По запросу', 'fixed' => 'Фиксированная', 'hidden' => 'Скрыта']],
        'price' => ['Цена', 'number'], 'currency' => ['Код валюты', 'text'],
        'availability_status' => ['Наличие', ['' => 'Не указано', 'in_stock' => 'В наличии', 'on_request' => 'Под заказ', 'out_of_stock' => 'Нет в наличии']],
        'part_type' => ['Тип', ['' => 'Не указан', 'original' => 'Оригинал', 'oem' => 'OEM', 'analog' => 'Аналог']],
        'short_description' => ['Краткое описание', 'textarea'], 'full_description' => ['Описание товара', 'textarea'],
        'description' => ['Описание', 'textarea'], 'content' => ['Текст публикации', 'textarea'], 'excerpt' => ['Анонс', 'textarea'],
        'company_name' => ['Компания', 'text'], 'phone' => ['Телефон', 'text'], 'email' => ['Email', 'text'],
        'address' => ['Адрес', 'text'], 'working_hours' => ['Режим работы', 'text'], 'hero_title' => ['Заголовок обложки', 'text'],
        'hero_text' => ['Текст обложки', 'textarea'], 'label' => ['Подпись пункта меню', 'text'], 'url' => ['Адрес ссылки', 'text'],
        'location' => ['Область меню', ['header' => 'Шапка', 'footer' => 'Подвал', 'legal' => 'Правовая информация']],
        'seo_title' => ['SEO-заголовок', 'text'], 'seo_description' => ['SEO-описание', 'textarea'], 'canonical_url' => ['Канонический URL', 'text'],
        'published_at' => ['Дата публикации (ISO 8601)', 'text'], 'sort_order' => ['Порядок', 'integer'],
        'is_indexable' => ['Индексация', ['1' => 'Разрешена', '0' => 'Запрещена']],
        'status' => ['Статус', ['draft' => 'Черновик', 'published' => 'Опубликовано', 'archived' => 'В архиве']],
    ];
    private const TYPE_FIELDS = [
        'product' => ['title', 'sku', 'mpn', 'brand', 'brand_slug', 'category_slug', 'price_status', 'price', 'currency', 'availability_status', 'part_type', 'short_description', 'full_description'],
        'category' => ['title', 'h1', 'description'],
        'brand' => ['name', 'description'],
        'article' => ['title', 'excerpt', 'content'],
        'page' => ['title', 'h1', 'content'],
        'home' => ['h1', 'hero_title', 'hero_text'],
        'navigation' => ['label', 'url', 'location'],
        'site' => ['company_name', 'phone', 'email', 'address', 'working_hours'],
    ];

    public function index(): void
    {
        $token = $this->session->data['user_token'] ?? null;
        $suppliedToken = $this->request->get['user_token'] ?? null;
        if (!$this->user->isLogged() || !$this->user->hasPermission('access', self::ROUTE)
            || !is_string($token) || !is_string($suppliedToken) || $token === '' || !hash_equals($token, $suppliedToken)) {
            http_response_code(403);
            $this->response->setOutput('Недостаточно прав');
            return;
        }

        $canWrite = $this->user->hasPermission('modify', self::ROUTE);
        $isPost = ($this->request->server['REQUEST_METHOD'] ?? '') === 'POST';
        if ($isPost && !$canWrite) {
            http_response_code(403);
            $this->response->setOutput('Недостаточно прав для изменения каталога');
            return;
        }

        require_once DIR_EXTENSION . 'cmteh/system/Database.php';
        require_once DIR_EXTENSION . 'cmteh/system/Catalog.php';
        $catalog = new Catalog(Database::connectFromOpenCart(), DB_PREFIX, (int) $this->config->get('config_language_id'), 0, Database::privateDir());

        $type = $this->request->get['type'] ?? 'product';
        $type = is_string($type) && isset(self::TYPES[$type]) ? $type : 'product';
        $id = $_GET['id'] ?? '';
        $id = is_string($id) && mb_strlen($id) <= 160 ? $id : '';
        $page = (int) $this->request->get('page', 'int');
        $page = max(1, min(4000, $page));
        $fields = [...self::TYPE_FIELDS[$type], 'seo_title', 'seo_description', 'canonical_url', 'sort_order', 'published_at', 'is_indexable', 'status'];
        $link = fn(array $params): string => $this->url->link(self::ROUTE, ['user_token' => $token] + $params, true);
        $record = $id === '' ? null : $catalog->editRecord($type, $id);
        if ($id !== '' && $record === null) {
            http_response_code(404);
            $this->response->setOutput('Запись не найдена');
            return;
        }

        $error = null;
        if ($isPost) {
            try {
                $record = $this->readForm($type, $fields, $id, $_POST);
                $catalog->save($type, $record);
                $this->response->redirect($link(['type' => $type, 'id' => $record['id'], 'saved' => 1]));
                return;
            } catch (InvalidArgumentException | JsonException $exception) {
                http_response_code(422);
                $error = $exception->getMessage();
            } catch (Throwable $exception) {
                $this->log->write('СМ ТЕХНО: не удалось сохранить запись (' . get_class($exception) . ')');
                http_response_code(500);
                $error = 'Не удалось сохранить запись. Повторите попытку.';
            }
        }

        $record ??= ['id' => '', 'slug' => '', 'status' => 'draft', 'is_indexable' => true];
        $formFields = [];
        foreach ($fields as $field) {
            [$label, $control] = self::FIELDS[$field];
            $value = $record[$field] ?? '';
            $value = is_scalar($value) ? (string) $value : '';
            $formFields[] = compact('field', 'label', 'control', 'value') + ['select' => is_array($control)];
        }

        $rows = $catalog->editRows($type, $page);
        foreach ($rows as &$row) {
            $row['href'] = $link(['type' => $type, 'id' => $row['id'], 'page' => $page]);
        }

        $tabs = [];
        foreach (self::TYPES as $key => $title) {
            $tabs[] = ['title' => $title, 'href' => $link(['type' => $key]), 'active' => $key === $type];
        }

        $details = array_diff_key($record, array_flip(['id', 'slug', 'confirm_zero_price', ...$fields]));
        $this->document->setTitle('Каталог и публикации СМ ТЕХНО');
        $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh_catalog', [
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'tabs' => $tabs, 'rows' => $rows, 'error' => $error, 'can_write' => $canWrite, 'type' => $type,
            'edit_id' => $id, 'record' => $record, 'fields' => $formFields, 'type_label' => self::TYPES[$type],
            'details' => json_encode((object) $details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'action' => $link(['type' => $type, 'id' => $id, 'page' => $page]),
            'new_record' => $link(['type' => $type, 'new' => 1, 'page' => $page]),
            'show_form' => $isPost || $id !== '' || ($this->request->get['new'] ?? '') === '1',
            'saved' => ($this->request->get['saved'] ?? '') === '1',
            'previous' => $page > 1 ? $link(['type' => $type, 'page' => $page - 1]) : '',
            'next' => count($rows) === 25 ? $link(['type' => $type, 'page' => $page + 1]) : '',
        ]));
    }

    private function readForm(string $type, array $fields, string $id, array $post): array
    {
        $details = $post['details'] ?? '{}';
        if (!is_string($details) || strlen($details) > 300000) {
            throw new InvalidArgumentException('Дополнительные данные слишком велики');
        }
        $object = json_decode($details, false, 16, JSON_THROW_ON_ERROR);
        if (!$object instanceof \stdClass) {
            throw new InvalidArgumentException('Дополнительные данные должны быть объектом JSON');
        }
        $record = json_decode($details, true, 16, JSON_THROW_ON_ERROR);
        $record['id'] = $id !== '' ? $id : $this->formString($post, 'id');
        $record['slug'] = in_array($type, ['home', 'site'], true) ? $type : $this->formString($post, 'slug');
        foreach ($fields as $field) {
            $value = $this->formString($post, $field);
            $record[$field] = match ($field) {
                'price' => $value !== '' && is_numeric($value) ? (float) $value : null,
                'sort_order' => (int) $value,
                'is_indexable' => $value === '1',
                default => $value,
            };
        }
        $record['confirm_zero_price'] = ($post['confirm_zero_price'] ?? null) === '1';
        return $record;
    }

    private function formString(array $post, string $field): string
    {
        $value = $post[$field] ?? '';
        if (!is_string($value)) {
            throw new InvalidArgumentException('Некорректное поле формы');
        }
        return $value;
    }
}
