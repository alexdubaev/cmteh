# Независимое ревью и узкое закрытие

Один fresh whole-branch reviewer проверил продуктовый diff12366ed..8e7dcd3. Подтверждены два fix-now: canonical source_id до160 не помещался в128-ограничение RFQ; при deniedsessionStoragewrites повтор после потерянного ответа получал новый ключ. Третья report-находка: pricecache identity не включалаproductId, из-за чего смена предложения с теми жеSKU/brand оставляла spinner. Lead явно сообщил владельцу стоимость одной дополнительной проверки и включил её в тот же boundedF1.

F1 3441135dc2ae217bdc024f43cb247e467d2190a6: шесть файлов, новых возможностей нет. Canonicalvalidator повторяет CatalogInput::text/160characterсемантику, схема3 безопасно расширяет установленную колонку128→160. Tabmemory авторитетнее sessionStorage; priceidentity включаетcanonicalid. Root refreshed полный installedsource/nativeupgrade, затем35realPDOLeadassertions и13browserсценариевPASS. RED/Green и существующая legacyfingerprint/replay/history проверены.

Тот же независимый reviewer выполнил только narrowclosure diff8e7dcd3..3441135: все3условия закрыты, новых поломок внутри исправления нет. Второе whole-review не запускали. Блокирующих/оставленных report-дефектов нет.

Дополнительная проверенная граница восстановления: старый schema1 migrator корректно отвергает schema3. Этот guard не ослаблялся. Старые constructor/find/list/submit/worker миграцию не вызывают; допустимый code-onlyrollback использует cache-onlyclear, не oldnativeupgrade и не снижение marker. Фактическое копированное host-восстановление остаётся отдельной операцией, этот документ не объявляет его выполненным.
