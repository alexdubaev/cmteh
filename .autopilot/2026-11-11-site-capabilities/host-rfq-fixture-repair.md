# Host RFQ fixture SQL failure

ActualhostPHP8.2/MySQL5.7 NONSTRICT: core45/import30/native24 PASS; warehouseoffers11PASS then SQL snapshot failure assertion fails. Suspected same missingNOTNULLfield injection as fixedcore4b955aa; establishexactcausebeforefix. Product/19Opshelpers/immutablearchive126files unchanged. No appdeployment yet, no auth/SQLglobal changes.

Allowed: opencart/tests/warehouse-offers-integration.php and thisprose only. Preserve first-header/items/outbox/warehouse-snapshot rollback assertion; deterministic realInnoDB FK failure againstexistingownedparent, no newtables/triggerprivilege/runtimeguardchanges. For faultproofonly setisolatedPDOsessionNONSTRICT andrestoreoriginalmode in nestedfinally; requireFKchecks1/parent0absent, cleanupFK+column. API safe503 mayhidePDOdetails; don'tweakenexpected503/count/replay invariants.

Read-only scan otheraccepted four suites+recovery for same strict-dependent injection toavoidserialrediscovery; don'teditoutsidezone/reportactualcouplingonly. Affectedcheck only reallocalMySQL5.7 warehouse18 suite with installedclass/rawtestsourcehashes/Reflection/fixturecleanup; no wholeDBmatrix/strict/browserrepeat. Rootfreshnarrowreview, autonomousverifiedPRmerge, thenexactprivatefixtureproofplan refresh underclosedGO withpublishedsourceSHA. Rootaloneactualhost; no workerhostcalls/writes. ApplicationextensionGit tree2140d709e3a1a004a72db316d2f6a8be5d02a33f mustremainunchanged.

## Cause and bounded repair

Confirmed locally: with only the faultPDO temporarily set NONSTRICT, the original missing-NOTNULL snapshot column is coerced instead of rejecting the INSERT. Exactly11checks PASS, then the original expected503/rollback assertion fails. This reproduces the actual-host stop without changing host/global settings.

The repaired fault adds a nullable BIGINT UNSIGNED column referencing the existing owned Lead PK; old rows remain NULL, then future omitted values default0. Setup requires foreign_key_checks1 and absent parent0. The real Lead API catches the resulting PDOException and returns safe503. Existing header/outbox rollback assertions remain; itemcount3 and warehouse-snapshotcount2 are now explicit too. The following unchanged concurrent retry of the same fresh key succeeds once, preserving replay semantics. Nestedfinally removes FK/column and restores the original faultPDO mode. No new tables, triggers, product changes, helper changes or assertion weakening.

## Actual affected check

`docker exec cmteh-oc-6c6f360e781b-web-1 php /tmp/cmteh-rfq-host-fault-9481/tests/warehouse-offers-integration.php`:18PASS/exit0, MySQL5.7.44/PHP8.3.36. The temporary source tree contains all126 exact accepted candidate application bytes (outera8520763/nestedb78dbe14), plus the exact changed test; it is not represented as a new installed-artifact/actual-host proof. Twelve loaded classes/traits were checked by Reflection/rawSHA against that archive. TestSHA14366452b09b259bd287e7fbeb493281113d5c2930c199d85ceb02778a130cc9. Remaining wof_ tables0; freshconnection/defaultglobal modes remain equal. PHP lint and gitdiffcheck PASS. Receipt D:/codex/temp/cmteh-rfq-host-fault-fixture-20261111-evidence.json.

Read-only coupling scan: core's prior4b955aa deterministicFK repair is present; import/native/recovery contain no analogous ALTER ADD missing-NOTNULL SQL-fault injection. Native's later before-event RuntimeException and import's optimistic-version rollback are explicit different boundaries and need no changes. No other files were edited or suites rerun.

The application extension tree remains2140d709e3a1a004a72db316d2f6a8be5d02a33f. Actual-host retry/publication and root's additional provenance binding remain pending; no host, mail, ACL or vendor-bootstrap calls were made by this executor.
