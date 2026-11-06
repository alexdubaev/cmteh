<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
$options = getopt('', ['output:', 'media-dir:', 'count:'], $rest);
$count = filter_var($options['count'] ?? 100000, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
if ($rest !== $argc || $count === false || !is_string($options['output'] ?? null) || !is_string($options['media-dir'] ?? null)) {
    fwrite(STDERR, "Usage: php generate-catalog.php --output=/private/scale.ndjson --media-dir=/private/media [--count=100000]\n");
    exit(2);
}

$brands = ['Промдеталь', 'Северные механизмы', 'Волга Маш', 'Линия Движения', 'Тех Ресурс',
    'Спектр Гидро', 'Горный Узел', 'Вектор Привод', 'Станкомир', 'Механика С', 'Магистраль С'];
$categories = ['Фильтры', 'Подшипники', 'Клапаны', 'Редукторы', 'Насосы', 'Уплотнения', 'Крепёж', 'Кабели'];
$availability = ['in_stock', 'on_request', 'out_of_stock'];
$partTypes = ['original', 'oem', 'analog'];
$mediaDirectory = realpath($options['media-dir']);
$stream = @fopen($options['output'], 'xb');
if ($mediaDirectory === false || !is_dir($mediaDirectory) || $stream === false) {
    if (is_resource($stream)) {
        fclose($stream);
    }
    fwrite(STDERR, "Укажите существующий каталог медиа и новый файл вывода.\n");
    exit(2);
}

function emitRecord($stream, string $type, array $data): void
{
    $line = json_encode(compact('type', 'data'), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    if (fwrite($stream, $line) !== strlen($line)) {
        throw new RuntimeException('Не удалось записать строку набора');
    }
}

function sampleImage(string $path, int $index): void
{
    $file = @fopen($path, 'xb');
    if ($file === false) {
        throw new RuntimeException('Требуется свободное имя для тестового изображения');
    }
    try {
        $image = imagecreatetruecolor(320, 240);
        $background = imagecolorallocate($image, 214 + $index % 36, 210, 188);
        $foreground = imagecolorallocate($image, 28, 34, 44);
        imagefill($image, 0, 0, $background);
        imagefilledrectangle($image, 90, 45, 224, 160, $foreground);
        imagefilledellipse($image, 155, 101, 62, 62, $background);
        imagestring($image, 4, 72, 187, "SYNTHETIC PART $index", $foreground);
        if (!imagejpeg($image, $file, 78)) {
            throw new RuntimeException('Не удалось записать тестовое изображение');
        }
    } finally {
        fclose($file);
    }
}

try {
    foreach ($brands as $index => $name) {
        $id = 'scale-brand-' . ($index + 1);
        emitRecord($stream, 'brand', ['id' => $id, 'slug' => $id, 'name' => $name, 'status' => 'published', 'sort_order' => $index]);
    }
    foreach ($categories as $index => $title) {
        $id = 'scale-category-' . ($index + 1);
        emitRecord($stream, 'category', ['id' => $id, 'slug' => $id, 'title' => $title, 'status' => 'published', 'sort_order' => $index]);
    }
    $images = 0;
    for ($index = 1; $index <= $count; $index++) {
        $brand = ($index * $index + intdiv($index, 19)) % count($brands);
        $category = ($index * 3 + intdiv($index, 71)) % count($categories);
        $id = 'scale-' . $index;
        $priceStatus = match ($index % 10) { 0, 1, 2 => 'on_request', 3 => 'hidden', default => 'fixed' };
        $product = [
            'id' => $id, 'slug' => $id, 'sku' => str_pad((string) $index, 10, '0', STR_PAD_LEFT), 'mpn' => 'TEST-' . $index,
            'title' => $categories[$category] . ' · синтетический узел ' . $index, 'brand' => $brands[$brand],
            'brand_slug' => 'scale-brand-' . ($brand + 1), 'category_slug' => 'scale-category-' . ($category + 1),
            'status' => $index % 97 === 0 ? 'draft' : 'published', 'published_at' => '2020-01-01T00:00:00Z',
            'price_status' => $priceStatus, 'price' => $priceStatus === 'fixed' ? 100 + $index % 119000 : null,
            'currency' => $index % 11 === 0 ? 'EUR' : 'RUB', 'availability_status' => $availability[$index % 3],
            'part_type' => $partTypes[intdiv($index, 3) % 3],
            'short_description' => 'Искусственная карточка для проверки поиска и индекса.',
            'specification_items' => [
                ['name' => 'Ширина', 'value' => (string) (7 + $index % 327), 'unit' => 'мм'],
                ['name' => 'Масса', 'value' => (string) (1 + $index % 125), 'unit' => 'кг'],
            ],
        ];
        if ($index % 20 === 0) {
            $digest = hash('sha256', 'cmteh-catalog-scale:' . $id);
            $uuid = substr($digest, 0, 8) . '-' . substr($digest, 8, 4) . '-4' . substr($digest, 13, 3) . '-8'
                . substr($digest, 17, 3) . '-' . substr($digest, 20, 12);
            $path = $id . '.jpg';
            sampleImage($mediaDirectory . '/' . $path, $index);
            $product['main_image'] = $uuid;
            $product['images'] = [['image' => $uuid, 'alt_text' => 'Синтетическая деталь ' . $index]];
            $images++;
        }
        emitRecord($stream, 'product', $product);
        if (isset($product['main_image'])) {
            emitRecord($stream, 'media', [
                'uuid' => $product['main_image'], 'path' => $path, 'mime' => 'image/jpeg', 'status' => 'published',
                'owners' => [['type' => 'product', 'id' => $id]],
            ]);
        }
    }
    echo "$count товаров, $images изображений", PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Не удалось завершить генерацию. ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    fclose($stream);
}
