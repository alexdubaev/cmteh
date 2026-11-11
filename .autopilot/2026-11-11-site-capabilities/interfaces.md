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

## Завершённый таск04 — public/Lead exactcontract

# Ticket 04 — exact public integration contract
- DONE: G01/G03/G04/G05/G06/G08/G09 public warehouse selection + additive RFQ snapshots; no reservation, account, order, checkout, payment or raw Quote changes.
- `Catalog::publicWarehouseOffers(array $sourceIds): array` delegates Inventory with the actual store/language; batched at 1000 IDs, total 10000 projected offers. Products/list-check add `inventory_managed:bool, offers:PublicOffer[], offers_partial:bool`.
- Managed product global `price=null, price_status="warehouse"`; effective availability derives from any active published offer with available stock. SQL filter/count/DTO agree without warehouse joins multiplying products. Price sorting uses minimum visible offer price, null prices last; it never selects an offer.
- Product cards link to explicit warehouse choices; detail/list-check show escaped public labels, exact decimal price, status, delivery term. Zero availability disables cart selection and retains the general quote link.
- `GET /api/warehouse-offers?ids[]=<source>` accepts 1–100 bounded exact source IDs and returns `{data:sourceId=>{managed,offers,partial}}`, no-store. Public DTO is Inventory’s allowlist, with no physical/reserved counts or private address/note.
- `Lead::__construct(PDO,string,int $storeId=0,int $languageId=1)` preserves two-argument callers. Existing `submit(string,string,array=[]):array` signature stays unchanged; new `submitWarehouse(string,string,array=[]):array` is explicit.
- `POST /api/lead/warehouse` shares existing origin/content-type/rate/contact guards and invokes `LeadInput::parseWarehouse(string):array`; legacy parse shape, property order, fingerprint and pending namespace remain unchanged.
- Selected wire item: `{article:string,quantity:int,product_id:sourceId,offer_id:decimalString<=20,offer_version:lowercaseSHA256}`. At least one selected item required; both selection fields required together; legacy freeform lines can coexist.
- Warehouse normalized data adds per-item `offer_id,offer_version` and final `warehouse_contract:1`; requested identity/revision determines fingerprint, never current live prices or client labels.
- First acceptance uses one PDO transaction/header-key lock, native product/control locks in ascending native ID, Inventory locked resolver, existing items/outbox and `cmteh_lead_inventory` at internal BIGINT lead_id/1-based position. Table and Lead schema3 are owned/preserved by 01.
- Snapshot exact fields: `offer_id,offer_version,warehouse_code,warehouse_name,warehouse_city,unit_price:string|null,currency,delivery_term,availability_status`; accepted replay resolves before freshness and never rereads/replaces snapshots.
- New canonical managed legacy submission without explicit selection returns 409 with a safe Russian reselection message; previously committed legacy replay still returns its original ID. Stale/tampered selected first write returns 409 and rolls back header/items/job/snapshots; SQL failures return honest 503.
- `Lead::find` left-joins snapshots when Inventory is installed; manager detail displays original warehouse/price/term independently of later warehouse/product edits. No private ACL or native controller changes.
- Original `smtechno-request` remains an array for unassigned legacy rows. New `smtechno-warehouse-request-v1` is an independent array of draft selected rows with `productId,offerId,offerVersion,article,title,brand,quantity,warehouseName,warehouseCode,warehouseCity,deliveryTerm,price,currency,warehouseSelection,selectionInvalid`.
- Cart identity is source product + offer + revision for acknowledgement; merge identity is product + offer and refuses revision replacement without review. Same product A+B are separate; acknowledgement subtracts submitted quantities and preserves concurrent additions/reviewed revisions.
- Selected pending key is `smtechno-pending-warehouse-lead-v1:<formType>`; legacy pending key/hash/property order remain byte compatible. Unsupported selected endpoint has no fallback to `/api/lead`; 404/405 preserve the selection and pending draft.
- Combined legacy+AUX data exceeding 100 rows stays intact; new additions/submission are rejected visibly, no combined truncation. Storage failure retains the in-page recovery copy and refuses acknowledgement clearing. Malformed selected drafts stay visible for repair/removal.
- Cart exact decimal arithmetic uses BigInt minor units, including totals beyond JS safe integers. New public CSS/module graph suffix is uniformly `20261111-1`, including request-import/vendor inherited query and updated current-graph browser expectations.
- RED: actual managed canonical legacy RFQ was accepted instead of 409; Catalog omitted two-offer projection/effective availability; real browser merged legacy+A+B into one line rather than three.
- Checks: `warehouse-offers-integration.php` 18 PASS on installed MySQL8.4/PHP8.3 and workspace MySQL5.7.44/PHP8.3; `browser/warehouse-offers.mjs` 7 PASS against installed 3190; Catalog20/Lead35/Quote54 compatibility PASS (private capture only).
- Browser proves actual product A+B→reload/independent quantities/terms→server stale409/no orphan→explicit review→real commit/lost acknowledgement→same-key replay→fresh addition retained; also prior cached 2031f82 module, storage failure, BigInt bound, unsupported endpoint and 101 combined rows. Owned fixture records are cleaned.
- Installed evidence: `D:/codex/temp/cmteh-warehouse-dev-20261111/evidence/warehouse-offers-targeted-installed.json` verifies Reflection paths and exact SHA256 for 12 installed class/trait files plus private test copy; product/cart 390px PNGs were visually inspected without horizontal overflow.
- Limits: legacy full browser suite cannot run here because documented RE568158 fixture is absent (GET search total0); 05 must run it after final fixture seeding. Final immutable package/strict verifier/actual host PHP8.2/deployment are NOT claimed. No commit, host action, external mail, real customer/stock fixture or secret output.


Rootclosure04: preservedtestreceipt verifiedcurrent12sourceclass hashes, all12Reflectionpathsunderinstalledsystem andprivate runnerSHA; reusecompleted18×2+browser7+existingcompatchecks perownerpolicy, no needlessrepeat. Legacyfullbrowsermissingfixture explicitlypending05, notPASS. Rootdidnotreadproductdiff.


## T04 visual closure

After sourcecommitc9dfe40, root390visual foundchoicebelowgenericmetadata andqty1duplicateprice. Sameauthor boundedfix only product.twig/request.mjs/storefront.css. Narrow realbrowser REDbefore→GREENafter: firstofferbutton y610–656 abovefixedactions776 atinitialscroll0; qty1oneunitprice/qty2exactsubtotal. FreshBODYfocus skiplinkhidden; noCSSfixneededforcaptureartifact. Rootviewedboth390repairedPNGs andaccepted. No backend/storagechange; previous18×2/compatchecksreused, nobroadrepeat. Newactualstaticartifacthashes finalcandidatewillinclude3files.

### D02 — фактическая доставка adminJS

RootactualHTTP3190 confirmed oldadmin/view/javascript/cmteh_inventory_guard.js404, existingpubliccatalog/assets/storefront.mjs200. 02movesgenericJS to catalog/view/assets/inventory-native-admin.js?v=20261111-1, verifiednew200text/javascript (nativeactualbrowserpending). 03genericmodule to catalog/view/assets/inventory-admin.mjs; privateconfig/user_token НЕstaticJS. ProtectedHTML includespageconfig, APIskeepnativeaccess/modify/token. Noauthenticated asset=js/newroute, no.htaccessallowlistwidening. 02oldadminJSownedfiledeleted/moved, 03oldadminassetsownedfiledeleted/moved. Zones02/03extenduniquefiles, 04frozenpubliczone narrowedmetadata (nochangesownedfiles). 02nativebrowser newtests/browser/native-inventory-form.mjs reuses04fixture withuniquenonce/finallycleanup +normallocaladminloginpasswordreadmemoryfromruntimefile neverstdout. Tests actualassetstatus/MIME/readonlystockvalue/editabledescription/link→existinginventoryroute. Requirementunchanged, rootboundedrepair2 for02UI.

### Финальная физическая сборка — один strict прогон

Interimtargeted source/installed rawhashes в01–04контрактах относятсякworkingtree physicalbytesвмоментпрогона. Finalcandidate authority — неизменяемыйZIP/filemanifest, не эти промежуточныеhashes. GitcanonicalLF и Windowswrite_textCRLF могутдатьдругиефизическиебайты приcanonicalsamecode. Все5previousacceptedCRLFpaths(Catalog/Lead/LeadInput/parts-list/adminleadTwig) теперьINTENTIONALLYmodified04, поэтомуNOlegacyacceptedoverlays дляних инидляnewchangedfiles. Seed-cleanupJSON -text/pinnedrawSHA неизменен.

После05+review/fixes commit source rootготовитcleanclone с per-commandcore.autocrlf=false (неglobal/commonconfig) исобирает/strictпроверяет ТОТЖЕimmutableartifactизclone ровноодинразпослеинтеграции. Новыйemptyruntime/literalnonoverlappingsubnet+cachedimages/tooling/referencesreuse. Installedhash/Reflectionguards сравниваютсясфактическимcandidate/clone, непереписываютсяподPASS. G4blindagentbriefonlyизcleanclone; не надо2полныхstrictвworktree+clone. 05проверяетсвоюузкуюинтеграцию/мирграцию/negativeguards, rootfinalstrict позже, еслинепоявитсяреальнаяошибка. Послеdoc-onlyacceptance новыйproductпрогонненужен.

## Завершение D02 / native deliveryrepair02

Exactcurrentpath: catalog/view/assets/inventory-native-admin.js?v=20261111-1; formeradmin/view/javascript fileDELETED. ActualsourceHTTP200/text/javascript+rawSHA match verifiedroot3190. Genericcodeunchangedhashfae3db63…, currentPHPevent332ff86b…; protectedconfigremainsHTML. Ownnativeformrealbrowser3PASS (readonlyavailable10/descriptiveeditable, link→actual03.inventory withproductfilter, cleanup0ownedroles/event/sessions+otherACLhashesexact). Form-only4PASS, no unchanged24×2repeat. DefaultbootstrapadmincorrectlylacksCMTEHcatalogaccess; temporarynative-authgroup/userexistingkeysusedforfixtureonly, nodefault/ownergrants. Actualnewfiletests/browser/native-inventory-form.mjs owned02. Both3190/3191targetcopies exactevent+asset, module/guardsunchanged. RootalsoactualHTTPverified inventory-admin.mjs?v20261111-2 matches03sourcebytes. D02 doesnotchangepublicAPI/dataACL.

## Завершённый таск03 — admin/import exactcontract

# Ticket 03 — admin warehouses and spreadsheet import

## Public interfaces

- Admin GET/POST route: `extension/cmteh/module/cmteh_catalog.inventory`; ACL is the existing exact key `extension/cmteh/module/cmteh_catalog` (`access` for reads, `modify` for POST). GET filters: `page`, `search`, `warehouse_id`, `product_id` (native integer, admin-only).
- Generic UI behavior module: `/extension/cmteh/catalog/view/assets/inventory-admin.mjs?v=20261111-2`; it contains no user data, config, prices, or credentials and uses the existing bounded vendor XLSX reader. Script requests do not carry the admin token.
- `InventoryImport::migrate(PDO $pdo,string $prefix): void` is explicit, additive, idempotent migration; it is not called by a constructor, read, or preview.
- `InventoryImport::parseCsv(string $bytes): array` accepts UTF-8/BOM or Windows-1251; canonical header is `article;warehouse_code;quantity;price;delivery_term`, with optional final `source_id` text column for exact SKU disambiguation.
- `InventoryImport::validateXlsxFile(string $path,bool $hasSourceId=false): void` checks bounded ZIP/XML, formula absence and text-typed article/warehouse cells; it does not calculate formulas.
- `InventoryImport::preview(array $rows,array $actor): array` returns `{preview_id,digest,expires_at,summary,errors,rows,page,pages,shown_rows,can_apply}`. Only 100 rows/errors are rendered per page; all rows are validated and stored. Rows carry expected product/legacy/balance/warehouse versions and exact canonical source mapping. `source_id` must identify the same exact SKU; no native integer is accepted as the disambiguator.
- `InventoryImport::apply(string $previewId,string $digest,array $actor,string $requestKey): array` is actor-bound, expires after 30 minutes, applies the complete preview inside one PDO transaction/savepoint, and returns the stored receipt for exact-key replay.
- `InventoryImport::storedPreview(string $previewId,string $digest,array $actor,int $page=1,bool $retryAllowed=true): ?array` reloads a bounded page of the same actor’s preview after an apply conflict or page navigation. Failed apply displays the saved rows and disables a second attempt with the stale digest.
- Import header fields: `article`, `warehouse_code`, absolute integer `quantity`, positive decimal RUB `price` or blank for on-request, optional override `delivery_term` (blank inherits warehouse default), optional final text `source_id`. No missing-row zeroing, fuzzy SKU, or entity creation. Explicit `publish_offers` is included in preview and apply.
- First unmanaged-product import always requires explicit cutover confirmation; a quantity difference also requires an audit reason. Preview shows the previous shared quantity and the per-row warehouse allocation/difference.

## Added staging schema

`cmteh_inventory_import_preview`: `preview_id CHAR(32)` PK; `actor_id INT`; `digest CHAR(64)`; `payload LONGTEXT`; `expires_at DATETIME`; unique nullable `apply_key VARCHAR(128)`; `result JSON`; `applied_at DATETIME`; `created_at DATETIME`; `(actor_id,expires_at)` index. Applied receipts are retained; each preview call removes up to 200 expired unapplied rows. No schema-version marker is introduced.

The existing module migration wiring is intentionally left to ticket 05 per orchestration; local test fixtures call `InventoryImport::migrate` explicitly. Before deployment, ticket 05 must invoke it from the existing authorized module migrator after InventorySchema, then verify a fresh installed candidate.

## Checks and RED proof

- Before implementation, `inventory-import-integration.php` failed as expected with missing `InventoryImport.php`.
- `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 compose exec -T web php /workspace/tests/inventory-import-integration.php` → 30 PASS on isolated MySQL 8.4/PHP 8.3, including UTF-8/BOM, CP1251, bounded XLSX formula/leading-zero checks, actor/digest, publish choice, explicit cutover, stale conflict rollback, replay/savepoint and actual 1,000-row preview/apply with 100-row UI pages.
- Same integration command with runtime `D:/codex/temp/cmteh-warehouse-mysql57-20261111`, port `3191` → 30 PASS on isolated MySQL 5.7.44/PHP 8.3.
- PHP syntax: runtime `php -l` for `InventoryImport.php`, `cmteh_catalog.php`, `inventory-import-integration.php` → no syntax errors.
- `node --check opencart/extension/cmteh/catalog/view/assets/inventory-admin.mjs` and `node --check opencart/tests/browser/inventory-admin.mjs` → pass.
- `node opencart/tests/browser/inventory-admin.mjs` on targeted local runtime 3190 → PASS catalog navigation/import form, uploaded invalid CSV row error/focus, desktop+390px layout; PASS direct POST denied for a temporary read-only role.
- `node opencart/tests/browser/inventory-import-happy.mjs` on targeted local runtime 3190 → PASS a real native-admin warehouse-create POST, valid XLSX through the static browser reader and multipart preview POST (HTTP 200), explicit publication selection and whole-preview apply POST; DB and reloaded admin UI confirm destination quantity 9, price `1777.25`, published=true, omitted original warehouse unchanged at quantity 6 / `1000.10` / unpublished, and no automatic product or warehouse creation. The same UI flow reserves 3 (available 9→6) and releases all 3 (available 6→9); exact nonce product, warehouses, ledger, preview, temporary user/group and password file are removed.
- One implementation failure found during the final DB run: a managed row’s native quantity mirror no longer matches its original legacy revision after stock changes. Fixed by checking managed product version and checking legacy hash only before cutover; both DB versions then passed.
- Positive browser XLSX preview initially returned HTTP 422 because native `Request::clean` HTML-escapes POST strings before the JSON row bridge is decoded. The existing controller now applies one matching `htmlspecialchars_decode(..., ENT_COMPAT)` before `json_decode`; the real XLSX preview/apply and stock-operation browser flow then passed. Inventory service and frozen core contracts were unchanged.

## Scope note

No public storefront files, request-import normalizer, mail flow, new dependencies, remote host, or production data were changed. The XLSX reader uses the existing vendored library after bounded archive inspection; server preview validates recognized rows but does not claim a cryptographic raw-file-to-row attestation.

Rootclosure03: completedpositiveXLSXwarehouse-create→preview/apply→reserve/release HTTPflow, controllerdecodeoncefix; service30×2unchangedreuse. No broadchecksrepeatforcontroller-onlyfix. Quickform04sanitycodeconfirmed existingrequest-onlyguard; quickitems[]/api/lead/pendingquick andcartretained, nochange/noseed. Finalsourceexactinstalledpackage/rootstrict stillpending05.

### D03 — observed installer sequencing

05sourceobserved applyChanges() migrate first thenregisterevents; only12additiveinventoryEvents movebeforeInventorySchema readiness, notrewritingpreexistinglanguage/adminhookflow. OldadminEvents deletes/readds5ownhooks onwholeupgrade: existingbehavior notbytepreserving, actualhost MUSTNOT fullapplythatpath. Hostinitialinventoryschema/data confirmedabsentbeforego: codeactivate→exactnew12events(nooriginal67rewrite)→InventorySchema+Importexplicit emptyDDL. BeforetablesreadynewInventorycommandsfailclosed/notacceptactivation; afterreadyallnativehooksalreadypresent. Unexpectedexistinginventorydata/baselineFAILSTOPforseparatecompatibleprocedure, no newauth/privilegedaccount/readonlybusinessdowngrade. 06prepareshelperguards, rootexecutesafterreview/actualfinalartifact.

### Recovery boundary final

06choosesstronger guard: ANYinventorytable/readinesspresent→oldbaselinebinaryrollbackDENIED, evenemptySchema. Onlypre-DDLwithfreshabsencefact permitsbaselinecodefallback. AfterDDL compatibleexactcandidatecode recovery/reinstall, liveDBneveroldrestored; twoidenticalcandidatecopies proofplanned. Thisisoperatorartifactguard, NOTapplicationruntimefloor. No newauth/maintenance/privilegedaccount. Unknown/manualcopyoutsideapprovedpipeline notguaranteedbyapp. Rootacceptsconservativeguard ratherthaninventquiescence/lockingforoldemptyrollback. Updatepreviouspreactivationphraseaccordingly: 'pre-DDL' notjust'no commands'.

05normalfresh/repeatmodulemigration actualPASSbothschemas12hooks+ACL/nonownedrows invariant. Explicitblocked-initial-schemafaultinjectionNOTRUN; notclaimedasPASS andnotappfeatureplaceholder. Earlyguardregistrationcontrolflow singlestrongreview +actualnormalflow proof, actualhostmanualsequenceindependentalso12beforeSchema. Rootfullimmutablecandidate/recoverydumpcomparisons/G4/hostfunctionalstillpending.

## Завершённый05 — integration exactcontract

# Ticket 05 — integration and release evidence

## Module startup order

`Cmteh::applyChanges()` installs the exact 12 additive inventory hooks before running migrations. Hook registration is safe before readiness: native guard readers detect the absence of `cmteh_inventory_product` and preserve legacy behavior until there are managed rows. A failed/incomplete migration therefore cannot leave managed inventory without installed native guards. The authorized existing migrator then runs, in order:

1. `InventorySchema::migrate($pdo, DB_PREFIX)`
2. `InventoryImport::migrate($pdo, DB_PREFIX)`

Both migrations use the same OpenCart PDO connection. Import staging does not run from a constructor/read. Existing 12 hooks are inserted only when absent. The module's existing five `adminEvents()` hooks are still delete/re-added during `applyChanges()` (including the language hook); they are separate from the additive 12 inventory events. No claim is made that the full module upgrade preserves those five event row IDs/bytes.

The supported host operation is staged by the release owner: install candidate code, add the exact 12 inventory hooks while preserving existing event rows, then run schema migration against empty inventory. The target host has no warehouse inventory schema/data yet. Do not use the unvalidated whole-module upgrade there merely to register the new hooks.

## Installed-candidate suite registry

`dev/release_checks.py::INSTALLED_SUITES` registers the inventory core, native guard, import, legacy lead, warehouse RFQ, and recovery suites. Each suite stores installed extension members from the candidate ZIP, verifies installed realpaths and SHA-256, copies the exact workspace test bytes to `/var/www/cmteh-verification/tests`, verifies the private copy, and checks reflected `Cmteh` system classes against the candidate receipt. Warehouse/lead fixtures also receive exact SHA-256 receipts in a private non-symlink directory; no test falls back to executing itself from `/workspace`.

Before the core suite, negative cases require the installed verifier to reject a wrong dependency hash and a test path redirected into `/workspace`. The original seed cleanup and admin business installed-suite guards remain registered with their exact source/dependency checks. No CLI downgrade or expected-hash rewrite was added.

## Recovery fixture

`tests/inventory-recovery-integration.php` accepts only `seed`, `advance`, `verify`, and `cleanup` plus a 12-hex task token. It creates a synthetic native item, warehouses, and opening allocation in the isolated release database; the release harness dumps the database; then it records a completed transfer, published prices/terms, a manual reservation, and an accepted non-reserving warehouse RFQ. After the compatible code upgrade, it compares canonical inventory/history/reservation/lead snapshot hashes and checks exact snapshot values. The database dump is restored only into two uniquely named disposable databases for comparison; the old dump is never restored over the working database. The fixture cleanup removes its unique synthetic lead, inventory records, warehouses, source mapping, and native product.

The expected snapshot is: pre-backup dump has no task-token transfer/reserve commands and no task RFQ; latest dump and working database contain both commands and the warehouse/price snapshot; compatible migration preserves active reserve and immutable history. The approved release/host operations deny baseline-binary rollback once any inventory table exists, including an empty table. Code recovery uses the exact compatible candidate with the current database preserved. The application has no version floor; manual copying outside the approved workflow is unsupported.

## Browser journeys in the release gate

The strict verifier runs two sequential browser journeys after the seeded legacy browser acceptance: `inventory-import-happy.mjs` exercises a real XLSX preview/apply and reserve/release; `warehouse-offers.mjs` exercises storefront A+B selection, cart separation, stale offer rejection, lost-ack replay and preservation of a fresh cart addition. There is no viewport/theme matrix for these warehouse journeys. Both scripts receive fixture paths in `/var/www/cmteh-verification`; the warehouse integration fixture is staged by the installed-suite guard with candidate SHA/reflection receipts, while `inventory-import-fixture.php` is copied and SHA-verified from the exact workspace source to a private test path. Browser source hashes are checked before/after each run, and both scripts perform ownership-scoped fixture cleanup.

## Owner evidence / checks

- `python opencart/dev/runtime.py --runtime-dir D:/codex/temp/cmteh-warehouse-dev-20261111 --port 3190 start --upstream D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip --refresh` → installed authorized module upgrade on isolated MySQL 8.4/PHP 8.3.
- `verify-local` will exercise a fresh runtime install plus `inventory_migration(runtime)` before its final strict gate. On existing isolated runtime 3190, `inventory_migration(runtime)` confirmed both schema tables, all 12 hooks, and that a repeat authorized module upgrade leaves group permission rows, all 12 hook rows, and all non-owned event rows unchanged.
- Installed candidate suites on the same isolated runtime: `inventory-integration.php` 45 assertions; `native-inventory-integration.php` 24; `inventory-import-integration.php` 30; `lead-integration.php` 35; `warehouse-offers-integration.php` 18. Each installed receipt reflected only the candidate's classes and hashes. Core guard negative checks rejected wrong hash and workspace path.
- `inventory_browser_acceptance(runtime, tooling)` on local runtime 3190 ran `inventory-import-happy.mjs` then `warehouse-offers.mjs` sequentially → PASS XLSX preview/apply/reserve/release and PASS A+B cart/stale/lost-ack journeys. Both PHP fixture inputs came from the private installed candidate test path or an exact-hash private fixture copy; installed receipts and browser source hashes were rechecked after each run. Both scripts completed their scoped fixture cleanup.
- Manual recovery fixture exercise on runtime 3190: synthetic setup → separate DB dump → transfer/reserve/warehouse RFQ → compatible module upgrade → preservation hash verification → owned fixture cleanup. The final immutable candidate strict verifier and full recovery shell comparison run in the root's clean-clone gate.
- `php -l` for `admin/controller/module/cmteh.php` and `tests/inventory-recovery-integration.php`; Python AST parse for `dev/release_checks.py` and `bin/verify-local`.
- MySQL 5.7 targeted inventory/import/browser evidence from ticket01–04 is retained in `interfaces.md`; it was not rerun because ticket05 changes do not alter the 5.7 domain services. Actual host PHP 8.2 behavior, host deployment, real mail and real stock data remain unverified.

Rootchecked6currentrawsourcehashesagainstfinal05checkreceipt. Actualnewcriticalbrowser2journeys onprivateinstalledfixtures PASS; legacyseededfullbrowser/finalstrictrootpending. No broadrepeatafterdocs/registration. Actualhostworkflowseparate06/RootGOonly.

## Завершённый06 — Ops preparation (execution NOTRUN)

# Ticket06 — prepared external host operations

Scope: only `D:/codex/temp/cmteh-warehouse-ops-20261111/` plus this prose file. Application/interface files and other tickets were untouched; no commits. Root owns final seal, one whole-change review and every host write.

Read-only actual target evidence: PHP8.2.35 / Python3.6.8 / MySQL5.7.44-48, inventory absent, exact67 events, extension113, native five pinned raw hashes, fsync support. Every extension raw member matches accepted immutable ec2b43… outer / 8cb2c4… nested archive. No uploads, app/bootstrap/native login, host file/DB writes, SMTP, cron, schema or deployment was performed.

Prepared root-sealed actions: fresh protected DB/public/private/config/cron backup and independent rehash; fail-closed SQL own-prefix restore/FK/ledger cleanup; raw-byte candidate stage with second compatible copy; actual gap-free renameat2 rehearsal/exchange; exact additive12 event registration preserving original67 IDs/row bytes with durable ownership intent before commit; explicit empty InventorySchema+InventoryImport DDL only after guards; CMTEH-only cache clear; compatible code-only recovery preserving DB. Full module upgrade/global DB restore/marker lowering/group grants are absent.

Before INSERT, final candidate hash-pinned module is exported with pure Reflection (no constructor/bootstrap/DB) and token-parsed addEvent payload. Root seals `event-profile.json`; code/description rawUTF8/trigger/action/status/sort and every observed column must match sealed final source plus independent exact12 canonical profile. Unknown extension/columns or dynamic metadata reject. Export against final immutable module is pending. The semantically frozen current05 module has separately passed actual pure export on local3190/PHP8.3.36: raw sourceSHA `a84d8fbf1dc9731ffb8e8a74a2a65d1f0c83c9a96fba255cbac3285b5f035515`, exported profileSHA `aca0e18322980e43c6b3a2226957f55d8b08317a05c6e6a1ece6098a5c54142e`, exact12 full canonical rows, correct rawUTF8 description/status1/priorities -10000+1000. Actual native Controller parentSHA `9a42d9104c9ad86c2d737b8747c4e3f0f355e74a9c942774354397a9f280c031` matched pinned upstream; Reflection resolves that real parent, registry stays uninitialized, exact5 included files contain no config/DB/bootstrap/autoload. Owned local Docker/tmp copies were removed by exact filenames; no host access/app/DB/permissions changes. This is current-source evidence, not final seal or GO; runtime helper source was unchanged.

Private actual-host plan mechanically adapts accepted core/import/native/warehouse suites and05 recovery fixture; original/adapted SHA/rules sealed, assertions/Reflection/dependency checks retained, distinct installed/source candidate copies. Exact table/file ledgers precede any CREATE/commit; schema-only native clones copy zero customer/user rows. After fresh backup, recovery fixture creates transfer/reserve/RFQ then two identical compatible candidate copies exchange/reverse; all own table hashes and fresh PHP assertions must remain exact. Functional host execution is NOT RUN.

Old binary guard is operational, not an app runtime floor. Fresh live counts reject any inventory data; guard also conservatively rejects any inventory/readiness table including marker-only empty schema. Baseline code rollback is only pre-DDL. After DDL only exact compatible candidate recovery/reinstall, with DB retained. Root accepted this stricter boundary. New task must not reopen a completed old GO.

Checks: `python D:/codex/temp/cmteh-warehouse-ops-20261111/test-ops.py` →13 PASS local boundaries. RED: inherited dict union rejected for Python3.6, fixed only CLI summary; strict SQL parser remains fail closed and optimized interpreter is forbidden. `python .../test-target-readonly.py` → actual target stdin-only compile/syntax19 helpers, foreign path/unknown event rejection, canonical12 accepted, seven synthetic in-use old-rollback denials, rawUTF8/status/extension metadata tests; no DB or app bootstrap. These are metadata/syntax checks, not functional integration PASS.

Remaining root gates and invocation details: external `ops-plan.md`; exact helper/evidence hashes in `ops-prepared-index.json`; draft seal/GO all closed with unknown candidate/review hashes null. Final package/profile, strict installed evidence, one fresh review, backup/restore/actual-target integration+compatible recovery/private HTTP acceptance and fresh phase GO remain required. Private HTTP bootstrap is not yet proven; no new real privileged account/auth downgrade/public warehouse fixture is proposed.

Rootclosure: preparedindexSHA9cbc195d…verifiedall32indexedfiles/rawhashes; draftROOT-GO actions12allFalse, actionnull. No hostmutationauthorizedatprep. Exact19runtimehelpers/finalcurrentprofilepureexportchecked; actualfunctionalhost/privateHTTPproof remainsrootgateafterreview/finalcandidate.
