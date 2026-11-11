# Orders, CRM and conditional catalog discovery

Read-only discovery, 2026-11-11. Base supplied by lead: `2031f82`. No product code, migrations, runtime, mail, configuration or private files changed. Only this report is written. Decisions below are proposed boundaries, not accepted business policy.

## Evidence and runtime

- `docs/architecture.md:3` explicitly identifies `opencart/` as production and root Next.js as a behavior/URL reference. OpenCart has its own DB; Directus is not a runtime dependency.
- Existing classes are plain PHP/PDO services. `Database.php:12` creates a PDO connection separately from OpenCart's native DB library. A transaction on this PDO does **not** wrap native model SQL on the registry's other connection.
- `opencart/extension/cmteh/admin/controller/module/cmteh.php:202` is the installation/upgrade migration seam; Lead/Quote are invoked at `:216`/`:220`. Reads must remain migration-free.
- Native evidence is OpenCart 4.1.0.4. Public audit extraction is `D:/codex/temp/cmteh-admin-unification-20261111/native-audit/upload/`. It contains only a subset: native `catalog/model/checkout/order.php` and schema are absent. Those were inspected in-memory from `D:/codex/temp/cmteh-admin-final-runtime-20261110-r4/opencart-4.1.0.4.zip`; supplied verified SHA-256 is `cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0`. No second extraction or writes were performed.
- In the following native citations, `native:` means that ZIP's `upload/` directory; these are verified upstream source line numbers, not current project files.

## Existing source records are requests, never a commercial offer

`Lead.php:45` stores a source request; `_item` at `:58` holds requested article/quantity and submitted optional product identity. `LeadInput.php:123` accepts at most 100 items, requires integer quantity, and discards submitted price. The optional product ID is untrusted submitted text, not purchase authorization. `Lead.php:211` saves request, item snapshots and notification job atomically, using request-key uniqueness, fingerprint equality and a locked lookup.

`Quote.php:29` stores an RFQ with either raw text or file; no agreed commercial line prices exist. `Quote.php:78` locks quota then request-key lookup, persists immutable input plus original attachment and outbox in one transaction. Existing `pending/sending/retry/sent/failed` are notification delivery states. Neither mail success nor RFQ existence establishes customer, sale, payable amount or stock reservation.

`AdminBusiness.php:57` constructs a UNION of sources, retaining separate source identity even when contacts match; `:91`, `:109`, `:115`, `:121` provide dashboard, contacts, reports and declared URL summaries. Preserve those request statistics and labels. Add distinct commerce summaries; do not change their meaning to sales.

## What native reuse really provides

| Upstream surface | Verified behavior | Consequence |
| --- | --- | --- |
| `native:catalog/model/checkout/order.php:92 addOrder(array): int` | Writes header, calls addProduct/addTotal, returns native order ID. No transaction, request key, fingerprint or row lock. | Schema reuse is useful; direct model call is not an atomic/idempotent conversion contract. |
| `:422 addProduct(int,array): int` | Stores native product/master ID, name/model, integer quantity, decimal price/total/tax, reward; options follow through `:531 addOption`. | Reuse native IDs and order line snapshots. Preserve extra source/option/SKU data in a linked immutable offer revision where native fields are insufficient. |
| `:724 addTotal(int,array): int` | Stores extension/code/title/value/sort order. | Totals are explicit agreed values. Arbitrary total extension names can later cause dynamic module loading during native history transitions. |
| `:196 editOrder` | Voids an already confirmed order through history, then replaces order data. | Unsuitable for editing immutable accepted terms; accepted revisions require a new amendment process. |
| `:789 addHistory(int,int,string='',bool=false,bool=false): int` | Reads old status without locking, runs antifraud, coupon/reward confirmation, stock, affiliate and subscription logic, updates status, appends history. No transaction, expected-version check, dedup key or rollback protocol. | Concurrent transitions can double-apply effects; retries after partial failure are unsafe. Do not expose as a generic headless state setter. |
| `:808` / `:842` / `:859` | Enabled fraud modules can change target status; entering processing/complete calls total-module confirm and subtracts product/master/option stock. | Status IDs are business and inventory policy, not presentation-only labels. |
| `:877` / `:934` / `:964` | Completion may credit affiliates/create subscriptions; leaving processing/complete restocks and unconfirms totals; leaving complete suspends subscriptions/removes commissions. | Payment, fulfillment, cancellation and refund cannot be assumed to equal a single native status transition. |
| `native:install/opencart-en-gb.sql:1120` / `:1121` | Installed events attach customer mail and owner alert **before** addHistory. Activity at `:1110` and statistics at `:1129` also run before it. | A failed transaction may already have emitted external effects if called through native loader. `notify=false` does not disable all native mail. |
| `native:catalog/controller/mail/order.php:49` | On old status 0 to nonzero, initial customer mail is sent without requiring notify=true; later update mail requires notify at `:54`. | Never test or enable commercial conversion by invoking native history against live config. |
| `native:admin/model/sale/order.php:49/:650/:791/:983` | Reads native order, products, totals, histories. `:834 getTotalSales` defaults to every status >0 unless filtered. | Can reuse read shapes; do not call that number paid revenue. |
| `native:admin/controller/sale/order.php:1209 call` / `:1302 delete` | Native modify `sale/order` can invoke native store API order calls or delete orders; rewards/commission have separate methods below `:1881`. | CMTEH-managed immutable orders need a server-side mutation boundary, including direct native endpoints, not only hidden links. Existing unrelated native orders must retain their behavior. |

### Native schema limits that affect integration

`native:system/helper/db_schema.php:4307` defines order; `:4782` order_product; `:4714` order_option; `:5018` order_total; `:4663` history. All use InnoDB. Monetary columns are `decimal(15,4)`; currency value is `decimal(15,8)`. Native methods cast money to float; a new adapter should bind validated decimal strings and use integer scaled units/decimal-safe arithmetic, with an explicit rounding rule.

Native customer/order first/last names are 32 characters; email is 96 (`:1823`, `:4363`) and telephone 32 (`:4368`). Existing RFQ allows name 100, email 254, phone 40. Never silently truncate requests or guess surname. Agreed checkout/customer data needs explicit validation or an approved compatibility extension. `native customer` has a normal email index, not verified identity uniqueness; equal email is not account proof.

Native order includes customer_id and contact/address snapshots. Guests use customer_id=0; account linkage uses a verified stable native customer_id provided by the account service, never an email join. Do not create customer accounts from RFQs. Mutable address-book IDs do not replace stored address snapshots.

## Minimal safe commerce boundary after business decisions

Reuse native `order/order_product/order_option/order_total/order_history` as the actual order storage, preserve native product/customer IDs, and keep custom linkage/security/effect metadata additive. Avoid a second independent sales database. A new CMTEH commerce service and native-schema adapter must use **the same PDO transaction**. Do not place native registry model calls on a separate connection inside that transaction.

Proposed interfaces (new, not existing):

```php
OfferService::createRevision(SourceRef $source, OfferTerms $terms, Actor $actor, Command $command): OfferRevision;
OrderService::acceptOffer(string $offerId, int $revision, BuyerProof $buyer, Command $command): OrderResult;
OrderService::transition(int $orderId, int $expectedVersion, string $transition, Actor $actor, Command $command): OrderResult;
NativeOrderAdapter::insert(PDO $transaction, AcceptedTerms $snapshot): int;
PaymentAdapter::createIntent(OrderSnapshot $order, string $idempotencyKey): PaymentInstruction;
PaymentAdapter::verifyWebhook(string $rawBody, array $headers): VerifiedPaymentEvent;
DeliveryAdapter::quote(DeliveryRequest $request): DeliveryQuote;
ReturnService::request(int $orderId, int $orderProductId, int $quantity, BuyerProof $buyer, Command $command): ReturnResult;
```

Terms must include agreed line native/source IDs, SKU/model/title, quantities, selected option IDs plus labels/values, unit/line money, currency/rate snapshot, tax components and inclusive/exclusive meaning, billing/shipping address snapshots, payment/delivery method and charges, totals components, validity/acceptance conditions and version. Never derive historical money from current catalog prices or recalculate an accepted revision when catalog changes. Source RFQ input, original bytes, idempotency fingerprint and mailstate remain unchanged.

Suggested additive tables/indexes, subject to the final spec:

- `cmteh_offer`: immutable revision `(offer_public_id, revision)` unique; source `(source_type, source_public_id, revision)` index; payload/version/hash, actor and timestamps. Draft editing can replace only unaccepted drafts or append revisions; acceptance locks the exact revision.
- `cmteh_order_link`: `order_id` primary/unique, public UUID unique, accepted `(offer_public_id,revision)` unique, source lookup, version, accepted-at, verified customer ID nullable. Exact uniqueness depends on whether split/partial/repeat fulfillment is approved; do not assume one source can only ever produce one order.
- `cmteh_commerce_command`: primary `(scope,request_key)`, fingerprint and immutable result IDs. Claim command and lock accepted offer/order on one connection; equal replay returns same result, different fingerprint 409; validate expected version under lock. Bounded retry for deadlocks only with command replay discipline.
- `cmteh_order_effect`: unique `(order_id,cause_id,effect_kind,target_id)`; records reservation/release/stock/refund/commission causes when enabled. Lock inventory targets in stable order, enforce quantity policy in SQL. A history row alone is not effect deduplication.
- `cmteh_payment_event`: unique `(provider,event_id)`, external transaction identity index, order_id and verified amount/currency/status. Verify signature/raw body and order/provider binding before any state mutation; duplicate callback is replay, wrong amount/currency is rejected. Never trust browser return URL as payment proof.
- `cmteh_commerce_outbox`: unique event identity `(aggregate_type,aggregate_id,event_kind,cause_id)`, due index `(status,available_at,id)`, immutable payload. Enqueue within the state transaction; perform external mail/provider calls after commit with stable idempotency identifiers. No global suppression/disabling of native events on unrelated orders.
- Guest order access: separate hashed random capability/verification token with order binding, expiry, revocation and throttling; no access by order integer, source UUID or matching email alone. Account reads use verified ID relation and per-order authorization. Decide whether guest links can access files; default is no inherited original entitlement.

Protect CMTEH-linked orders from conflicting native edit/delete/history/reward/commission paths through scoped server-side guards/events or route to the same service. Native read-only forms can remain useful with native ACL. A public storefront adapter is needed because `Storefront.php:40` owns allowed routes and rejects arbitrary native controller routing; enabling a native payment extension does not automatically provide checkout, callbacks or headless UI.

Order summaries should report explicit categories: created/accepted orders, outstanding balances, verified captured payments, refunded payments and approved fulfillment statuses. Group money by currency; do not sum RUB/USD or count an offer acceptance as captured revenue. Reconcile from ledger/order data rather than native cache/statistics before events.

### Returns are a separate workflow

`native:admin/model/sale/returns.php:44 addReturn` stores user-supplied order/product/customer/contact/status data; `:410 addHistory` changes return status and adds history. Native return schema (`db_schema.php:6067`) lacks order_product_id and does not establish remaining eligible quantity. Its add/history methods do not provide refund or restock reconciliation. Return mail (`native:admin/controller/mail/returns.php:22`) is event driven and sends when notify=true.

Reuse native return headers/history for accepted commercial purchases plus additive `cmteh_return_link(return_id UNIQUE,order_id,order_product_id,quantity,version,command_key)` and an effect/refund ledger. Lock the purchased line and active returns to prevent returning more than eligible purchased quantity under concurrency; record approve/reject/receipt/refund separately. A status label is not proof that provider funds moved or stock was received. Preserve product snapshots even if the catalog product is deleted. Business decisions still needed: who can request, reasons/time limits, partial returns, opened goods, receipt inspection, restock point, refund amount and shipping/tax treatment, payout/provider workflow.

## CRM can precede checkout without changing RFQ semantics

New service suggestions:

```php
RequestCrm::get(SourceRef $source, Actor $actor): CaseView;
RequestCrm::change(SourceRef $source, int $expectedVersion, CrmChange $change, Actor $actor, Command $command): CaseView;
RequestCrm::remind(SourceRef $source, ReminderInput $input, Actor $actor, Command $command): Reminder;
RequestCrm::inbox(Actor $actor, InboxFilter $filter, Page $page): InboxPage;
```

`cmteh_crm_case` should key `(source_type,source_public_id)`, with assigned native user_id nullable, stage_id nullable, version, updated_at and indexes `(assigned_user_id,stage_id,updated_at,source_type,source_public_id)` / `(stage_id,updated_at,...)`. Resolve source existence; do not mutate RFQ rows to add metadata. Missing CRM metadata remains unassigned/unconfigured, never a guessed sale stage. Lazy creation occurs only on authorized writes, not read/migration backfill by contact.

`cmteh_crm_stage` has stable machine code unique, labels/order/active flag and agreed transition policy. New stages are owner/authorized-admin configuration; do not invent New/In progress/Won/Lost or repurpose mail/order status IDs. Removing an in-use stage needs archive/mapping policy. `cmteh_crm_event` is append-only: event_id primary, case identity/time index, actor ID plus non-sensitive actor label snapshot, previous/next values, cause/command unique. Audit stage/owner/reminder changes atomically with versioned case mutation; no private original bytes or secrets in audit.

`cmteh_crm_reminder`: id, case reference, recipient_user_id, due_at UTC, state, version, creator and optional bounded message; due index `(recipient_user_id,state,due_at,id)`. Assignment does not grant source-read rights. Disabled/deleted users must not receive notifications; reassignment, snooze/completion/cancel and overdue policy need decisions. `cmteh_manager_notification` can separately key `(recipient_user_id,event_id)` with read_at nullable, unread index `(recipient_user_id,read_at,id)`. Mailstate never means manager read/unread.

ACL seam is `admin/controller/module/cmteh_leads.php:22`: existing session, exact user_token and access are checked at `:25-30`; quote POST modification separately requires modify at `:174`. Use the same checks for CRM read/write endpoints and current native ACL groups, or explicit additional CRM routes with no auto-grants. Choosing row ownership restrictions is a protected authorization decision: current access reads every source and private original. Do not silently restrict existing readers or interpret assignment as new access. Clarify all-allowed-reader versus assigned/team-only visibility, who can assign whom, stage administration and audit visibility. Targets for assignment must be enabled native users with source access; validate on server under the chosen policy, not only dropdown filtering.

UI scope: case owner/stage/history/reminders in Lead and Quote detail, server-side list filters and pagination, personal due/inbox count. Existing `cmteh_business.twig`, `cmteh_leads.twig`, `cmteh_quotes.twig` should consume explicit extra models; escape text at Twig boundary. Do not merge request report mail-status filters into CRM stage filters. A local due inbox can work without external cron/mail; targeted mail needs an explicitly selected notification policy and transactional outbox.

## Conditional catalog facets, options and identifiers

- Existing `Catalog.php:336 productFilters` supports brand, availability, partType and category membership; `Catalog.php:87 checkList` is ordered **exact SKU** lookup. Preserve these semantics/URLs and search rank (`:69`). `CatalogSchema.php:35-41` already has SKU/MPN/publication/brand/category/facet indexes.
- Native filter tables (`native:db_schema.php:3183`, product_filter `:5494`) are not connected to these headless predicates. A facet feature requires approved allowlisted facets, native IDs/mapping, category scope, OR-within/AND-between semantics, empty-value behavior, counts under active filters, canonical URL representation and import/update/reindex rules. Reuse native filter identity and add lookup index `(filter_id,product_id)` if the actual schema lacks an appropriate reverse index; determine from EXPLAIN, not assumption. No product read may publish or materialize missing mappings.
- Native options (`db_schema.php:4167`, product_option_value `:5618`) include price/stock/weight adjustments, whereas current request has only article/quantity/optional product_id. Agree whether an option identifies another SKU/native variant or configuration of one product. Add selection identity to cart request snapshots and normalized idempotency fingerprint compatibly, validate product-option membership, availability and required options on server. Include immutable selection in accepted offer/order_option. Native IDs alone do not preserve historical human meaning. Legacy stored `smtechno-request` rows must remain readable.
- `NativeCatalog.php:183` reads only product_code `sku`/`mpn`, with model fallback for SKU. Native identifiers (`db_schema.php:3391`) define arbitrary codes/validation and product_code (`:5301`) holds values; merely exposing the editor does not add search. New codes need display/search/import/uniqueness/normalization policy. Use additive `(identifier_code,value_norm,product_id)` lookup if approved, preserve stable native product IDs, and retain exact-SKU checkList behavior instead of widening it silently. Duplicate alternate codes need explicit ambiguity handling.
- Recurring subscription plans, paid downloads and review/comment moderation are independent conditional catalog consumers; they are not supplied by facets/options or guest checkout. Preserve them in the overall requirements map and plan separate entitlement/recurrence/moderation work. No automatic activation of subscription creation/native mail events.

## Verification seams and meaningful affected checks

Available documented dependency setup: PHP 8.3/MySQL 8.4/Apache in Docker Compose (`opencart/dev/README.md`), `opencart/dev/runtime.py` supports ready-runtime refresh/exec, and root Playwright/Node supplies Chromium. No Docker/runtime commands were run in this discovery. `opencart/bin/verify-local:66-68` runs Lead, Quote and AdminBusiness suites. `opencart/dev/release_checks.py:40` hashes installed suite dependencies; update actual dependency lists if adding installed checksets so evidence corresponds to shipping code. Strict generic verifier must not be downgraded.

Existing reusable meaningful tests:

- `tests/lead-integration.php:48 race` uses concurrent PHP processes and real MySQL locks; adapt this approach for offer acceptance/state and quantity races.
- `tests/quote-integration.php:3` accepts isolated test config, `:24` supports commit-failure injection, `:34/:142` real concurrent submission; useful templates for transaction failure/replay.
- `tests/admin-business-integration.php:10-18` uses random isolated table prefix and hashes native customer/order/affiliate plus source tables to prove read nonmutation; `:43` checks unchanged fingerprints/originals. Extend for CRM reads/writes preserving source snapshots/outboxes.
- `dev/admin_business_checks.py:18` plus `tests/admin-business-fixture.php` provide synthetic native users and browser/session integration; existing `release_checks.py:386` proves migration deny for operators.

Required new scenarios, bounded by implemented phase: CRM access/modify denials including direct POST/invalid token; stale versions and idempotent duplicate actions; valid/invalid/inactive assignee; mailbox recipient isolation; stage filter counts; source bytes/fingerprint/mailstate unchanged; repeated migration does not grant permissions. Commerce: same offer accepted concurrently gives one native order and consistent result; key conflict 409; injected mid-write failure leaves no native header/line/total/link/effect; catalog edit does not change accepted terms; guest/account IDOR; exact currency/tax rounding boundaries; duplicate/out-of-order verified payment callbacks; stock/cancel and partial-return races; refunds reconcile exactly once at provider boundary; unsafe direct native mutation cannot bypass service. One short browser path per critical new journey is sufficient; no viewport matrix required for backend phases.

## Business decisions remaining before writes

1. Is the next sale a manager-issued offer accepted by the customer, a manual manager-created order, direct fixed-price checkout, or multiple approved paths? Can one RFQ split into/repeat orders? Offer validity, revision/amendment and evidence of acceptance?
2. Currency, allowed price source and edit rights, exact decimal scale/rounding, tax regime and included/excluded prices, billing identity, address/zone needs. Current product price display is not a tax/agreement contract.
3. Payment provider(s)/offline methods, authorization versus capture, webhook verification, cancellations/refunds; delivery service, zones, costs, fulfillment tracking; reservation/subtraction/release points and oversell/backorder policy. Native status IDs cannot answer these.
4. Guest proof, account association/claim rules and private order/file scope; historical RFQ ownership cannot be inferred from contacts.
5. CRM stage codes/transitions, role/ownership visibility, assignment rights, reminders/notification recipients/channel/read state; retention/audit policy.
6. Return eligibility/window, partial quantities, reasons/decisions, receipt/restock, refund/tax/shipping effects.
7. Which specific native filters/options/additional identifiers have real customer-facing consumers? Their semantics and URL/import compatibility.

These are owner product/authorization decisions. No prices, taxes, payment method, delivery procedure or stage set has been assumed here. Research/code scaffolding can continue, but activating transactional commerce or new visibility rules before answers would invent requirements.

## Rough changed LOC and sequencing

Estimates include new production PHP/templates/JS plus focused integration tests and documentation, exclude vendor/core extraction/generated fixtures. They describe a minimal complete implementation after decisions, not a promise of a fixed diff.

| Core path | Approx changed LOC | Main surface |
| --- | ---: | --- |
| CRM case/stage/audit schema + service | 700–1,150 | new system service/schema; migration seam `cmteh.php:202` |
| CRM controller/templates/list filter | 450–800 | `cmteh_leads.php`, Lead/Quote/business templates |
| Reminder/personal inbox/read state | 600–1,050 | service/schema/controller/header, optional outbox worker |
| CRM integration/browser/migration evidence | 450–700 | PHP native ACL + real DB race + short browser |
| Agreed offers + atomic native order adapter + command ledger | 1,700–2,700 | new commerce schema/services; existing source records read-only |
| Order manager/customer/guest views + histories/stats | 1,000–1,700 | new admin/public routes/templates; scoped native mutation guard |
| Payment/delivery adapters and callback/effect/outbox contracts | 1,400–2,600 | provider-specific plus shared reconciliation; costs vary by selected providers |
| Returns linkage/quantity/refund/receipt workflow | 850–1,450 | native return adapter plus ledger/admin/public views |
| Commerce integration/ACL/race/short E2E/recovery evidence | 1,200–2,000 | real MySQL/native runtime, no external live mail/payment |
| Conditional facets | 550–950 | Catalog filters/counts/mapping/import/UI/tests |
| Conditional options | 750–1,300 | catalog/cart/request/offer/native order snapshots/tests |
| Conditional identifiers | 300–550 | normalization/import/search/read-model/tests |
| Total this discovery scope | **9,950–16,950** | excludes full account system, newsletters, discounts/affiliate, analytics, GDPR and other conditional modules |

The entire capabilities report additionally requires account verification/recovery/history claims, marketing consent/double opt-in/suppression, coupon/affiliate financial rules, analytics attribution/retention and potentially recurring billing/entitlements/reviews/multilingual/branches. It is not defensible to promise the full inventory below ~36k changed LOC; this scope alone can approach 17k, and real provider/recurrence/entitlement requirements can push the whole beyond that ceiling. Final total must combine other scouts and owner answers rather than trim the inventory.

Suggested complete sequence preserving all requirements: (A) CRM ownership/stages/audit/reminders + optional approved facets/identifiers, (B) account/guest proof and agreed commercial offers/native orders with immutable totals/history/ACL, (C) selected payment/delivery/returns with reconciliation plus coupons/affiliate after price rules, (D) marketing consent and analytics, (E) explicitly selected conditional catalog/CMS/recurrence/paid-download/moderation/multilingual/branch consumers. Runs B/C share contracts agreed before coding; guest ordering does not require accounts. Split a run further when estimate exceeds its budget. Keep an explicit requirements-to-run ledger so a conditional feature is pending/declined by owner rather than silently omitted. Every publication retains existing backup/recovery/native-access safeguards and source-corresponding checks.
