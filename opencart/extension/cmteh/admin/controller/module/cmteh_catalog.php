<?php

declare(strict_types=1);

namespace Opencart\Admin\Controller\Extension\Cmteh\Module;

use Cmteh\Catalog;
use Cmteh\CatalogInput;
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
        'brand_slug' => ['Бренд', 'text'], 'category_slug' => ['Основная категория', 'text'],
        'parent_slug' => ['Родительская категория', 'text'], 'image_path' => ['Изображение', 'image'],
        'main_image_path' => ['Основное фото', 'image'], 'delivery_status' => ['Условия доставки товара', 'text'],
        'date' => ['Дата статьи', 'text'], 'author' => ['Автор статьи', 'text'],
        'price_status' => ['Режим цены', ['on_request' => 'По запросу', 'fixed' => 'Фиксированная', 'hidden' => 'Скрыта']],
        'price' => ['Цена', 'number'], 'currency' => ['Код валюты', 'text'],
        'availability_status' => ['Наличие', ['' => 'Не указано', 'in_stock' => 'В наличии', 'on_request' => 'Под заказ', 'out_of_stock' => 'Нет в наличии']],
        'part_type' => ['Тип', ['' => 'Не указан', 'original' => 'Оригинал', 'oem' => 'OEM', 'analog' => 'Аналог']],
        'short_description' => ['Краткое описание', 'textarea'], 'full_description' => ['Описание товара', 'rich'],
        'description' => ['Описание', 'rich'], 'content' => ['Текст публикации', 'rich'], 'excerpt' => ['Анонс', 'textarea'],
        'company_name' => ['Компания', 'text'], 'phone' => ['Телефон', 'text'], 'email' => ['Email', 'text'],
        'address' => ['Адрес', 'text'], 'working_hours' => ['Режим работы', 'text'], 'hero_title' => ['Заголовок обложки', 'text'],
        'hero_text' => ['Текст обложки', 'textarea'], 'label' => ['Подпись пункта меню', 'text'], 'url' => ['Адрес ссылки', 'text'],
        'location' => ['Область меню', ['header' => 'Шапка', 'footer' => 'Подвал', 'legal' => 'Правовая информация']],
        'intro' => ['Вступление', 'textarea'],
        'logo' => ['Логотип', 'image'],
        'city' => ['Город', 'text'],
        'legal_name' => ['Юридическое наименование', 'text'],
        'legal_address' => ['Юридический адрес', 'text'],
        'inn' => ['ИНН', 'text'],
        'kpp' => ['КПП', 'text'],
        'ogrn' => ['ОГРН', 'text'],
        'vat_info' => ['Информация о НДС', 'textarea'],
        'footer_text' => ['Описание в подвале', 'textarea'],
        'footer_disclaimer' => ['Примечание в подвале', 'textarea'],
        'footer_brand_label' => ['Метка в подвале', 'text'],
        'footer_sections_title' => ['Заголовок разделов в подвале', 'text'],
        'footer_contacts_title' => ['Заголовок контактов в подвале', 'text'],
        'footer_copyright' => ['Подпись об авторских правах', 'text'],
        'button_text' => ['Подпись кнопки страницы', 'text'],
        'button_url' => ['Адрес кнопки страницы', 'text'],
        'product_delivery_title' => ['Заголовок доставки в карточке товара', 'text'],
        'product_delivery_text' => ['Условия доставки в карточке товара', 'textarea'],
        'product_documents_title' => ['Заголовок документов в карточке товара', 'text'],
        'product_documents_text' => ['Условия по документам в карточке товара', 'textarea'],
        'product_missing_description' => ['Подсказка при отсутствии описания товара', 'textarea'],
        'product_no_detail_text' => ['Подсказка при отсутствии подробностей о товаре', 'textarea'],
        'product_analog_intro' => ['Примечание к аналогам товара', 'textarea'],
        'product_relation_intro' => ['Примечание к связанным товарам', 'textarea'],
        'brand_business_label' => ['Краткая подпись на странице бренда', 'text'],
        'brand_business_text' => ['Условия работы на странице бренда', 'textarea'],
        'request_form_hint' => ['Примечание к форме заявки', 'textarea'],
        'article_cta_text' => ['Текст блока заявки в статье', 'textarea'],
        'article_cta_button_text' => ['Подпись кнопки заявки в статье', 'text'],
        'article_cta_button_url' => ['Адрес кнопки заявки в статье', 'text'],
        'hero_image_path' => ['Обложка', 'image'],
        'hero_mobile_image_path' => ['Обложка для телефона', 'image'],
        'hero_image_alt' => ['Описание обложки', 'text'],
        'hero_search_placeholder' => ['Подсказка в поле поиска', 'text'],
        'hero_search_button_text' => ['Кнопка поиска', 'text'],
        'hero_primary_button_text' => ['Первая кнопка обложки', 'text'],
        'hero_primary_button_url' => ['Ссылка первой кнопки', 'text'],
        'hero_secondary_button_text' => ['Вторая кнопка обложки', 'text'],
        'hero_secondary_button_url' => ['Ссылка второй кнопки', 'text'],
        'hero_quick_title' => ['Заголовок быстрых ссылок', 'text'],
        'hero_article_link_text' => ['Быстрая ссылка на поиск', 'text'],
        'hero_brand_link_text' => ['Быстрая ссылка на бренды', 'text'],
        'brands_title' => ['Заголовок блока брендов', 'text'],
        'brands_link_text' => ['Ссылка на все бренды', 'text'],
        'brands_scroll_hint' => ['Подсказка прокрутки брендов', 'text'],
        'categories_title' => ['Заголовок блока категорий', 'text'],
        'categories_link_text' => ['Ссылка из блока категорий', 'text'],
        'products_title' => ['Заголовок блока товаров', 'text'],
        'products_link_text' => ['Ссылка из блока товаров', 'text'],
        'articles_title' => ['Заголовок блока статей', 'text'],
        'articles_link_text' => ['Ссылка на все статьи', 'text'],
        'cover_image_path' => ['Обложка публикации', 'image'],
        'og_image_path' => ['Изображение для публикации ссылки', 'image'],
        'image_alt' => ['Описание изображения', 'text'],
        'seo_text' => ['Дополнительный текст', 'textarea'],
        'open_in_new_tab' => ['Открывать в новой вкладке', ['0' => 'Нет', '1' => 'Да']],
        'seo_title' => ['SEO-заголовок', 'text'], 'seo_description' => ['SEO-описание', 'textarea'], 'canonical_url' => ['Канонический URL', 'text'],
        'published_at' => ['Дата публикации (ISO 8601)', 'text'], 'sort_order' => ['Порядок', 'integer'],
        'is_indexable' => ['Индексация', ['1' => 'Разрешена', '0' => 'Запрещена']],
        'status' => ['Статус', ['draft' => 'Черновик', 'published' => 'Опубликовано', 'archived' => 'В архиве']],
    ];
    private const TYPE_FIELDS = [
        'product' => ['title', 'sku', 'mpn', 'brand_slug', 'category_slug', 'price_status', 'price', 'currency', 'availability_status', 'part_type', 'short_description', 'full_description', 'main_image_path', 'delivery_status'],
        'category' => ['title', 'h1', 'description', 'parent_slug', 'image_path'],
        'brand' => ['name', 'description', 'image_path'],
        'article' => ['title', 'date', 'author', 'excerpt', 'content', 'cover_image_path', 'image_alt', 'og_image_path'],
        'page' => ['title', 'h1', 'intro', 'content', 'button_text', 'button_url', 'cover_image_path', 'image_alt', 'og_image_path', 'seo_text'],
        'home' => [
            'h1', 'hero_title', 'hero_text', 'hero_image_path', 'hero_mobile_image_path', 'hero_image_alt',
            'hero_primary_button_text', 'hero_primary_button_url', 'hero_secondary_button_text', 'hero_secondary_button_url',
            'hero_search_placeholder', 'hero_search_button_text', 'hero_quick_title', 'hero_article_link_text', 'hero_brand_link_text',
            'brands_title', 'brands_link_text', 'brands_scroll_hint', 'categories_title', 'categories_link_text',
            'products_title', 'products_link_text', 'articles_title', 'articles_link_text',
        ],
        'navigation' => ['label', 'location', 'open_in_new_tab'],
        'site' => [
            'company_name', 'logo', 'phone', 'email', 'city', 'address', 'working_hours',
            'legal_name', 'legal_address', 'inn', 'kpp', 'ogrn', 'vat_info', 'footer_text', 'footer_disclaimer',
            'footer_brand_label', 'footer_sections_title', 'footer_contacts_title', 'footer_copyright',
            'product_delivery_title', 'product_delivery_text', 'product_documents_title', 'product_documents_text',
            'product_missing_description', 'product_no_detail_text', 'product_analog_intro', 'product_relation_intro',
            'brand_business_label', 'brand_business_text', 'request_form_hint',
            'article_cta_text', 'article_cta_button_text', 'article_cta_button_url',
        ],
    ];

    private const LEGACY_IMAGES = [
        'hero_image_path' => 'hero_image', 'cover_image_path' => 'cover_image', 'og_image_path' => 'og_image',
        'main_image_path' => 'main_image', 'image_path' => 'image',
    ];

    private const SECTION_FIELDS = [
        'section_type' => 'Вид раздела', 'title' => 'Заголовок', 'subtitle' => 'Подзаголовок',
        'text' => 'Текст', 'image_path' => 'Изображение', 'image_alt' => 'Описание изображения',
        'button_text' => 'Подпись кнопки', 'button_url' => 'Адрес кнопки',
    ];
    private const ITEM_FIELDS = [
        'title' => 'Заголовок или вопрос', 'text' => 'Текст или ответ', 'url' => 'Ссылка',
        'button_text' => 'Подпись кнопки', 'text_from' => 'Текст из настроек', 'links_from' => 'Ссылки из каталога',
    ];
    private const SECTION_TYPES = [
        'custom' => 'Текст и изображение', 'cta' => 'Призыв к действию', 'faq' => 'Вопросы и ответы',
        'steps' => 'Шаги', 'columns' => 'Колонки', 'features' => 'Карточки',
        'delivery_intro' => 'Вступление о доставке', 'home_order' => 'Порядок заказа',
        'parts_request' => 'Заявка на запчасти', 'lead_form' => 'Ссылка на форму заявки',
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
        $search = is_string($this->request->get['search'] ?? null) ? mb_substr($this->request->get['search'], 0, 128) : '';
        $status = is_string($this->request->get['status'] ?? null) && in_array($this->request->get['status'], ['', 'published', 'draft', 'archived'], true) ? $this->request->get['status'] : '';
        $page = (int) $this->request->get('page', 'int');
        $page = max(1, min(4000, $page));
        $fields = [...self::TYPE_FIELDS[$type], 'seo_title', 'seo_description', 'canonical_url', 'sort_order', 'published_at', 'is_indexable', 'status'];
        $link = fn(array $params): string => $this->url->link(self::ROUTE, ['user_token' => $token] + $params, true);
        $choices = [];
        foreach (['brand', 'category', 'product', 'page'] as $choiceType) {
            $choices[$choiceType] = $catalog->editorChoices($choiceType);
        }
        $record = $id === '' ? null : $catalog->editRecord($type, $id);
        if ($id !== '' && $record === null) {
            http_response_code(404);
            $this->response->setOutput('Запись не найдена');
            return;
        }

        $showSections = in_array($type, ['page', 'home', 'site'], true);
        $canBrowseImages = $canWrite && $this->user->hasPermission('access', 'common/filemanager');
        $showForm = $isPost || $id !== '' || ($this->request->get['new'] ?? '') === '1';
        if ($showForm && $canWrite) {
            $this->document->addScript('view/javascript/ckeditor/ckeditor.js');
            $this->document->addScript('view/javascript/ckeditor/adapters/jquery.js');
        }

        $sectionRevision = $this->sectionRevision($record['sections'] ?? []);
        $submittedSections = [];
        $error = null;
        if ($isPost) {
            try {
                if (($this->request->post['action'] ?? '') === 'delete') {
                    if ($id === '' || ($this->request->post['confirm_delete'] ?? '') !== '1') {
                        throw new InvalidArgumentException('Подтвердите удаление записи. Предпочтительно снять её с публикации.');
                    }
                    $catalog->deleteRecord($type, $id);
                    $this->response->redirect($link(['type' => $type, 'deleted' => 1]));
                    return;
                }
                $record = $this->readForm($type, $fields, $id, $_POST, $record ?? [], $showSections, $choices);
                if ($showSections && ($input = $this->formString($_POST, 'sections_json')) !== '') {
                    $submittedSections = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
                }
                $catalog->saveFromAdmin($type, $record, ($_POST['confirm_slug_change'] ?? '') === '1');
                $this->response->redirect($link(['type' => $type, 'id' => $record['id'], 'saved' => 1]));
                return;
            } catch (InvalidArgumentException | JsonException $exception) {
                if (($_POST['action'] ?? '') !== 'delete') $record = $this->submittedRecord($type, $fields, $_POST, $record ?? []);
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
            if ($type === 'brand' && $field === 'description') {
                $control = 'textarea';
            }
            if (in_array($field, ['brand_slug', 'category_slug', 'parent_slug'], true)) {
                $control = ['' => $field === 'parent_slug' ? 'Корневая категория' : 'Не выбрано'];
                foreach ($choices[$field === 'brand_slug' ? 'brand' : 'category'] as $choice) {
                    if ($field === 'parent_slug' && $choice['id'] === ($record['id'] ?? '')) continue;
                    $control[$choice['slug']] = $choice['title'] . ($choice['status'] !== 'published' ? ' (скрыто)' : '');
                }
            }
            $value = $record[$field] ?? '';
            $value = is_bool($value) ? (string) (int) $value : (is_scalar($value) ? (string) $value : '');
            $formFields[] = compact('field', 'label', 'control', 'value') + [
                'select' => is_array($control),
                'preview' => $control === 'image'
                    ? $this->preview($value, $record[self::LEGACY_IMAGES[$field] ?? ''] ?? null)
                    : '',
            ];
        }

        $rows = $catalog->editRows($type, $page, $search, $status);
        foreach ($rows as &$row) {
            $row['href'] = $link(['type' => $type, 'id' => $row['id'], 'page' => $page, 'search' => $search, 'status' => $status]);
            $row['native_href'] = $this->nativeLink($type, (int) ($row['native_id'] ?? 0), $token);
        }

        $tabs = [];
        foreach (self::TYPES as $key => $title) {
            $tabs[] = ['title' => $title, 'href' => $link(['type' => $key]), 'active' => $key === $type];
        }

        $targets = ['/catalog' => 'Каталог', '/brands' => 'Все бренды', '/articles' => 'Все статьи', '/request' => 'Заявка', '/' => 'Главная'];
        foreach (['page', 'category', 'brand'] as $targetType) {
            foreach ($choices[$targetType] as $choice) {
                $targets[$choice['path']] = self::TYPES[$targetType] . ': ' . $choice['title'] . ($choice['status'] === 'published' ? '' : ' (скрыто)');
            }
        }
        $relationOptions = ['source_id' => ['' => 'Выберите товар'], 'relation_type' => ['analog'=>'Аналог', 'oem_cross'=>'OEM-замена', 'compatible'=>'Совместимый', 'superseded_by'=>'Заменён товаром']];
        foreach ($choices['product'] as $choice) if ($choice['id'] !== ($record['id'] ?? '')) $relationOptions['source_id'][$choice['id']] = $choice['title'];
        $blocks = [];
        foreach (['hero'=>'Обложка', 'categories'=>'Категории', 'products'=>'Товары', 'brands'=>'Бренды', 'order'=>'Порядок заказа', 'articles'=>'Статьи', 'sections'=>'Разделы'] as $key=>$label) {
            $value = ['key'=>$key, 'enabled'=>true, 'sort_order'=>count($blocks)*10, 'label'=>$label];
            foreach ($record['home_blocks'] ?? [] as $block) if (($block['key'] ?? '') === $key) $value = array_replace($value, $block);
            $blocks[] = $value;
        }
        usort($blocks, fn($a,$b)=>$a['sort_order'] <=> $b['sort_order']);
        $publicPath = match ($type) {
            'product','brand','category' => '/' . $type . '/' . ($record['slug'] ?? ''),
            'page' => '/' . ($record['slug'] ?? ''), 'article' => '/articles/' . ($record['slug'] ?? ''), 'home' => '/', default => '',
        };
        $previewHtml = '<!doctype html><meta charset="utf-8"><h1>' . htmlspecialchars((string)($record['title'] ?? $record['name'] ?? $record['h1'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h1>';
        $previewHtml .= CatalogInput::html($record['content'] ?? $record['full_description'] ?? $record['description'] ?? $record['hero_text'] ?? '');
        foreach ($record['sections'] ?? [] as $section) {
            if (!is_array($section)) continue;
            $previewHtml .= '<h2>' . htmlspecialchars((string)($section['title'] ?? ''), ENT_QUOTES, 'UTF-8') . '</h2>' . CatalogInput::html($section['text'] ?? '');
        }
        $sections = $showSections ? $this->sectionRows($record['sections'] ?? [], $submittedSections) : [];
        $this->document->setTitle('Каталог и публикации СМ ТЕХНО');
        $this->response->setOutput($this->load->view('extension/cmteh/module/cmteh_catalog', [
            'header' => $this->load->controller('common/header'),
            'column_left' => $this->load->controller('common/column_left'),
            'footer' => $this->load->controller('common/footer'),
            'tabs' => $tabs, 'rows' => $rows, 'error' => $error, 'can_write' => $canWrite, 'type' => $type,
            'edit_id' => $id, 'record' => $record, 'fields' => $formFields, 'type_label' => self::TYPES[$type],
            'choices' => $choices, 'relation_options' => $relationOptions, 'targets' => $targets, 'home_blocks' => $blocks,
            'native_href' => $this->nativeLink($type, (int)($record['native_id'] ?? 0), $token),
            'public_href' => ($record['status'] ?? '') === 'published' && $publicPath !== '' ? rtrim(HTTP_CATALOG, '/') . $publicPath : '',
            'preview_html' => $previewHtml, 'search' => $search, 'filter_status' => $status,
            'filter_action' => $link(['type' => $type]), 'user_token' => $token,
            'deleted' => ($this->request->get['deleted'] ?? '') === '1',
            'action' => $link(['type' => $type, 'id' => $id, 'page' => $page, 'search' => $search, 'status' => $status]),
            'new_record' => $link(['type' => $type, 'new' => 1, 'page' => $page]),
            'show_form' => $showForm, 'show_sections' => $showSections, 'sections' => $sections,
            'section_revision' => $sectionRevision,
            'section_fields' => self::SECTION_FIELDS, 'section_types' => self::SECTION_TYPES, 'item_fields' => self::ITEM_FIELDS,
            'can_browse_images' => $canBrowseImages, 'ckeditor_lang' => 'ru',
            'store_origin' => rtrim(HTTP_CATALOG, '/'),
            'saved' => ($this->request->get['saved'] ?? '') === '1',
            'previous' => $page > 1 ? $link(['type' => $type, 'page' => $page - 1, 'search' => $search, 'status' => $status]) : '',
            'next' => count($rows) === 25 ? $link(['type' => $type, 'page' => $page + 1, 'search' => $search, 'status' => $status]) : '',
        ]));
    }

    private function readForm(string $type, array $fields, string $id, array $post, array $existing, bool $showSections, array $choices): array
    {
        if (array_key_exists('details', $post)) {
            throw new InvalidArgumentException('Произвольные дополнительные данные не принимаются. Используйте поля формы.');
        }
        // Provenance, native identity and unsupported metadata always come from storage.
        $record = $existing;
        $sourceId = $this->formString($post, 'id');
        $record['id'] = $id !== '' ? $id : ($sourceId ?: $type . '-' . bin2hex(random_bytes(8)));
        $record['slug'] = in_array($type, ['home', 'site'], true) ? $type : $this->formString($post, 'slug');
        foreach ($fields as $field) {
            if (!array_key_exists($field, $post)) {
                continue;
            }
            $value = $this->formString($post, $field);
            $record[$field] = match ($field) {
                'price' => $value !== '' && is_numeric($value) ? (float) $value : null,
                'sort_order' => (int) $value,
                'is_indexable', 'open_in_new_tab' => $value === '1',
                default => self::FIELDS[$field][1] === 'image' ? $this->imagePath($value) : $value,
            };
        }
        $cleared = $post['clear_legacy'] ?? [];
        if (is_array($cleared)) {
            foreach (self::LEGACY_IMAGES as $field => $legacy) {
                if (in_array($field, $fields, true) && ($record[$field] ?? '') === '' && ($cleared[$field] ?? null) === '1') {
                    $record[$legacy] = null;
                }
            }
        }
        if ($type === 'navigation') {
            $target = $this->formString($post, 'internal_target');
            if ($target !== '') {
                $allowed = ['/', '/catalog', '/brands', '/articles', '/request'];
                foreach (['page', 'category', 'brand'] as $targetType) foreach ($choices[$targetType] as $choice) $allowed[] = $choice['path'];
                if (!in_array($target, $allowed, true)) throw new InvalidArgumentException('Выберите существующую страницу, категорию или бренд');
                $record['url'] = $target;
            } else {
                $record['url'] = trim($this->formString($post, 'external_url'));
                if (!preg_match('~^https?://~i', $record['url'])) throw new InvalidArgumentException('Для внешней ссылки укажите полный адрес https://…');
            }
        }
        $record = $this->readCollections($type, $post, $record, $existing);
        if ($record['slug'] === '') {
            $record['slug'] = CatalogInput::slugify($record['title'] ?? $record['name'] ?? $record['label'] ?? '');
        }
        if ($showSections && $this->formString($post, 'sections_json') !== '') {
            $previousSections = $existing['sections'] ?? [];
            $revision = $this->formString($post, 'sections_revision');
            if ($revision !== '' && !hash_equals($this->sectionRevision($previousSections), $revision)) {
                throw new InvalidArgumentException('Разделы изменились в другой вкладке. Обновите страницу редактора.');
            }
            $record['sections'] = $this->readSections($post['sections_json'], $previousSections);
        }
        $record['confirm_zero_price'] = ($post['confirm_zero_price'] ?? null) === '1';
        return $record;
    }

    private function nativeLink(string $type, int $id, string $token): string
    {
        $native = match ($type) { 'product'=>['product','product_id'], 'category'=>['category','category_id'], 'brand'=>['manufacturer','manufacturer_id'], default=>null };
        if (!$native || $id < 1 || !$this->user->hasPermission('access', 'catalog/' . $native[0])) return '';
        return $this->url->link('catalog/' . $native[0] . '.form', ['user_token'=>$token, $native[1]=>$id], true);
    }

    private function submittedRecord(string $type, array $fields, array $post, array $record): array
    {
        foreach (['id','slug', ...$fields] as $field) if (isset($post[$field]) && is_string($post[$field])) $record[$field] = $post[$field];
        foreach (['category_slugs','brand_slugs','specification_items','codes','relations','social_links','home_blocks'] as $field) {
            if (isset($post[$field]) && is_array($post[$field])) $record[$field] = $post[$field];
        }
        foreach (['brands_mode','categories_mode','categories_limit'] as $field) if (isset($post[$field]) && is_string($post[$field])) $record[$field] = $post[$field];
        if ($type === 'navigation') $record['url'] = is_string($post['internal_target'] ?? null) && $post['internal_target'] !== '' ? $post['internal_target'] : (is_string($post['external_url'] ?? null) ? $post['external_url'] : '');
        if (is_string($post['sections_json'] ?? null)) {
            try { $record['sections'] = $this->readSections($post['sections_json'], $record['sections'] ?? []); } catch (InvalidArgumentException | JsonException) {
                // Keep the last valid section projection; the validation error remains visible above.
            }
        }
        return $record;
    }

    private function readCollections(string $type, array $post, array $record, array $existing): array
    {
        if (in_array($type, ['product','home'], true)) {
            foreach ($type === 'home' ? ['brand_slugs','category_slugs'] : ['category_slugs'] as $field) {
                if (!isset($post[$field . '_present'])) continue;
                $values = $post[$field] ?? [];
                if (!is_array($values) || count($values)>300 || array_filter($values, fn($value)=>!is_string($value))) throw new InvalidArgumentException('Некорректный список выбора');
                $record[$field] = array_values(array_unique($values));
            }
        }
        $collections = match($type) {
            'product' => ['specification_items'=>['group_name','name','value','unit'], 'codes'=>['code','code_type','source_name'], 'relations'=>['source_id','relation_type']],
            'site' => ['social_links'=>['label','url']], default => [],
        };
        foreach ($collections as $field=>$keys) {
            if (!isset($post[$field . '_present'])) continue;
            $rows = $post[$field] ?? [];
            if (!is_array($rows) || count($rows)>100) throw new InvalidArgumentException('Слишком много строк');
            $record[$field] = [];
            foreach ($rows as $row) {
                if (!is_array($row)) throw new InvalidArgumentException('Некорректная строка');
                $values = [];
                foreach ($keys as $key) $values[$key] = $this->formString($row, $key);
                $record[$field][] = $values;
            }
        }
        // Private media IDs cannot be injected: only edit captions/remove the stored linked media.
        if ($type === 'product') foreach (['images'=>'alt_text', 'documents'=>'title'] as $field=>$caption) {
            if (!isset($post[$field . '_present'])) continue;
            $rows = $post[$field] ?? [];
            if (!is_array($rows) || count($rows) > 50) throw new InvalidArgumentException('Некорректный список вложений');
            $record[$field] = [];
            foreach ($rows as $key=>$row) {
                if (!isset($existing[$field][$key]) || !is_array($row)) throw new InvalidArgumentException('Медиа изменились. Обновите страницу.');
                $value = $existing[$field][$key];
                $value[$caption] = $this->formString($row, $caption);
                $record[$field][] = $value;
            }
        }
        if ($type === 'home') {
            foreach (['brands_mode','categories_mode'] as $field) if (isset($post[$field])) $record[$field] = $this->formString($post, $field);
            if (isset($post['categories_limit'])) {
                $limit = $this->formString($post, 'categories_limit');
                if (!preg_match('/^\d{1,3}$/D', $limit)) throw new InvalidArgumentException('Лимит категорий: от 0 до 300');
                $record['categories_limit'] = (int)$limit;
            }
            if (isset($post['home_blocks_present'])) {
                if (!is_array($post['home_blocks'] ?? null)) throw new InvalidArgumentException('Некорректные блоки главной');
                $record['home_blocks'] = [];
                foreach (['hero','brands','categories','products','order','articles','sections'] as $key) {
                    $row = $post['home_blocks'][$key] ?? [];
                    $order = $this->formString($row, 'sort_order');
                    if (!preg_match('/^-?\d{1,5}$/D', $order)) throw new InvalidArgumentException('Некорректный порядок блока');
                    $record['home_blocks'][] = ['key'=>$key, 'enabled'=>($row['enabled']??'')==='1', 'sort_order'=>(int)$order];
                }
            }
        }
        return $record;
    }

    private function sectionRevision(mixed $sections): string
    {
        return hash('sha256', json_encode($sections, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function sectionRows(mixed $sections, array $submitted): array
    {
        $rows = [];
        foreach (is_array($sections) ? $sections : [] as $key => $section) {
            if (!is_array($section)) {
                continue;
            }
            $items = [];
            foreach (is_array($section['items'] ?? null) ? $section['items'] : [] as $itemKey => $item) {
                $item = is_array($item) ? $item : ['title' => is_scalar($item) ? (string) $item : ''];
                $item['title'] ??= $item['name'] ?? $item['label'] ?? $item['question'] ?? '';
                $item['text'] ??= $item['description'] ?? $item['answer'] ?? $item['value'] ?? '';
                $item['url'] ??= $item['href'] ?? $item['link'] ?? '';
                $items[] = ['key' => $submitted[$key]['items'][$itemKey]['key'] ?? (string) $itemKey, 'values' => $item];
            }
            $image = $section['image_path'] ?? '';
            $rows[] = [
                'key' => $submitted[$key]['key'] ?? (string) $key, 'values' => $section, 'items' => $items,
                'preview' => $this->preview(is_string($image) ? $image : '', $section['image'] ?? null),
            ];
        }
        return $rows;
    }

    private function readSections(string $input, mixed $existing): array
    {
        if (strlen($input) > 300000) {
            throw new InvalidArgumentException('Разделы страницы слишком велики');
        }
        $rows = json_decode($input, true, 16, JSON_THROW_ON_ERROR);
        return $this->readRows($rows, is_array($existing) ? $existing : [], self::SECTION_FIELDS);
    }

    private function readRows(mixed $rows, array $existing, array $fields): array
    {
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 300) {
            throw new InvalidArgumentException('Некорректный список разделов или пунктов');
        }
        $result = [];
        $used = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['key'] ?? null)) {
                throw new InvalidArgumentException('Некорректный раздел или пункт');
            }
            $key = $row['key'];
            if ($key !== '' && (!array_key_exists($key, $existing) || isset($used[$key]))) {
                throw new InvalidArgumentException('Раздел или пункт изменился. Обновите страницу редактора.');
            }
            $before = $key === '' ? [] : $existing[$key];
            $values = is_array($before) ? $before : [];
            if ($key !== '') {
                $used[$key] = true;
            }
            foreach ($fields as $field => $_label) {
                $value = $this->formString($row, $field);
                $values[$field] = $field === 'image_path' ? $this->imagePath($value) : $value;
            }
            if ($fields === self::SECTION_FIELDS) {
                if ($values['image_path'] === '' && ($row['clear_legacy_image'] ?? false) === true) {
                    unset($values['image']);
                }
                $items = $values['items'] ?? [];
                $values['items'] = $this->readRows($row['items'] ?? [], is_array($items) ? $items : [], self::ITEM_FIELDS);
            } else {
                // The editor projects legacy item aliases onto its named fields.
                foreach (['name', 'label', 'question', 'description', 'answer', 'value', 'href', 'link'] as $alias) {
                    unset($values[$alias]);
                }
            }
            $result[] = $values;
        }
        return $result;
    }

    private function imagePath(string $value): string
    {
        $value = trim($value);
        if ($value === '' || preg_match(
            '~^/extension/cmteh/catalog/view/assets/images/(?:sm-techno-logo-transparent|hero-industrial-v3(?:-mobile)?)\\.webp$~D',
            $value,
        )) {
            return $value;
        }
        $path = CatalogInput::publicImagePath($value);
        if ($path === null) {
            throw new InvalidArgumentException('Выберите изображение из папки catalog в менеджере изображений.');
        }
        return $path;
    }

    private function preview(string $value, mixed $legacy): string
    {
        try {
            $path = $this->imagePath($value);
            if ($path !== '') {
                return str_starts_with($path, '/') ? $path : '/image/' . $path;
            }
        } catch (InvalidArgumentException) {
            // Keep the field value visible so the editor can correct it.
        }
        $uuid = CatalogInput::uuid($legacy);
        return $uuid === null ? '/image/no_image.png' : '/api/assets/' . $uuid;
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
