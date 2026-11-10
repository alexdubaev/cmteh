# F1 — Полностью видимое поле после возврата к списку

**Требования:** R03, R09, R11, R12i, R13i, D01
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/catalog/view/` · `opencart/tests/browser/storefront.mjs` · `DESIGN.md`
**Волна:** 2
**Ревью:** нет
**Модель:** обычная

## Что должно заработать

Только узкое host-найденное presentationисправление после основнойпринятойпереработки. «Изменить список» должен показывать всёполередактирования, а не толькоcaret/последниестроки. Пользователь: «то что должен видеть где то внизу и половина видна», «удобный и продуманный интерфейс всего сайта».

## Фактический RED и критерии

- [ ] WebKit390×664: initial→paste3rows→Check→Изменитьсписок; сейчасfocusedtextarea top=-39.91,bottom51.66 после3сек. Draft/cartсохранены, но верхобрезан. Исправитьfocus+явныйpredictablescroll такчтовсёполеVisible, безmanualscroll, сrespectreducedmotion иучётомреальныхfixedbars. Chromiumтожепроверить, focusостаетсянаполе, текст/количества/legacycartнеизменны.
- [ ] ОсновнойCheck→result/offer по-прежнемувиден; quote-reveal/validationfocus/linkfallback сохранены. Проблемунеисправлятьуменьшениемtextarea/font/маскировкойinputилирасширениемviewport.
- [ ] Согласованныйcachegraph20261110-8; sourcebusiness/backend/DB/ACL/API/storage/payload/idempotency/limitsunchanged. Обновитькороткуюexistingbrowservisibilityрегрессиюдляedit, meaningfulRED может бытьреальнымWebKithostproof+локальнымreproducer. Не делатьpermanentfullbrowsermatrix.
- [ ] FocusedlocalChromium+WebKitonnewfrozenfix, existingaffectedbrowser14, rootrequiredstrictnewexactartifact. Whole-reviewиG4неповторяются дляизолированногоfocus/versionfix; исходнаяclean5cприёмкасохраняется сnarrowclosureнаF1.
- [ ] D01: передstrictупаковкойсохранитьточные5protectedCRLFbytesизacceptedv7R2archive, сохранивGitcanonical инеизменяяpackager/verifier/guard. Послеartifacttest вернутьсобственныйphysicaloverlayизbackup. СтарыйR1guardSTOPсохранён.

## Границы и запуск

Спецификация/interfaces текущейосновнойсборки. Новыйimplementationworktree D:/codex/worktrees/cmteh-ux-edit-focus-20261110 branchcodex/site-ux-webkit-edit-focus-20261110 отmainc246. Изменениятольковpresentation/browser. Rootmetadataвдругомworktreeнетрогать. ДоcommitfixR13pending. Hostv7остаётсяработать, rootпроверяет/мержитF1PRзатемминимальныйfrontenddeployбезnativeupgrade/migration/очередейпочты.
