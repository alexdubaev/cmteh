# Приёмка V2 — факты

## До реализации

- V2 checkout создан от принятого main9edb5fa; поисковая версия и runtime ещё отсутствовали.
- SHA256 cached OpenCart4.1.0.4 ZIP соответствует lock: cdef537f5a0a7e1b4ddbe16f589dd2e80b10150a09bfbe93ce76ef2d5c3218c0.
- V1 source D:/codex/cmteh: main9edb5fa, clean. Никаких действий на хосте или подключения real DB в сборке нет.
- Первый старт: стандартные Docker pools исчерпаны; исполнитель проверил сети/маршруты и использовал штатный CMTEH_DOCKER_SUBNET со свободной 10.231.93.0/28 для собственного project. Чужие сети/контейнеры не удалялись.

## Целевые пользовательские проверки

| Сценарий | Результат |
|---|---|
| Главная1440: поиск/чертёж/логотипы/компактная ссылка | PASS, home-desktop-final.png1440x1080, body fits1080; matched source/render1043x788 compared in one image |
| Главная1024 и390: всё доступно, нет горизонтального overflow | PASS, screenshots + DOM geometry; mobile inner390, scrollWidth375 |
| Ввод артикула → подсказка → карточка | PASS, RE-568158 → ArrowDown/Enter → /product/jd-re568158 |
| Неизвестный артикул → пустой результат | PASS, ZZZ-NO-SUCH-PART-V2 → Найдено0, понятный текст и путь подбора |
| Складское предложение → корзина с выбором склада | PASS, Synthetic warehouse B, 850.25RUB, 5days, количество увеличено до2 |
| Отправка локальной тестовой заявки | PASS, accepted local lead079c020f-7542-4747-aa6f-0fcbb6b63f78, корзина опустела |
| Текстовый список: ведущие нули/количество/неизвестныйSKU | PASS, 0000550380;2 и RE568158;3 сохранены; третья неизвестная строка показана отдельно; автоматически в корзину не добавлено |
| ФайлCSV: проверка и отправка на расценку | PASS, собственный local-parts.csv выбран штатным диалогом пользовательского Yandex;2строки; local quote64bd8783-c968-4fe2-a552-9eb5d54b60ad принят |
| Бренды/категории/информационные страницы | PASS,24HTTP route/API/assets checks; browser brandCaterpillar+Delivery; mobile menu and quick dialog open/close |
| Карточка без фото и длинные значения | PASS, title160+computedbrand/SKU52, no photo, on-request;390px wraps, add/cart/remove works. Own stress fixture unpublished after verification, data not deleted |
| Console errors | PASS, [] in actual user browser; final brokenImages[] |
| Повторный запуск без потери данных | PASS,17products/2warehouses and exact identities preserved; own runtime evidence/v2-repeat-start.json |
| Default V1 package/source неизменны | PASS,126exactfilepackageSHA b78dbe14f9132c7a6a4164a1eb79d665fcd9f3488064b09b3f470e59c5dae442; protectedsource unchanged |

## Визуальная доводка

- Initialmatched comparison: иконка поиска имела inheritedpadding19px и выглядела точкой; исправлено padding0.
- Фон был сильнее ориентира; снижена opacity до .055desktop/.045mobile.
- Footer был ниже viewport из-за inheritedmargin50px и лишней высоты; компактный footer/main исправлены, matched1043x788 и desktop1440x1080 помещаются целиком.
- Использованы горизонтальные JohnDeere/CNH, оптически выровнены CAT/CNH/Deere; кнопка поиска radius16.
- Finalmatchedcomparison evidence/compare-home-matched-final.png просмотрен вместе с source/render в одном кадре; основных P0/P1/P2 различий главной не осталось. Действующие фильтры каталога и рабочие страницы переиспользованы; не заявляем их pixel-identical с маленькими иллюстрациями board.
- NativeCSVupload использован после отказа browser-extension filechooser из-за отсутствующего fileURLpermission. Разрешения браузера не менялись, прочие пользовательские файлы не выбирались.
- Preview оставлен в пользовательском браузере http://127.0.0.1:3162/, временный viewport reset. Собственная дополнительная mobile tab закрыта, V1 не тронута.

## Ограничения

Это локальная визуальная/функциональная приёмка на synthetic fixtures. Настоящие письма не отправляются. Серверный релиз, host/recovery gate и подключение выбранного дизайна к рабочей админке не входят в текущий локальный запуск.

## Финальная синхронизация — актуальный baseline

Предыдущие126filepackage proofs выше относятся к T01/baseline9edb5fa. Внешняя параллельная работа PR26 изменила origin/main до8a5ada0 и обновила V1 checkout. Наш прогон не писал в V1: синхронизация сделана только в своей ветке/V2 runtime. T02 добавил3строки совместимости нового общего head contract, без изменения default V1 source относительно актуального origin/main.

Текущий default package127files, SHA bcc029517fa3419682f2ca9cd1d6b6a0692085a44d6ad64213edf8e407933ac4. Evidence: evidence/v2-head-sync.json.5safety+24HTTP/API/assets PASS;2затронутыхexistingPHP/MySQLsuites155PASSlines; syntheticheadregression и exactsettingsrestore/defaultOFF PASS. Body/CSS hashes идентичны до/после, поэтому прежние визуальные captures соответствуют текущему visual source.

Doosan source SVG whitespace-only normalization после T02: XMLcontent unchanged, новый asset GET совпадает с source SHA14fd0574a6797879fb6a74f17d3e3e464e1861f1fb33d2b0ee088dcca5d80b20; исходный URL/hash сохранены в attribution. Никакого visual/semantic изменения, default package не затронут. Productsource ddb5f0d. Финальный G4 проверяет этот source отдельно от первоначального41773a3.

Итоговый G4 PASS: fresh clone/fresh DB на3163, finalsource ddb5f0d,5safety+24HTTP/API/assets+127exactdefaultpackage+3realMySQLsuites; userChromeCUA search→product→cart/qty→acceptedlocallead, exactlist, email-onlyquote,2warehouses→2cartlines, brands/delivery. G4 browser fileupload/mobile не повторялись; эти сценарии реально проверены лидом ранее и соответствуют неизменным body/CSS/business JS. Productionadminbinding/deploy NOTRUN по scope. Свои3163контейнеры остановлены без удаленияvolumes,3162оставленrunning. Полный verdict — G4-final.md.
