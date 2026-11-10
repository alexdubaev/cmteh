# F1 — Два подтверждённых исправления по код-ревью

**Требования:** R07, G01
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/catalog/view/template/storefront/lead-form.twig` · `opencart/extension/cmteh/catalog/view/assets/request.mjs` · `opencart/extension/cmteh/catalog/view/assets/storefront.mjs` · `opencart/tests/browser/storefront.mjs`
**Волна:** 2
**Ревью:** да — ремонт ветки
**Модель:** сильная

## Что должно заработать

Закрыть только два подтверждённых сбоя. Верстка/палитра/hero/CMS/API вне этой правки.

## Критерии приёмки — находки дословно

- [ ] FIX NOW: Spec · `opencart/extension/cmteh/catalog/view/template/storefront/lead-form.twig:16` · G01.1 требует «Пока lead module не готов — явное сообщение и disabled submit». При блокировке `request.mjs` quick dialog открывается с недоступной кнопкой без объяснения. Нужен видимый статус подготовки/недоступности, скрываемый после успешной инициализации. Fallback `/contacts` уже присутствует.
- [ ] FIX NOW: Craft + Spec · `opencart/extension/cmteh/catalog/view/assets/storefront.mjs:161` · `IntersectionObserver.entries` содержит изменения, а не состояние всех наблюдаемых кнопок. Закрытие quick dialog сбрасывает `submitVisible`, даже когда обычный submit остаётся видимым. Подтверждено на `/contacts`, 390×664: после открытия dialog, прокрутки его submit и Escape «Наверх» занимает x284–378/y538–586 поверх submit x29–361/y540–588. Требование R07: не закрывать submit. Видимость следует учитывать отдельно для каждого target.

## Проверка

Минимальные новые browser regressions должны быть RED на3828b38, затем GREEN. Полный уже пройденный backend21 не повторять для чистой UI-поправки. Существующий storefront5сценариев должен остаться зелёным. Root выполнит полный generic release gate на окончательном стабильном исходнике. Не ослаблять проверки/не скрывать отсутствие инфраструктуры. Не запускать дополнительные visual polish rounds; проверить только сообщения и фактическое отсутствие перекрытия.
