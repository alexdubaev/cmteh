# Локальная среда OpenCart

Требуются Docker Compose v2 и Python 3.10+. Проверенные образы используют
PHP 8.3.36, MySQL 8.4.13 и Apache. Кандидат ядра закреплён в
[open-cart.lock.json](../packaging/open-cart.lock.json).

Используйте пустую директорию вне checkout и не кладите её содержимое в Git.
В ней создаются одноразовые пароли среды и проверенный ZIP. БД создаётся
в отдельном Compose project. Веб доступен только на 127.0.0.1, порт БД
на хост не публикуется. Если стандартные пулы адресов Docker исчерпаны,
задайте при первом запуске `CMTEH_DOCKER_SUBNET` со свободной приватной
IPv4-подсетью, например /28, после проверки существующих сетей.

```text
python opencart/bin/verify-local --upstream PATH_TO_OFFICIAL_ZIP --runtime-dir PRIVATE_RUNTIME_DIR --port 3154
```

Команда собирает расширение, использует штатный CLI установщика OpenCart,
ставит пакет и модуль через админку, применяет миграции и проверяет HTTP,
PDO/MySQL и PHP lint. С существующим тестовым runtime копирует новые файлы
расширения и снова запускает миграции. Для проверки чистой установки
используйте новый runtime и незанятый порт.

```text
python opencart/dev/runtime.py --runtime-dir PRIVATE_RUNTIME_DIR --port 3154 compose exec -T web php -v
python opencart/dev/runtime.py --runtime-dir PRIVATE_RUNTIME_DIR --port 3154 compose exec -T web php /workspace/tests/FILE.php
python opencart/dev/runtime.py --runtime-dir PRIVATE_RUNTIME_DIR --port 3154 compose stop
```

В контейнере web checkout OpenCart расширения доступен по /workspace,
web root /var/www/html, private root /var/www/cmteh-private. Штатный
/var/www/html/config.php подключает приватную конфигурацию.
Тестовая MySQL использует DB_PREFIX oc_ и синтетические данные.
