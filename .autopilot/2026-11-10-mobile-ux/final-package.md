# Конечный пакет после host-дефекта F2

Продуктовый исходник f5a61e94120c9f580904e1bd2b305ac48594c0b1, PR11, main8ff70dd85e11e904e37f74b8ad74831f12b8d4fd. Строгий r3 verifier завершился exit0:104PASS/0FAIL. Browser файл8/8; до F2 было7. Backend и зависимости не менялись.

F2 RED без скриншотов: native empty submit→Escape не возвращал фокус opener. Причина: скрытая нижняя панель отклоняла focus, а visibility transitions задерживали доступность даже после удаления класса; reduced-motion override распространялся и на ссылку. Исправление восстанавливает видимость перед фокусом, сохраняет обычное скрытие и исключает visibility transitions при reduced motion. Targeted Chromium/WebKit normal/reduced motion × Escape/крестик подтвердили закрытие, фокус opener, видимость панели и фактическое повторное открытие.

Именно этот пакет заменяет r2 в окончательном deploy:

- D:/codex/temp/cmteh-mobile-ux-final-runtime-20261110-r3/artifacts/cmteh-install.zip
- ZIP SHA256 e2f0183e200668409cb7ab1d059cc9b0a3c12c605f620db1f5d62e2930275593
- nested extension SHA256 ea4af2f62383707b199fdd852871c6fd4220e84d87a125f741812911344e4d7f
- upstream SHA256 cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0
- metadata D:/codex/temp/cmteh-mobile-ux-final-runtime-20261110-r3/evidence/release.json
- sanitized log D:/codex/temp/cmteh-mobile-ux-20261110/full-gate-r3.log

Прежний r2 локальный PASS и слепая приёмка остаются историческим доказательством основных требований на3c41855. Узкое исправление F2 проверено дополнительным regression и конечным strict package. Результат отдельного host closure фиксируется в host-report.md; вторую целую слепую приёмку или whole-code-review не запускали. В metadata-PR продукт не меняется, повтор app checks не нужен.
