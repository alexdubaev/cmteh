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

Inventory::__construct(PDO $pdo,string $prefix). Методы:
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

New POST /api/lead/warehouse accepts normalLeadcontactfields + request_items where selectedlinesadd offer_id/offer_version pair beside existingproduct_id; canincludepreservedlegacyfreeformlines. Rejectmalformedselection; newserver legacy/api/lead refusesfirstacceptancecanonicalmanagedlinewithoutoffer, committedoldreplaymuststillwork. Oldservernewpathfails404, neverignoresselection. New GET /api/warehouse-offers boundedIDs forcartfreshness, no arbitrarynative routes. ExistingrawQuote routebody untouched.

LeadInput adds explicit parseWarehouse path withoutchanging legacyparse normalizedshape. Lead firstacceptance samePDO saves baselead/items/outbox and nullablepositionkeyed cmteh_lead_inventory snapshotstable; InventorySchemaownsadditivetable ifLeadmarker3unchanged. Snapshot {offer_id,offer_version,warehouse_code/name/city,unit_price:string|null,currency,delivery_term,availability_status} immutable onacceptedreplay. Resolveproduct↔offer/exactSKU/publication and revision beforeinsert andundercompatiblelocks. Canonicalfingerprint usesonlynormalizedrequestedselection+legacydata, notliveprice. Typedstale409 tellsreview; no orphanlead/job/reserve. Admin Leadfind/display joinsnapshotfororiginalrows, privateACLunchanged. New JSversion allimports consistent, previouscachedJS tests included.

## Проверки и восстановление (owner ticket05)

Core realMySQL integration isolatedprefix; nativeinstalledloader/ACL tests; importpreview/apply rollback/version/races and1000rows; publicofferproductcounts/prices/lists; Lead immutablewarehouse/noreserve/idempotency/lostack; shortcriticalE2E andtargeted390pxmanualvisual. NOT sourceassertions/mirrorunits/giantmatrices.

Finalstrict verifier remainsstrict; new suite runsinstalledcandidate dependencieshashguard/Reflection. Exactimmutableartifactbytes correspondtosource. RawseedJSONpin unchanged. ActualtargetPHP8.2/MySQL5.7 requiredcompatibleproof; current8.4onlynotclaimhostversion. Upgradebackupfresh+privatefiles+cron+actualrecoverycopy. Oldpackage rollbackallowedonlyprovennoactivatedmanagedproducts/noinventorycommands/nochangedselectedsubmissions; afteruse oldcoderejectsnewselectedpath butcan'tservicefullinventory, so incompatibleoldbinaryrollbackblocked. Keepcurrentcompatibleartifact/privateDB snapshot recovery and verifyrestore/reinstalloncopy includingpostbackupmovements/RFQ. Optional inventoryreadonly recoveryflag permitspublicoffer/RFQread+snapshots anddeniesadmininventorymutations whilekeepingnativeguards. NeverrestoreoldDBovernewdata, neverlowerSchema3 orgrants. Finalhostdeployment onlytest.cmteh.ru, noexternalmail/actualstockfixtureactivation.

## Изменения исполнителей

Каждый completedticket добавляет точные созданные table/columns/methods/checkcommands и контрактные уточнения сюда; незаметное изменение соглашений запрещено. Новое бизнесответвление возвращается lead, невыдумывать ownerfacts.

## Рабочие команды и владение

Baselineorigin2031f82; taskbranchcodex/site-capabilities-20261111. Runtime taskdev D:/codex/temp/cmteh-warehouse-dev-20261111 port3190 (rootprepare), upstreamZIP D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip, node tooling D:/codex/cmteh. PHP8.3local/MySQL8.4 vsactual8.2/5.7 notconfused. Runtime commands through python opencart/dev/runtime.py --runtime-dir ... --port3190 compose exec -T web php /workspace/tests/<suite>.php withestablishedisolatedtestconfig; neverprintfcredentials. Finalaffectedcheck command documentedstrict python opencart/bin/verify-local --upstream <pinnedZIP> --runtime-dir <newempty> --reference-dir <known18reference> --port <free>, not newlyinventedwrapper. One suite commandexisting runtimehelper; dependenciescached no npmupdate/newlibrary. Root willgivereadyrealDBconfig path privatelywithoutvalues.

Parallelwrites ownership: ticket01 Inventorycore standalone; 02 nativeguards/moduleinstaller/CatalogAdmin/Writes/NativeCatalog;03 CMTEHcatalogcontroller/privateadmin/menu/InventoryImport;04 publicCatalog/Storefront/Lead/publicassets/templates;05 installedverification/docs onlyafterall. Askleadbeforeeditinganotherownerfile. Sharedinterfaces updates appendonlynamedsection. Core methoderrors/payloadkeys freeze at01. Everymutationandtest hasowned data/noproductionmail; no mixedgitcommits. Ownerpolicy overrides separatefoundationpointreview: onefresh wholechange independentriskreview afterintegration +boundedfix/narrowclosure; no repeatedcleanreview.
