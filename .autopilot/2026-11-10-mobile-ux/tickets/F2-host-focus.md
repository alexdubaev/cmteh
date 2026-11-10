# F2: возврат фокуса после невалидной короткой формы

**Требования:** G01, R10, R11, R14i, D02
**Зависит от:** —
**Зона:** `opencart/extension/cmteh/catalog/view/assets/storefront.mjs` · `opencart/extension/cmteh/catalog/view/assets/responsive-ux.css` · `opencart/extension/cmteh/catalog/view/assets/request.mjs` · `opencart/extension/cmteh/catalog/view/assets/request-import.mjs` · `opencart/extension/cmteh/catalog/view/template/storefront/layout.twig` · `opencart/tests/browser/storefront.mjs`
**Волна:** 3
**Ревью:** нет
**Модель:** обычная

## Требования
G01, R10, R11, R14i — существующая отдельная короткая форма и доступная мобильная навигация.

## Сценарий
На390×664 открыть quick диалог нижней ссылкой «Запрос», нажать пустой submit, закрыть Escape/крестиком. Диалог закрыт, нижняя панель снова доступна, фокус у видимого opener. На закрытом диалоге скрытый input не должен оставаться activeElement. Проверить реальное действие без зависимости от fullpage screenshot.

## Зона
Только storefront.mjs/responsive-ux.css по подтверждённой причине, короткий регрессионный сценарий storefront.mjs test; согласованное обновление версии всех4модулей/CSS и XLSX reader, если JS изменился. Backend/CMS/schema/auth/данные/зависимости не менять. Сохранить F1 readiness, observer aggregation и все старые формы.

## Приёмка
Причина до фикса; meaningful RED→GREEN для описанного сценария. Весь затронутый browser файл зелёный; без второго полного ревью. Lead выпускает новый точный пакет через strict verifier и merge, затем host closure. Реальные отправки/SMTP и host deploy исполнителю запрещены; этим владеет отдельный operational agent.

## Выполнение
Код в D:/codex/worktrees/cmteh-mobile-ux-focus-20261110, веткаcodex/mobile-ux-focus-20261110 от f4dd2d5. Интерфейсы и executor prompt передаются абсолютными путями. Текущий собственный dev3176 можно безопасно refresh из нового checkout. Пакет финального verifier проверяется отдельно. Артефакты вне Git в D:/codex/temp/cmteh-mobile-ux-20261110/.

Ревью: нет — новый функциональный RED закрывается meaningful regression, существующий whole review и closure уже пройдены.
