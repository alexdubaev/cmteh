#!/bin/sh
set -eu

root=/var/www/html
private=/var/www/cmteh-private

if [ ! -f "$private/admin.php" ]; then
    # Stop on partial installation. The official CLI overwrites an existing schema.
    if [ -f "$root/config.php" ] || [ -f "$root/admin/config.php" ]; then
        echo "Partial local installation; inspect this isolated Compose project." >&2
        exit 1
    fi

    mkdir -p "$root" "$private"
    chmod 0750 "$private"
    php -r '
        $upstream = "/runtime/opencart-4.1.0.4.zip";
        $lock = json_decode(file_get_contents("/workspace/packaging/open-cart.lock.json"), true, 512, JSON_THROW_ON_ERROR);
        if (hash_file("sha256", $upstream) !== $lock["sha256"]) {
            fwrite(STDERR, "OpenCart archive checksum mismatch.\n");
            exit(1);
        }
        $zip = new ZipArchive();
        if ($zip->open($upstream, ZipArchive::RDONLY) !== true || !$zip->extractTo("/var/www/upstream")) {
            fwrite(STDERR, "Cannot extract the official OpenCart archive.\n");
            exit(1);
        }
        $zip->close();
    '
    cp -a /var/www/upstream/upload/. "$root/"
    cp "$root/config-dist.php" "$root/config.php"
    cp "$root/admin/config-dist.php" "$root/admin/config.php"
    # Bind only the isolated test database. Check that it really is empty.
    php -r '
        $db = new PDO("mysql:host=db;dbname=cmteh_dev", "cmteh_dev", trim(file_get_contents("/runtime/db-password")));
        if ($db->query("SHOW TABLES")->fetch()) {
            fwrite(STDERR, "Refusing to initialize a nonempty database.\n");
            exit(1);
        }
    '
    result=$(php "$root/install/cli_install.php" install \
        --username cmteh-admin --email opencart@example.test \
        --password "$(cat /runtime/admin-password)" --http_server "$CMTEH_LOCAL_ORIGIN/" \
        --db_driver mysqli --db_hostname db --db_username cmteh_dev \
        --db_password "$(cat /runtime/db-password)" --db_database cmteh_dev --db_port 3306 --db_prefix oc_)
    case "$result" in
        SUCCESS!*) ;;
        *) echo "Official CLI installer failed. Check runtime requirements." >&2; exit 1 ;;
    esac

    php /workspace/packaging/seal.php "$root" "$private"
    chown -R www-data:www-data "$root/extension" "$root/image" "$private"
fi

exec apache2-foreground
