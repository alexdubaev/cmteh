# 06 — Подготовка безопасной проверки и выкладки на хостинг

**Требования:** G01, G05, G07, G09
**Зависит от:** 02, 03, 04
**Зона:** `D:/codex/temp/cmteh-warehouse-ops-20261111/`
**Волна:** 3
**Ревью:** нет — единая независимая проверка product+ops перед исполнением
**Модель:** сильная

## Что должно заработать

Вне репозитория подготовлены root-controlled helpers для freshprotectedbackup/rehearsal/actualPHP8.2-ownprefix проверки, безопасногоfileexchange+точногоadditiveeventregistration+emptyinventorymigration иdata-compatible recovery. Наэтойстадии НИКАКОЙфактическойhostмутации/выкладки; толькоhelpercode/read-onlypreflight/локальныенегативныепроверки. Rootпослеreview+exactcandidate сам sealsGO/executes.

## Из брифа, дословно

> «Да нам нужны склады, остальное оставим на будущее»
> «Нужна загрузка из Excel/CSV»
> «Да, ручные резервы (рекомендую)»

## Разделы спецификации

История21 и существующиеrelease/host/recovery safeguards; interfaces targetverification/rawartifactfacts; target-verification-plan.md. Это разбиение прежде root-ownedops, не новыйпродукт/бизнесрешение.

## Критерии приёмки

- [ ] Targetexacttest.cmteh.ru; чужиеproduction/engine/StoreLand/DNS/cron/usergroups/privateconfigs не меняются. Aliasengine-cmteh-local толькотранспорт. Никакихсекретоввsource/logs/CLIargs; allGOflagsclosed поумолчанию. Newtasknonce/dir/seal, прежниеGOclosed неиспользуются.
- [ ] Reusepreviousverifiedbackup/rehearsal/fileexchange mechanics, freshDB/public/private/config/cron exactprotectedcopies+hash/size checks andownprefix restoreproof. No destructiveglobalDBrestore, no broadpatterncleanup; safeownershipledger beforecommit. ExistingSQLliteralparserfailclosed/strictmanifest/UTF8canonicalhash unchangedsafety.
- [ ] CurrentPHP8.2/MySQL5.7 same-host isolatedprefix syntheticchecks feasible withcandidateinstalledhashes/provenance; ideally private127.0.0.1app+SSHbrowserfixture ifsafelysupported; no publictestwarehouses/prices/customeremail, no newprivilegedrealaccount/authdowngrade. Targetreadiness/constraints documentedhonestly.
- [ ] Exact12newinventoryevents canonicalactions/sort/status planned, existing67rows+IDs/ACL preserved. Existingwholemoduleupgrade mayrewriteold64 soactualhostexactadditiveonly; candidatecode/effectiveschema orderingpreventsmanagedactivationbeforeguards, usingobservedbaselineinventoryabsent. Rejectunexpectedexistinginventorydata/incompatibleoldbinaryrollback.
- [ ] Allmutations require rootsealedexactcandidateSHA/action/paths/prefix/approvedbaseline; failclosedwronghash/wrongaction/foreignpath/unknownexistingevent/unsafeDatarollback tests. NoexecuteuntilrootexplicitGO. Reporthelperhashes/directory andexactremainingpreconditions, noNOTRUNasPASS.

## Existing evidence

Previousresult D:/codex/_handoffs/cmteh-admin-unification-result-2026-11-11.md andoldops D:/codex/temp/cmteh-admin-unification-20261111 (referenceonly; oldGOallfalse). Targetroot/privatebase andSSHconfiginhosting-readiness.md; no needreadsecrets. HostPython3.6.8-compatiblehelpers (no dictunion/3.7APIs), PHP8.2.35/MySQL5.7.44-48; nativecore5rawhashesmatchpin. Allnewdomain/sourceclasses inparentcommits; finalimmutableZIPnotyetbuilt, rootwillprovidelater sealedmetadata.
