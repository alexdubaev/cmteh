# Разведка существующего приложения

База:2031f82d9bb75a85600cb540e94cd8428676f952. Actual app — opencart/, PHP/OpenCart4.1.0.4/MySQL, rootNext reference. Точные source anchors и concurrency hazards в warehouse-scout.md и warehouse-offers-scout.md: исполнители читают свой релевантный отчёт, не повторяют обзор всего проекта.

- Inventory отсутствует. Native product_id integer — внутренний ключ; публичный Catalog productDto.id = cmteh_product.source_id (строка до160), не числовойnativeID и неofferID.
- Native editProduct безусловно пишет quantity; after NativeCatalog::synchronize открывает другуюPDO. Redirect Loader callback поддержан, но меняет route after-events: проекцию нужно сохранить явно. Simple beforePDO substitution небезопасна.
- Catalog::products/product/checkList/selectedProducts и productDto + productFilters отвечают за один опубликованныйкаталог. productcount/pagination нельзя размножать warehousejoin.
- Cart cart.mjs имеет smtechno-request, linekey productId и whitelist normalize; отдельные предложения потеряются без нового контракта. request.mjs сохраняет pendinghash/propertyorder legacy, Lead::save проверяет replay перед saveItems; это сохранить.
- LeadInput legacy игнорирует неизвестные поля, поэтому выбранные warehouselines идут в новый явный POSTpath, который старый сервер отвергнет. Lead snapshots warehouse — отдельная additive таблица по lead_id/position, однаPDOтранзакция, базовую LeadSchema3 не переименовывать/непонижать.
- Raw Quote text/file/originals не меняются, noautoadd/listcheck exactSKU и до1000строк сохраняются. No RFQ automatic stock reserve.
- Действующий XLSX JSreader vendored read-excel-file9.3.10 и boundedZIP extraction можно переиспользовать для извлечения ячеек. RFQ normalization с qtydefault/clamp для складов НЕ подходит. Server preview всегдастрогий, authoritative, авторизованный. Не новаязависимость.
- Runtime/debug instructions opencart/dev/README.md; backendrealDBcheck templates lead/quote/admin-catalog-integration. Final installedcandidate guards dev/release_checks.py и verify-local строгие; новыйsuite должен исполняться противinstalledcandidate сdependencyhashes/Reflection, неworkspace fallback.
- Existing native public checkout закрыт Storefront router. Native quantity/copy/delete/order-effect обходы всёравно защитить scoped дляmanagedтоваров; unrelatednative идоступы сохраняются.

Все scout результаты read-only; приложение/БД/host/mail tests пока NOT RUN. Сохранённые значения secrets не читать и не логировать. КэшZIP cdef537f… и существующие runtime/registry images переиспользовать в новых изолированных данных, не удалятьчужие сети.
