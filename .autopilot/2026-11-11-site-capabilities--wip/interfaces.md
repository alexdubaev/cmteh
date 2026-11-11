# Границы, решённые в спецификации

## Правила проекта из carried (дословно)

## Договорились — не пересматривать

- Actual app `opencart/`, OpenCart4.1.0.4; rootNext reference. Толькоtest.cmteh.ru; production/StoreLand/DNS/engine исключены.
- Один каталог/рабочийстол; native advanced формы по месту/прежнемуACL. IDs/source_id/slug/mappings/ownerdata сохраняются; чтение не публикуетunknown. Upgrade не выдаёт права.
- Adminusers/groups access/modify ужеесть; публичные покупатели отсутствуют. Обращение/расценка ≠ заказ/оплата, mailstate ≠ стадияпродажи; контакты по исходнойзаявке безслияниялюдей/подписки.
- Кампании: native getTotalReports (повторы/404), неstaleclicks/люди/выручка. page_url заявленный, неатрибуция.3новыхсобытия header/dashboard/marketing;4точныхcampaignviews. Original64events byteexact.
- seed-cleanup.json -text хранитточныеподписанныебайты/SHAcb85…; неформатировать/неослаблятьpin. Unmapped≠demo; толькоofficialpositiveproof+refs/guard. Allphysicalfiles/системныеdefinitions/правила/новыеобращениясохранять. НеrestoreстаруюБДповерхновыхзаявок.
- UXсохранён: компактныйпервыйэкран→категории→товары; отдельнаяформа«Запрос»,«Позвонить»+7 911 921-22-14; content-firstparts/results/backtotop; драфты/файлы/корзина/URLs стабильны. CSS/modulesgraph20261110-8; actualiPhone отдельнонеподтверждён.
- List/check/quote неавтодобавляютсыройсписоквкорзину; толькоявновыбранныепредложения. ExactSKU/ведущиенули/порядок/дубли;до1000строк/50page; статускаталоганефизическийостаток.
- Quoteinfo@cmteh.ru от«СМТЕХНО»:таблицы/PDF/фото безOCR; автопроверкатолькоXLSX/CSV/TXT. История/оригиналывЗаявках. OwnerподтвердилобаInbox+CSV; непереоткрыватьпочтовуюприёмку.
- Толькоquotequeuecron; старыйLeadSMTPневключать. Privatebase/sealed, Leadschema3/product_id160. Config/ACL/cron/privateoriginals сохранять, rollbackcode-only+ownTwig, безloweringmarkers/oldmigrator/globalDBrestore.
- КартаофисаопциональновCMSsite:толькоtrustedYandexiframeURL;richHTMLсанитайзернеослаблять. Собственныйкод/адресвладельцемпока непредоставлен.
- Rawbyteguardстрогий: Gitcanonical≠rawZIP; толькоunchangedTEXTacceptedoverlays+restore. Новыеintegration suitesinstalledpaths/hashonly, workspacefallback/CLIdowngradeзапрещены.
- LocalPHP8.3/MySQL8.4 ≠ hostPHP8.2/MySQL5.7. Actual5.7business13/cleanup46PASS; actualhostrehearsalотдельныйgate. Normalnativeвходможетизменитьuser1.ip/login/sessionmetadata, неостальныеuser/hash/groupACL.


## Явные новые решения владельца

Новый объём только склады; R01–R42 deferred. Публичные предложения одного товара по складам с ценой и сроком, Excel/CSV import, manualdeliverywarehouse+offer, manualreserves. G2сузил дополнительные сценарии: A01 — фиксация завершённого перемещения, A02 — полная отгрузка резерва; нет transitworkflow/partialshipment. Это прямо разрешает новые inventorycontracts и additiveRFQsnapshots; RFQ остаётсянеобязывающим/noautoreserve, аккаунты/платежи deferred. Legacy URL/productsourceID/SKU semantics/basecartkey/privateQuote/ACL сохраняются. Прежнее «каталогныйстатус нефизическийостаток» остаётся дляlegacy; после явного managedcutover status следует warehouseavailable.

## Inventory domain (owner ticket01)

Новые system InventorySchema.php, Inventory.php, InventoryInput.php; namespace/DBprefix conventions существующие. InventorySchema::migrate(PDO,string): void idempotent/additive, отдельная inventoryversion, без lifecycleмиграции в reads и без groupgrants. Существующие LeadSchema3/Quote/catalog markers не понижаются.

Inventory::__construct(PDO $pdo,string $prefix,int $storeId=0,int $languageId=0). Дополнительный publiccontext опционален длясовместимости; storefrontcallers передают текущийstore/language, а непрячут и неутекаюттовары другогоstore. Не hardcode0вконтекстномcaller. Rawpublicationquery guard согласован сCatalog без рекурсии DTO→Inventory→CatalogDTO. Методы:
- command(string $kind,array $input,array $actor,string $requestKey): array — единственная авторизованная доменная запись, expectedversions вinput; return stable result/replayed. kinds warehouse.save/archive, product.activate, balance.count/receive/issue, transfer.complete, reservation.create/release/ship (release/ship только весь активный резерв), offer.update. Actor {id:int,label:string}, серверныйnativeuser, неbody.
- warehouses(array $filter=[]): array; balances(array $filter=[]): array; history(array $filter=[]): array; reservations(array $filter=[]): array. Bounded page/pagination и count; внутренниеadminDTO неpublic.
- productState(int $nativeProductId): ?array — managed/version/on_hand/reserved/available, no sideeffects.
- publicOffers(array $sourceIds): array — map source_id=>{managed:bool,offers:[],partial:bool}; batched query, только текущая публикация, allowlist.
- resolveSelectedOffers(array $items): array — вызывается на том же PDO внутри first-insert Leadtransaction, locked/consistentserver validation; exactid/version/product/source, authoritative snapshots; stale409 typederror. Не делает reserve. Повтор Lead разрешается до вызова.

Public offer DTO: {offer_id:string,offer_version:string,product_id:string,warehouse:{code:string,name:string,city:string},availability_status:'in_stock'|'on_request'|'out_of_stock',price:string|null,currency:'RUB',delivery_term:string}. No raw on_hand/reserved/internalwarehouseid/address/note/actor. offer_id stable nonsecret numericstring/UUID; offer_version boundedopaqueSHA256 combining balanceversion+warehousepublicversion and relevant publicterms. Internal nativeproduct ID never replaces source_id. Allstockqty integers, total checked within nativeintegerbounds, money canonical positive decimal(15,2) ornull; nofloatwrites. Public labels escaped once inTwig/DOM.

Schema minimal tables: cmteh_warehouse; cmteh_inventory_product; cmteh_inventory_balance (stableofferid/uniqueproductwarehouse, price,termoverride,published,version); cmteh_inventory_command (uniquerequestkey+fingerprint+result); cmteh_inventory_movement/entry appendonly; cmteh_inventory_reservation. Exactcolumns worker records here afterproof, notsilentrenaming. No duplicated catalogproducts. Exact mapping: cmteh_product.product_id INT PRIMARY KEY = native product.product_id; cmteh_product.source_id VARCHAR(160) UNIQUE = public DTO.id/cart.productId/Leadwire.product_id. Column native_product_id не существует. Shared lockorder productnumericascending→control→warehouse/balanceIDsascending→reservation. Allwriters including import/nativeguards followit. on_hand>=reserved>=0, available=on_hand-reserved; no unlocatedfakewarehouse. Перемещение — парное ledgerдвижение только после явного подтверждения фактического прибытия, без transitworkflow. Ledger matchesmaterialized balances; reversals append, nevererasehistory.

Product activation takes explicit allocations, expectedlegacyqty/revision and discrepancyreason. Pureallocationsum must equalconfirmedlegacyqty; physicalcountdifference requires explicitreason+audit. Existingnative qty/availability unchanged untilconfirmed. Firstimportpreview includescutoverdifference and explicitconfirm flag; errors can'tautoactivate. Inconsistent shared master/optionsstock rejects withreason, neverdoublecounts. Warehousearchive requiresnoonhand/reserve, offerunpublish separate. Native mirror defined aggregateavailable; projection metadata remains storedlegacy, effectivecatalog queriesusemanagedsource.

## Import (owner ticket03)

InventoryImport.php samePDO/prefix/Inventory. template headers article,warehouse_code,quantity,price,delivery_term. CSV UTF8/CP1251 supported; XLSX uses existingvendorreader boundedrawcellextraction, no formulaexecution; SKU/code texttypedleadingzeros. Strictserverscalar/length/decimal/integer/duplicatepair checks, ambiguous SKUerror, no defaultqty/no silentclamp/no unknownentitycreation. Partialfile updates onlypresentpairs. Emptyprice=onrequest; emptyterm=fallback documentedpreview.

InventoryImport::preview(array $rows,array $actor): array returns {preview_id,digest,expires_at,summary,errors,rows,can_apply}; persists normalizedboundedinput+expectedversions in privateDB staging ownedactor. InventoryImport::apply(string $previewId,string $digest,array $actor,string $requestKey): array validatesowner,expiry,versions then atomic Inventorycommands underonePDOtransaction, wholepreview no partialbatch, samekey exactreplay. Changedbalancewithnewreserve/import conflicts beforeanywrite; countbelowreserved rejectswhole. Preview expiry30min is technicalstalenessbound, immutableappliedreceipt remains incommandhistory. Productcutoverdifference flagged/reasonexplicit beforeapply. Maxfile5MiB/max10000rows, existing1000rowlist separate; actual1000import exercised. Xlsx extraction trust describedhonestly as confirmedrecognizedrows, notverifiedrawfileprovenance.

## Native integration/admin (owner ticket02)

Inventory model guards run on actual registry DB connection for originalcorewrite+lock transaction. Avoid separatePDO lock while corewrites. Wrapperredirect preserves original postcommit NativeCatalogsynchronize explicit; no nestedPDO beforecommit. Scope managedproducts only. Protect fullformstock/copy/delete, directquantity setters, CMTEHdeletebatch/import and managednativeorder historybeforemail/stockeffects; unrelatedlegacy remains.

New controller cmteh_inventory.php reuses EXACT existing cmteh_catalog permissionkeys/session/user_token; accessforread, modifyforcommands/import. Menu onlywithread. Noautomatic grants. UI warehouseCRUD+defaultterm, balances/offerprice/term/publication, cutoverdiff, movements/reserves/history paginated; stocknativeformreadonlysummary/link but description editable. AllmutationsPOST+requestkey+expectedversions, failurespreserveinput. Safearchive/recordconfirmation, no massdestructive buttons. Existingmodule migration invokesInventorySchema onlyunderexistingmigratorpermission. Neweventregistration precise/additive, originaleventsnotrewritten justtoaddhooks.

## Storefront/cart/Lead (owner ticket04)

Catalogproducts/product/checkList/search additiveoffers projection+effectiveavailability/price-sort consistent at query/count/DTO, sourceIDs/URLs/exactSKU unchanged. Oneproductresult canhavemultipleoffers. Productpage public warehousecards/table, clearselect peroffer; multi-offerproductcardlink'Выбрать склад'. No implicitcheapest selection, noautolistadd. Eligiblezeroavailability canbeshownstatusdisabledforinstockadd plus generalquote CTA withoutfakepromise.

Cart keeps smtechno-request legacy untouched, adds versioned smtechno-warehouse-request-v1 storage for selectedwarehouseentries only. New cartcombinesboth; warehousekey=(productId,offerId), nevermerges intolegacy/unassigned. Auxdata fields {productId,offerId,offerVersion,article,title,brand,quantity,warehouseName,warehouseCode,deliveryTerm,price,currency}; clientlabels aredraftonly, servernevertruststhem. OldJScan'tstrip selectedauxrows. Storagefailure handledwithoutack/clear/dataloss. Pendingnewwarehousebody uses separate pendingnamespace; oldpendinglegacy hash/key/propertyorder retained. Acksubtract matches submittedlineidentity+revision and preservesconcurrent additions. Explicitlegacyreselection mayproducebothrowsif interrupted; showunassignedlegacyclearly and requireintent, no silentmigration/cheapdefault.

New POST /api/lead/warehouse accepts normalLeadcontactfields + request_items where selectedlinesadd offer_id/offer_version pair beside existingproduct_id; canincludepreservedlegacyfreeformlines. Rejectmalformedselection; newserver legacy/api/lead refusesfirstacceptancecanonicalmanagedlinewithoutoffer, committedoldreplaymuststillwork. Oldservernewpathfails4xx (404/405 actuallegacyrouter), neverignoresselection. New GET /api/warehouse-offers boundedIDs forcartfreshness, no arbitrarynative routes. ExistingrawQuote routebody untouched.

LeadInput adds explicit parseWarehouse path withoutchanging legacyparse normalizedshape. Lead firstacceptance samePDO saves baselead/items/outbox and nullablepositionkeyed cmteh_lead_inventory snapshotstable; InventorySchemaownsadditivetable ifLeadmarker3unchanged. Snapshot {offer_id,offer_version,warehouse_code/name/city,unit_price:string|null,currency,delivery_term,availability_status} immutable onacceptedreplay. Resolveproduct↔offer/exactSKU/publication and revision beforeinsert andundercompatiblelocks. Canonicalfingerprint usesonlynormalizedrequestedselection+legacydata, notliveprice. Typedstale409 tellsreview; no orphanlead/job/reserve. Admin Leadfind/display joinsnapshotfororiginalrows, privateACLunchanged. New JSversion allimports consistent, previouscachedJS tests included.

## Проверки и восстановление (owner ticket05)

Core realMySQL integration isolatedprefix; nativeinstalledloader/ACL tests; importpreview/apply rollback/version/races and1000rows; publicofferproductcounts/prices/lists; Lead immutablewarehouse/noreserve/idempotency/lostack; shortcriticalE2E andtargeted390pxmanualvisual. NOT sourceassertions/mirrorunits/giantmatrices.

Finalstrict verifier remainsstrict; new suite runsinstalledcandidate dependencieshashguard/Reflection. Exactimmutableartifactbytes correspondtosource. RawseedJSONpin unchanged. ActualtargetPHP8.2/MySQL5.7 requiredcompatibleproof; current8.4onlynotclaimhostversion. Upgradebackupfresh+privatefiles+cron+actualrecoverycopy. Oldpackage rollbackallowedonlyprovennoactivatedmanagedproducts/noinventorycommands/nochangedselectedsubmissions; afteruse oldcoderejectsnewselectedpath butcan'tservicefullinventory, so incompatibleoldbinaryrollbackblocked. Keepcurrentcompatibleartifact/privateDB snapshot recovery and verifyrestore/reinstalloncopy includingpostbackupmovements/RFQ. Optional inventoryreadonly recoveryflag permitspublicoffer/RFQread+snapshots anddeniesadmininventorymutations whilekeepingnativeguards. NeverrestoreoldDBovernewdata, neverlowerSchema3 orgrants. Finalhostdeployment onlytest.cmteh.ru, noexternalmail/actualstockfixtureactivation.

## Изменения исполнителей

Каждый completedticket добавляет точные созданные table/columns/methods/checkcommands и контрактные уточнения сюда; незаметное изменение соглашений запрещено. Новое бизнесответвление возвращается lead, невыдумывать ownerfacts.

## Рабочие команды и владение

Baselineorigin2031f82; taskbranchcodex/site-capabilities-20261111. Runtime taskdev D:/codex/temp/cmteh-warehouse-dev-20261111 port3190 (rootprepare), upstreamZIP D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip, node tooling D:/codex/cmteh. PHP8.3local/MySQL8.4 vsactual8.2/5.7 notconfused. Runtime commands through python opencart/dev/runtime.py --runtime-dir ... --port3190 compose exec -T web php /workspace/tests/<suite>.php withestablishedisolatedtestconfig; neverprintfcredentials. Finalaffectedcheck command documentedstrict python opencart/bin/verify-local --upstream <pinnedZIP> --runtime-dir <newempty> --reference-dir <known18reference> --port <free>, not newlyinventedwrapper. One suite commandexisting runtimehelper; dependenciescached no npmupdate/newlibrary. Root willgivereadyrealDBconfig path privatelywithoutvalues.

Parallelwrites ownership: ticket01 Inventorycore standalone; 02 nativeguards/moduleinstaller/CatalogAdmin/Writes/NativeCatalog;03 CMTEHcatalogcontroller/privateadmin/menu/InventoryImport;04 publicCatalog/Storefront/Lead/publicassets/templates;05 installedverification/docs onlyafterall. Askleadbeforeeditinganotherownerfile. Sharedinterfaces updates appendonlynamedsection. Core methoderrors/payloadkeys freeze at01. Everymutationandtest hasowned data/noproductionmail; no mixedgitcommits. Ownerpolicy overrides separatefoundationpointreview: onefresh wholechange independentriskreview afterintegration +boundedfix/narrowclosure; no repeatedcleanreview.

### Runtimeready и error seam — 2026-11-11

Dev runtime http://127.0.0.1:3190, projectcmteh-oc-5b685b8b7b78, провереннаянесовпадающаяsubnet10.231.89.0/28. /workspace mountedopencart; installedsite /var/www/html. Initial realDBRED suite inventory-integration наown ivt_prefix сообщилmissinginventoryreadiness доproductcode. Runtimeversion initialPHP8.3/MySQL8.4, неactualhostproof. Ticket01 разрешён system/InventoryException.php какtyped InventoryException(reason,status), owned01. Errors/status/replaycontract фиксируются worker ticket01-contract.md, root принимаетединожды доwave2.

### Числовые/браузерные границы

Money decimal(15,2) unitprice можетпоместитьсявPHPminorinteger, ноумножениенаqty/JSNumbertotal можетпереполниться. Клиентскиетоталы использовать exactminorBigInt либоявныйsafeintegerguard безмолчаливойпотерикопеек; никогда floatpersist. Агрегатqty ограничитьnativeINTmax2147483647; inventoryJSON integers проверятьнаinteger, неcoerceexp/fraction. Public DTOavailabilitystatusonly — raw on_hand/reserved/внутренние адреса ненужныклиенту и неэкспортируются. Склады/цена/срок публичны по прямомутребованию.

Cachegraph04: существующийcurrent20261110-8, новыйединыйверсионныйсуффикс всехизменённыхмодулей/ихимпортов, no unversionededges. Новыйselectedstorage/POSToperation старыеbinary/JS неинтерпретируюткакgenericSKU; no fallbackon404.

### Дополнительный MySQL5.7 runtime

D:/codex/temp/cmteh-warehouse-mysql57-20261111 port3191/projectcmteh-oc-6c6f360e781b, privateACL/user+SYSTEM, isolateddatabase/websitevolumes, subnet10.231.90.0/28. Preseednetwork.json overrides dbimage mysql:5.7.44 withnewdatavolume; sourcecompose/verifierunchanged. ActualreadonlySELECTVERSIONreturned MySQL5.7.44/PHP8.3.36. NotactualPHP8.2hostproof/notstrictgate. Alltasktests same/workspace mount, configfrom /var/www/html/config.php withoutvaluedumps. Initialprepare failedonDockerIPAM.Config null; noexistingcontainers/volumesverifiedbeforecorrectedpreseed; repairednullhandling+failstop thenonlynewownedruntimecreated. Не переключатьexisting8.4datadirна5.7.

## Завершённый таск01 — frozen exactcontract, приоритет над предварительными именами

# Ticket 01 — exact integration contract

Core implementation complete; check evidence below. No migration on constructor/read. `InventorySchema::migrate(PDO $pdo,string $prefix): void` explicit additive inventory marker **1**. Native/CMTEH product identity unchanged.

## Public signatures

`Inventory::__construct(PDO $pdo,string $prefix,int $storeId=0,int $languageId=0)` (optional context approved by lead). `command(string $kind,array $input,array $actor,string $requestKey): array`; `warehouses/balances/history/reservations(array $filter=[]): array`; `productState(int $nativeProductId): ?array`; `publicOffers(array $sourceIds): array`; `resolveSelectedOffers(array $items): array`. `Inventory::legacyRevision(array $native): string` exposes cutover revision using product_id, quantity, master_id, date_modified. Use productState's revision for forms.

`InventoryException extends RuntimeException`: public readonly `reason:string`, `status:int` (422 default, 409 conflict, 404 missing), safe Russian message. Defined in `InventoryException.php`, required by InventoryInput/Inventory. Caller passes server-derived actor `{id:int>0,label:string<=160}`; no ACL engine or native sessions in domain.

## Commands

All keys below snake_case. Request key ASCII `[A-Za-z0-9_.:-]{8,128}`, global uniqueness within prefix. Fingerprint SHA256 canonical recursively key-sorted `[kind,input,actor.id]` (JSON scalar types preserved); exact replay returns stored result before freshness. No actor label/live price in fingerprint. Stable result `{command_id:string,kind:string,replayed:bool,...}`. Joins same PDO transaction using owned savepoint; never commits/rolls back caller transaction. Product rows locked numeric ascending → inventory_product → command → warehouses numeric ascending → balances → reservation. Import batch must pre-lock **all** native product/control rows sorted before invoking commands; same PDO sees own updated versions.

- `warehouse.save`: `{warehouse_id?:int=0,expected_warehouse_version:int,code:string<=64 /^[A-Za-z0-9_-]+$/,name:string<=160,city?:string<=160,delivery_term?:string<=255,address?:string<=1000,note?:string<=5000}`. Create expected version 0, active=1. Existing fields are full form values; missing optional fields clear. Archived warehouse cannot be reactivated. Return `{warehouse:internalRow}`. `warehouse.archive`: `{warehouse_id,expected_warehouse_version}`; zero physical/reserved required; preserves offers/history.
- `product.activate`: `{product_id:int,expected_product_version:0,expected_legacy_quantity:int,expected_legacy_revision:sha256,confirmed:true,reason?:string<=1000,allocations:[{warehouse_id:int,quantity:int>=0,expected_warehouse_version:int}]}`. 1–1000 unique explicit allocations. Sum must equal confirmed legacy quantity unless nonempty audit reason; supported independent native product only; master/child/stock-subtracting options refuse. Creates unpublished offers with null prices/blank override, no invented terms.
- Other product commands share `{product_id,warehouse_id,expected_product_version,expected_balance_version,expected_warehouse_version}`. Absent pair version **0** creates unpublished zero balance; failed command removes that row atomically. Every successful product command increments product version once; affected balance version increments once; native.quantity becomes aggregate available.
- `balance.count/receive/issue`: common + `{quantity:int,reason:string<=1000 nonempty}`; count absolute >=0, receive/issue delta >0. Count cannot fall below reserved.
- `transfer.complete`: common + `{quantity:int>0,reason,arrival_confirmed:true,to_warehouse_id,expected_to_balance_version,expected_to_warehouse_version}`. Both legs one movement/transaction, source free stock only. No transit.
- `reservation.create`: common + `{quantity:int>0,reason,reference?:string<=255}`. Return includes `reservation` row. `reservation.release/ship`: common + `{reservation_id,expected_reservation_version,reason}`; `quantity` key **forbidden**, closes whole active reserve. States `active/released/shipped`; ship decreases on_hand+reserved together.
- `offer.update`: common + `{price:string|null,published:bool,delivery_term?:string<=255}`. Price canonical positive decimal(15,2) string or null; blank -> null; float/zero/negative reject. Blank term means warehouse fallback. Does not append physical movement. Return product command resource format.

Product command result fields `{product:ProductState,movement_id:string|null,balances:InternalBalance[],reservation?:InternalReservation}`. ProductState `{product_id:int,managed:bool,version:int,on_hand:int,reserved:int,available:int,legacy_quantity:int,legacy_revision:string}`; unmanaged existing product returns managed=false/version0 plus current legacy qty/revision, zero located sums. Missing native product returns null. Native mirror excludes no balances: all stock, including unpublished offers, contributes available.

## Read/admin and public DTOs

Read lists return `{rows:[],total:int,page:int,limit:int}`; default page1/limit50, limit<=100/page<=100000. warehouses filters `active,warehouse_id`; balances `product_id,warehouse_id,search` (SKU/source/code substring), rows b.* plus `available,warehouse_code,warehouse_name,warehouse_version,source_id,sku`; history `product_id,warehouse_id`, descending movement rows with `entries:[]`; reservations `product_id,warehouse_id,reservation_id,state`. No write/migration in reads.

`publicOffers` accepts max1000 exact source strings<=160, returns keyed map `{managed:bool,offers:PublicOffer[],partial:bool}`; max100 offers/product and10000 total with partial=true on truncation. DTO exactly `{offer_id:string,offer_version:sha256,product_id:source_id,warehouse:{code,name,city},availability_status:'in_stock'|'out_of_stock',price:canonicalDecimal|null,currency:'RUB',delivery_term:string}`. Positive available means in_stock even null price. No physical count/private fields. Revision hashes DTO + balance.version + warehouse.public_version; private address/note-only change doesn't invalidate public terms. Publication: p.status/date_available + cmteh_product.published_at + product_to_store(currentstore) + available product_description (specific language when >0, any when0) + native manufacturer/public cmteh_brand/current manufacturer_to_store or manufacturer_id0. Matches Catalog's current product visibility (categories are optional left joins there). Hidden/missing source gets empty default map value.

`resolveSelectedOffers` requires existing PDO transaction; input position-keyed normalized Lead items, selected lines `{product_id:source_id,article:exact SKU,offer_id:string,offer_version:sha256,...}`; no pair means skipped legacy row, malformed partial pair rejected. Locks all products/control sorted then warehouses/balances, performs current-read publication+revision+SKU identity checks. Returns position-keyed snapshots exactly `{offer_id,offer_version,warehouse_code,warehouse_name,warehouse_city,unit_price:string|null,currency,delivery_term,availability_status}`. Quantity exceeding available still a nonbinding RFQ, no reserve. Exact committed Lead replay must resolve before this method. Missing/stale/publication mismatch `InventoryException(reason='stale_offer',status=409)`.

Reasons: `invalid_input,stale_version,request_conflict,not_found,duplicate_warehouse,warehouse_not_empty,already_managed,unsupported_stock,confirmation_required,quantity_overflow,warehouse_archived,unmanaged_product,insufficient_stock,reservation_closed,stale_offer`. SQL/injected database failures propagate and roll back; no fake success.

## Exact additive schema columns (all cmteh_ prefixed)

- `inventory_schema`: version PK SMALLINT, applied_at DATETIME.
- `warehouse`: warehouse_id INT AI PK; code VARCHAR64 binary UNIQUE; name/city VARCHAR160; delivery_term VARCHAR255; address VARCHAR1000; note TEXT; active TINYINT; version/public_version INT; created_at/updated_at DATETIME UTC.
- `inventory_product`: product_id INT PK; version INT; legacy_quantity INT; legacy_revision CHAR64 ascii; activated_at DATETIME.
- `inventory_balance`: offer_id BIGINT UNSIGNED AI PK (stable public decimal string); product_id/warehouse_id INT UNIQUE pair; on_hand/reserved INT; price DECIMAL15,2 nullable; delivery_term VARCHAR255; published TINYINT; version INT.
- `inventory_command`: command_id BIGINT UNSIGNED AI PK; request_key VARCHAR128 ascii binary UNIQUE; fingerprint CHAR64 ascii; kind VARCHAR40; actor_id INT; actor_label VARCHAR160; result JSON nullable (only inside uncommitted command); created_at DATETIME.
- `inventory_movement`: movement_id BIGINT UNSIGNED AI PK; command_id BIGINT UNSIGNED UNIQUE; kind VARCHAR40; reason VARCHAR1000; actor_id INT; actor_label VARCHAR160; created_at DATETIME.
- `inventory_entry`: movement_id BIGINT UNSIGNED + position INT composite PK; product_id/warehouse_id INT; delta_on_hand/delta_reserved/on_hand/reserved/balance_version INT; source_id VARCHAR160; article VARCHAR128; warehouse_code VARCHAR64. Signed delta only. Ledger append only through service.
- `inventory_reservation`: reservation_id BIGINT UNSIGNED AI PK; product_id/warehouse_id/quantity INT; state VARCHAR16; version INT; reference VARCHAR255; actor_id INT; actor_label VARCHAR160; created_at/updated_at DATETIME.
- `lead_inventory`: lead_id BIGINT UNSIGNED + position INT UNSIGNED composite PK; offer_id VARCHAR40 ascii; offer_version CHAR64 ascii; warehouse_code VARCHAR64; warehouse_name/warehouse_city VARCHAR160; unit_price DECIMAL15,2 nullable; currency CHAR3 ascii; delivery_term VARCHAR255; availability_status VARCHAR16 ascii. No foreign keys/cascade into legacy tables. Lead marker3 unchanged.

`lead_inventory.lead_id` matches actual `Lead.php::migrate` native internal header/items BIGINT UNSIGNED (lines46/59); public Lead UUID remains `public_id`, never used in this FK position.

## Check evidence / final boundary

`python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/inventory-integration.php`: **45 PASS** on MySQL8.4/PHP8.3. Same command with runtime-dir `D:/codex/temp/cmteh-warehouse-mysql57-20261111`, port3191: **45 PASS** on MySQL5.7.44/PHP8.3. Syntax checks all four Inventory PHP files + integration suite passed. These are workspace-mounted realDB isolated-prefix integration checks, **not** installed-artifact/hostPHP8.2/final strict-verifier evidence; those remain integration/release ticket05 responsibilities.

RED before product code: realDB inventory readiness table absent. Additional REDs: offers leaked removed storefront membership; snapshot under an older REPEATABLE READ view accepted stale SKU. All three now covered and green. Fault injection uses **only** isolated fixture table mandatory-column failure (SQL1364), after actual stock/header changes; no trigger privilege/server-setting requirement. Real two-process reserve race observed both connections blocked on native product mutex, then one accepted/one stale_version, no overdraw. No host config/mail/real warehouse activation/commit performed.


Rootclosure: actualworkspace suite45PASS/exit0 each MySQL8.4 and5.7.44 on3190/3191. PHP8.3local; actualhostPHP8.2/installedartifact notyetclaimed. Rootdidnotreadproductdiff. Finalreview singlefreshwholechange later.

### Nativeorder guard integration02

02зона дополнена catalog/controller/event/cmteh_inventory.php и catalog/model/checkout/inventory_guard.php. Catalogevent Action не можетрезолвитьadmincontroller. ТолькоwrapperaddHistory недостаточно: before-mailhooks ужеотработают доего вызова. Earlybeforeownpriority precedesnativeMAIL; actualregistryDB mutex productrows sorted+transaction holds throughwrapper/finally andpreventsconcurrentcutover. Managedотказ BEFOREeffects; ordinaryunmanaged preserved. Другойbeforehandlerexceptionbetweenownbeforeandwrapper долженrollbackownconnection safely (ownershipflag/shutdownfallback), не оставлять незакрытуюtransaction/не подавлятьexception. Sourcecoreбезпатча, no globalACL bypass. Rootрасширилhelperzones техническивнутритребования, не новыйbusinessscope.

### Импорт/публикация и unknownoperation

Serverpreview показывает, какие предложения будутпубличны послеapply: explicitdesiredpublication includedindigest/receipt, не rawbackgroundpublish и не навсегданевидимыеoffers послеактивации. Оператор подтверждаетраспознанныеценыпредложения, не закупочнуюсебестоимость/НДС. МетодInventoryImport.preview можетдобавитьoptionaloptionsargument дляpublicationchoice, конкретный03contractфиксирует. СтарыйserverотвергаетновыйwarehousePOST4xx (404/405 вфактическомrouter), этоfailclosedcapability анестрого404requirement. Newclientunsupportedresponse не переводитwarehousechoiceвgenericSKU.

### Уточнение03/04/02 в процессе интеграции

03optional source_id column (публичныйcanonicalsource_id, неnativeinteger) разрешёндляразрешенияSKUambiguousimport: еслиестьID, проверятьего↔exactSKU; безнегоSKUдолженбытьуникален. Этоexistingidentity, не новыйтипидентификатораR20. Noautomaticchoiceamongbrands. 04AUXcombinedstorageoverflow (>100 fromoldtabs+newAUX) mustpreserveallstoredrows; limitsapplynewadd/submitwithvisiblemessage, no read-truncate-write data loss.

02foreignouterregistrytransaction cannotproducequietlyunsynchronizedmanagedmetadata. ЕслиpinnedHTTPnone, denybeforemanagedwrites withclearunsupportedtransaction; ournestedownedguardtransactionsallowed. No committing/rollingbackforeigntransaction; no separatePDOprojectionbeforecallercommit. Exact02contractrecordsfailclosedtest. CoreInventoryPDOsavepointjoinsremainlegitimateforimport/Lead andnotaffectedbythisnativeadapterboundary.

## Завершённый таск02 — native integration exactcontract

# Ticket 02 — native stock guards

Owned implementation complete; core Inventory files unchanged. Guard adds no DB columns/tables/permission keys/dependencies or automatic grants. Ticket02 explicit installer migration calls `InventorySchema::migrate($pdo, DB_PREFIX)` under the pre-existing install/upgrade permission gate; reads do not migrate or activate products. Ticket05 integration must explicitly require ticket03's `InventoryImport.php` and call `InventoryImport::migrate($pdo, DB_PREFIX)` on the same PDO after InventorySchema, before the full candidate. This mandatory wiring belongs to ticket05 (depends on02+03+04), keeping standalone ticket02 independent from the not-yet-committed ticket03 class. No conditional skip/no-op is permitted. Import-preview staging schema belongs to ticket03's contract, not a second guard schema.

## Actual native transaction boundary

`Cmteh\InventoryNativeGuard::__construct(object $db,string $prefix)` receives the **registry native DB object**, not PDO. `begin(string $purpose): void`, `finish(bool $success): bool`, `active(): bool`, `run(array $ids,callable $callback): mixed`, `lock(array $ids): array`, `available(int $id): int`, `projectionDeferred(): bool`; static `rejectManaged(array $states): void`; static `assertDeletable(PDO $pdo,string $prefix,int $id): void` for the already-transactional CMTEH deletion path.

Native wrappers lock native product rows numerically ascending, then inventory controls in the same order. The available aggregate is a **current locking read**, ordered by warehouse ID; product mutex prevents concurrent stock changes. No separate PDO is opened until the native commit. Loader route redirection changes the route delivered to the original after-event, so wrappers explicitly call `CmtehCatalog::synchronize` with the original add/edit/delete route **after** commit. Nested original-model proxy calls suppress projection until the outer wrapper completes. Descriptions/native fields survive stale quantity submissions. Managed copy is an unpublished ordinary product with quantity zero and no inherited inventory. Managed deletion, shared-stock variant creation/editing and subtracting-option introduction are rejected. Legacy operations remain native.

Managed operations inside a foreign, pre-existing native transaction fail before writes with `InventoryException(reason=unsupported_native_transaction,status=409)`; that caller retains its transaction. Guard-owned nesting (copy→add, full form→option value) remains supported. Legacy foreign transactions join a savepoint and defer separate-PDO projection while the caller transaction is still open; a native catalog sync warning asks for a postcommit save. A later explicit `synchronize` after that caller commits detects the closed transaction and can proceed. `native_busy` 409 rejects a second prepared scope rather than committing another operation. `managed_stock` 409 is the ordinary protected-path denial. No caller transaction is committed/rolled back wholesale by the guard.

Native order `addHistory` guard runs at priority **-10000**, before native mail/activity/statistics priority zero. It starts the actual native transaction, locks order-product rows plus sorted product/master identities, rejects any managed identity before downstream hooks, then redirects to a wrapper which calls the original native model and commits/rolls back. A later before-event exception is propagated; registered scope-owned shutdown fallback rolls back when the wrapper is never reached. No real mail event/transport is invoked by tests.

## Additive events (12)

All added only if their exact event code is absent; original events are not deleted/rewritten for these additions. Uninstall removes these owned codes. `A=extension/cmteh/event/cmteh_catalog`; `C=extension/cmteh/event/cmteh_inventory`.

| Code | Trigger | Action | Sort |
|---|---|---|---:|
| cmteh_inventory_delete_batch | admin/controller/catalog/product.delete/before | A.guardProductDelete | -10000 |
| cmteh_inventory_native_form | admin/view/catalog/product_form/before | A.inventoryForm | 1000 |
| cmteh_inventory_order_guard | catalog/model/checkout/order.addHistory/before | C.guardOrder | -10000 |
| cmteh_inventory_native_addProduct | admin/model/catalog/product.addProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editProduct | admin/model/catalog/product.editProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_copyProduct | admin/model/catalog/product.copyProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_deleteProduct | admin/model/catalog/product.deleteProduct/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_addVariant | admin/model/catalog/product.addVariant/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editVariant | admin/model/catalog/product.editVariant/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_addOptionValue | admin/model/catalog/product.addOptionValue/before | A.guardProductModel | -10000 |
| cmteh_inventory_native_editQuantity | catalog/model/catalog/product.editQuantity/before | C.guardQuantity | -10000 |
| cmteh_inventory_native_editOptionQuantity | catalog/model/catalog/product.editOptionQuantity/before | C.guardQuantity | -10000 |

Redirected model routes are `extension/cmteh/catalog/inventory_guard.{addProduct,editProduct,copyProduct,deleteProduct,addVariant,editVariant,addOptionValue}` in Admin and `extension/cmteh/catalog/inventory_guard.{editQuantity,editOptionQuantity}` plus `extension/cmteh/checkout/inventory_guard.addHistory` in Catalog. Controller batch redirect is `extension/cmteh/event/cmteh_catalog.inventoryDelete`, with explicit logged user + native `modify catalog/product` guard; it retains native deletion logic after whole-batch preflight. No global ACL bypass/new permission grants. Native stock readonly summary links to existing `extension/cmteh/module/cmteh_catalog.inventory` only for existing `access extension/cmteh/module/cmteh_catalog`; the form's descriptive fields remain editable. Native URL helper receives an argument array containing current session user_token and native product_id, with its raw-JavaScript URL flag enabled; JSON_HEX_AMP safely serializes raw ampersands for JSON-to-DOM href assignment without literal `&amp;` query keys.

CMTEH `CatalogAdmin::deleteRecord` checks managed history before routes/relations/deletion. Existing CMTEH controller deletes one record per confirmed request (no second unguarded CMTEH batch API). Existing `CatalogWrites` deliberately does not update quantity; actual `CatalogImport::preview/apply` regression confirms metadata imports cannot reset balances/reserves/versions/native mirror. NativeCatalog no longer derives stored legacy availability from the managed compatibility mirror; ticket04 owns effective public availability.

## Checks and evidence

`python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/native-inventory-integration.php` — **24 PASS**, exit 0, actual installed OpenCart Loader/Factory/Event/models + native Cart User/isolated synthetic user groups + real MySQL8.4/PHP8.3.

Same command with `--runtime-dir D:/codex/temp/cmteh-warehouse-mysql57-20261111 --port 3191` — **24 PASS**, exit 0, MySQL5.7.44/PHP8.3.36. Not actual target PHP8.2 or final immutable candidate/host verification. Both sites received only explicitly coordinated owned-file copies plus four frozen Inventory core files/CatalogInput; no whole-package refresh or partial ticket03/04 copy. Tests require installed paths via Reflection and matching SHA256 for 17 owned/stable dependencies. Test runner is workspace-mounted; app classes are exclusively installed paths (no workspace fallback).

RED before guard code: actual installed native `editProduct` wrote posted 999 over explicitly managed 10; assertion `Stale native full form preserves warehouse available` failed. GREEN additionally covers native projection after redirected loader, copy zero/no inventory, direct/mixed-batch deletion, native ACL denial, unchanged legacy full form/direct setters, native two-process stale form blocked on real product mutex then preserved newly reserved available=6, metadata save/import, CMTEH deletion, foreign native transaction refusal, direct option setter, order before-hook spy never called for managed, legacy hook path preserved, later-before-event exception/shutdown rollback, user-group ACL bytes unchanged.

PHP syntax: all nine owned PHP product files + integration script PASS. `node --check` readonly-form script PASS; affected `git diff --check` PASS. No host/config/mail/live-user/real-stock changes. Local fixtures use fresh `ngt_<12hex>_` tables, synthetic users/groups, private temporary NDJSON and child processes; all are removed by suite cleanup.

## Coordinated installed file SHA256 (both runtimes)

| Relative to extension/cmteh | SHA256 |
|---|---|
| system/InventoryNativeGuard.php | 1ceffecc31dad729c54a87a41b78f90444e1381d9b7c7783c16b922a3c4dbf10 |
| admin/model/catalog/inventory_guard.php | 938ef514c7385ad82b0c1d022c2d467d326303feb142c26b62d07718494aa3fb |
| catalog/model/catalog/inventory_guard.php | 3a90cd071578b1ea44db1919de2fb681de8ca25b5c58bc1b8f0f49c75451e2d7 |
| catalog/model/checkout/inventory_guard.php | 73ed9fdd7562d520596e0d8094c894d171303ddf32e96522cc494854e4a91314 |
| catalog/controller/event/cmteh_inventory.php | 171a4d25822e085d746eb3e6e59b14fc03cb8083bbccc06314a07c3317756eb1 |
| admin/controller/event/cmteh_catalog.php | 75bd396e37712f5073cf37f45df6b7f6cf85d7fa4c3a5513ee541ddbd6bfe9fb |
| admin/controller/module/cmteh.php | 3a223cbabb3fdbc24104e36dce780c65a5e9bd13f88ab7e39d4c4382addcf260 |
| admin/view/javascript/cmteh_inventory_guard.js | fae3db63e63aa7a589bd2a8a04b1d1ceb33d12a044093c26129e2be3e589532f |
| system/CatalogAdmin.php | 53bcc060886614a384ca13cb078bb660a09d8f143ac4282d8d2e5a9e3207457d |
| system/NativeCatalog.php | ea0850508b05371e4a25a03dcaac32943c1d7c9596d76cbcf2db13c7c2813204 |

UI/browser readonly summary, final full candidate, actual target PHP8.2, existing release/host/recovery safeguards and the one whole-change independent review remain ticket05/root gates. No self-review loop, commits or deployment performed by ticket02.

Integration ownership boundary: ticket03 tests its static staging-DDL migration explicitly, without constructor DDL. Ticket05 owns mandatory Import migrator wiring and the integrated authorized-migrator check before the full candidate. Ticket02 installer is independent from InventoryImport; current installer hash matches the prior 24-PASS receipt. Installed-module PHP syntax PASS after this boundary adjustment; only the module was recopied to3190/3191, with no repeated unchanged domain suite.

Native summary link correction: the initial code incorrectly named a nonexistent standalone inventory route. Confirmed ticket03 entry method is `cmteh_catalog.inventory`. Narrow installed Event fixture `php /workspace/tests/native-inventory-integration.php --form-only` failed before the fix on the expected route assertion, then **3 PASS** on3190 after correction: actual emitted form JSON uses the existing method/native product ID, URL-helper token encoding and JSON ampersands decode correctly, and missing catalog access produces no link. Installed event PHP syntax, updated fixture PHP syntax, and unchanged JS syntax PASS. Only this event file was recopied to3190/3191; domain files unchanged. Per root's explicit owner-policy ruling, root reruns only the affected `--form-only`; the final immutable-candidate strict gate checks the integrated package. Current integration runner SHA256 `e33941cf920e04a03c5bd2549e3a72777dadaec06229d53ff3df51cc310d19bc`.

Prior 24-PASS ×2 receipt: runner SHA256 `b106d0b4bab00147eda07b1d807e252523781440f602848a941736238d514aa1`; event file SHA256 `cef7fb85d6065e0eb37f66a4cd236e7fe54fd7443bb080b41e3767138a6d1efc`; installer SHA256 `3a223cbabb3fdbc24104e36dce780c65a5e9bd13f88ab7e39d4c4382addcf260`. All domain/wrapper hashes and the installer remain exactly those in the table above. The later form-link change has separate narrow evidence above, so the earlier full native runs are not misrepresented as having executed that later line.


Rootclosure02: installed form-only3PASS exit0, actualeventdata route/product/tokenencoding+ACL checked. Priornative24×2 checks reused withmatching source receipts; do notbroadrepeatforhref-onlychange. MigrationImportwiring deferredMANDATORYto05, noconstructorDDL/noop.
