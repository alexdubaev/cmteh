# V2: убрать рывок меню — 2026-11-16

Сценарий: «Каталог» появляется дёргано. Исправить появление и переключение без изменения ссылок/данных/V1.

Причина подтверждена в owner browser: WAAPI fade запускался из отложенного native toggle; opacity панели сначала1, затем0.642→0.949→1. До начала fade панель успевала полностью показаться. Открытие перенесено в CSS `[open].v2-menu-motion` с fill:both280ms, который применяется до первой отрисовки. После исправления серия0→0.586→0.786→0.919→0.960→0.990→1, без обратного скачка.

Смена групп: прежний список теперь затухает160ms, новый проявляется220ms без horizontal slide/delay; размер и indicator меняются300ms с более спокойной кривой. Outgoing copy временный, inert/aria-hidden/без id/role/aria-labelledby, pointer-events:none; после finish/cancel удаляется. Keyboard/reduced-motion сразу показывают конечное состояние. Native details/Escape/outside и все URL сохранены.

Фактические checks:

- PASS JS syntax/diff, affected V2 HTTP checkset5 safety/28 HTTP/API/assets/127 exact default V1 package unchanged. Полные backend/reference Next.js suites не перезапускались.
- Owner browser: native pointer open до/после измерен покадрово; теперь opacity монотонно растёт. Tab crossfade содержит только визуальный inert/aria-hidden outgoing, duplicate IDs отсутствуют. Natural finish, быстрые переключения, клавиатурная отмена и resize удаляют copy/temporary sizing. Финально copies0/is-switching=false, 9 category/12 brand links.
- Keyboard ArrowLeft/Escape/Enter: открытие без animation, opacity1, фокус/selected корректны. Mobile390px: panel right374, copy inert attr присутствует, родитель не выходит за экран; после перехода и reset всё очищено. Native888 viewport восстановлен. Reduced-motion guard проверен статически, OS preference не менялась.
- Открытое меню сохранено владельцу, данных/корзины/заявок не меняли. Бounded known fix выполнен одним исполнителем без повторного формального review.

Evidence вне repo: `D:/codex/CMTEH.RU/.playwright-mcp/v2-catalog-smooth-evidence.json`, `v2-catalog-smooth-demo.gif` из реальных кадров с паузами, final/mobile JPEG.

Начало21:02:52 MSK, fix и HTTP PASS21:06:50; далее browser closure/запись и публикация. Итог с merge/cleanup и server-only refresh (~1s) — в versions.json; hosting deploy отсутствует.
