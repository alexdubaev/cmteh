<?php
declare(strict_types=1);
namespace Cmteh;

/** Sanitized applicability only: never queries settings, permissions or business records. */
final class AdminCapabilities
{
    public static function all(): array
    {
        return json_decode(file_get_contents(__DIR__.'/admin-capabilities.json'),true,512,JSON_THROW_ON_ERROR);
    }

    /** Only applicable advanced native controls; caller filters native's already ACL-scoped tree. */
    public static function nativeRoutes(): array
    {
        return [
            'catalog/attribute'=>'Атрибуты', 'catalog/attribute_group'=>'Группы атрибутов',
            'marketing/marketing'=>'Рекламные кампании',
            'marketplace/installer'=>'Установщик (обслуживание)', 'marketplace/extension'=>'Установленные расширения',
            'marketplace/modification'=>'Модификаторы', 'marketplace/startup'=>'Автозапуск',
            'marketplace/event'=>'События', 'marketplace/cron'=>'Задания cron',
            'setting/store'=>'Параметры платформы (расширенные)', 'user/user'=>'Пользователи',
            'user/user_permission'=>'Группы пользователей', 'localisation/language'=>'Языки платформы',
            'localisation/currency'=>'Валюты платформы', 'localisation/stock_status'=>'Статусы каталога',
            'localisation/length_class'=>'Единицы длины', 'localisation/weight_class'=>'Единицы веса',
            'tool/upgrade'=>'Обновление платформы', 'tool/backup'=>'Копирование / восстановление',
            'tool/upload'=>'Системные загрузки', 'tool/log'=>'Журнал ошибок',
        ];
    }
}
