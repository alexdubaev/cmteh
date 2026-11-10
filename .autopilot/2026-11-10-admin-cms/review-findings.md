# Единственный независимый обзор ветки

Диапазон9cf360f..84df4c4, read-only, отдельный свежий агент.

## Исправить
1. CatalogAdmin.php:123,126: allowlist silently drops category.h1 and article.excerpt although visible controls; values must persist after reload and reach storefront.
2. CatalogWrites.php:446: existing product.model overwritten with SKU on unrelated CMS save; preserve independent native model.
3. module/cmteh.php:165 native category delete only after-hooks; core recursively removes children/product links; reject before any mutation if dependencies remain.

## Дополнительно в том же исправительном проходе
footer.twig:28 social navigation aria-label contains invalid UTF8; correct Russian text. Добавлено в тот же таск без дополнительного круга ревью.
