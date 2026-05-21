# Каталог услуг ЦК ПР

Раздел корпоративного портала на платформе **1C-Bitrix**. Позволяет
сотруднику собрать «корзину» работ из справочника услуг, тонко
отредактировать состав (роли, грейды, часы, уровень обслуживания),
рассчитать итоговую стоимость проекта, сохранить набор как
черновик (личный, общий или публичный) и выгрузить расчёт в Excel
со сводкой по разработке/поддержке и НДС.

---

## Содержание

- [Структура репозитория](#структура-репозитория)
- [Слой данных в Bitrix](#слой-данных-в-bitrix)
- [Поток данных и сценарии](#поток-данных-и-сценарии)
- [Серверные классы (`ServiceCatalog`)](#серверные-классы-servicecatalog)
- [Компоненты Bitrix](#компоненты-bitrix)
- [AJAX‑контракты](#ajax-контракты)
- [Расчёт стоимости](#расчёт-стоимости)
- [Черновики, доступы и блокировки](#черновики-доступы-и-блокировки)
- [Экспорт в Excel](#экспорт-в-excel)
- [Конфигурация и константы](#конфигурация-и-константы)
- [Известные ограничения и TODO](#известные-ограничения-и-todo)

---

## Структура репозитория

Дерево репозитория повторяет пути, по которым код располагается
на сервере Bitrix:

```
catalog/                                 # → DOCUMENT_ROOT/catalog
├── index.php                            # лендинг с кнопкой «К каталогу»
├── _assets/nav.js                       # подшивает вкладки «Главная/Каталог/Корзина»
├── list/index.php                       # подключает компонент service.catalog
└── basket/index.php                     # подключает компонент service.cart

local/components/catalog_components/     # → DOCUMENT_ROOT/local/components/...
├── service.catalog/                     # витрина услуг
│   ├── component.php
│   └── templates/.default/{template.php, script.js, style.css}
└── service.cart/                        # корзина + черновики + экспорт
    ├── component.php
    └── templates/.default/{template.php, script.js, style.css}

local/php_interface/lib/                 # → DOCUMENT_ROOT/local/php_interface/lib
├── autoload.php                         # PSR‑4 для Ithive\Goalsbazhen\*
└── ServiceCatalog/
    ├── Repository.php                   # фасад над инфоблоками и HL‑блоками
    ├── CartService.php                  # корзина в $_SESSION
    ├── CostCalculator.php               # формулы стоимости и уровней
    ├── DraftService.php                 # CRUD и доступы к черновикам
    ├── DraftAccessRepository.php        # список пользователей с доступом
    ├── DraftLockService.php             # pessimistic‑lock + heartbeat
    └── ExcelExporter.php                # XLSX через PhpSpreadsheet
```

`autoload.php` регистрирует `spl_autoload` для префикса
`Ithive\Goalsbazhen\` → `local/php_interface/lib/`. Каждая страница
и каждый компонент явно вызывают `require_once .../lib/autoload.php`.

---

## Слой данных в Bitrix

Все источники данных — штатные сущности Bitrix. Идентификаторы
инфоблока/HL‑блоков не хранятся в коде: `Repository::resolveIds()`
ищет их по символьному коду / имени таблицы. Это значит, что
для запуска раздела на портале должны существовать следующие
сущности с указанными именами и обязательными полями.

| Сущность | Тип | Имя/код | Назначение | Обязательные поля |
|---|---|---|---|---|
| `services` | Инфоблок | `CODE = 'services'` | Каталог услуг и его разделы | Свойство `MIN_CRITERIA` (текст) — критерии уровней обслуживания |
| `depts_table` | HL‑блок | `TABLE_NAME = 'depts_table'` | Справочник подразделений | `UF_NAME` |
| `roles_table` | HL‑блок | `TABLE_NAME = 'roles_table'` | Справочник ролей | `UF_NAME`, `UF_DEPARTMENT` (→ depts) |
| `grades_table` | HL‑блок | `TABLE_NAME = 'grades_table'` | Категории/грейды и почасовые ставки | `UF_NAME`, `UF_RATE` (float) |
| `sostav_table` | HL‑блок | `TABLE_NAME = 'sostav_table'` | Состав каждой услуги: «услуга → роль/часы/результат» | `UF_SERVICE` (→ services), `UF_ROLE` (→ roles), `UF_HOURS` (float), `UF_RESULT` (текст) |
| `role_default_grades` | HL‑блок | `TABLE_NAME = 'role_default_grades'` | Грейд «по умолчанию» для роли | `UF_ROLE`, `UF_GRADE` |
| `drafts_table` | HL‑блок (опционально) | `TABLE_NAME = 'drafts_table'` | Сохранённые корзины | `UF_NAME`, `UF_USER_ID`, `UF_DATA` (JSON, text), `UF_DATE_CREATE` (datetime), `UF_TYPE` (`private`/`shared`/`public`), `UF_LOCKED_BY` (int, nullable), `UF_LOCKED_AT` (datetime, nullable) |
| `draft_access_table` | HL‑блок | `TABLE_NAME = 'draft_access_table'` | Список пользователей с доступом к `shared`‑черновикам | `UF_DRAFT_ID`, `UF_USER_ID` |

Разделы инфоблока `services` образуют двухуровневое дерево:
**корневые разделы — это «модели реализации»** (используются
коды `cascade`, `agile`, `service`), а их подразделы — **этапы**,
в которых уже лежат сами услуги. Корневой код `service`
включает специальный режим: для услуг этого раздела
становится доступен выбор уровня обслуживания (low/medium/high).

> Если HL‑блок `drafts_table` отсутствует — функциональность
> черновиков молча отключается, остальные части продолжают работать.
> Это поведение задано в `Repository::resolveIds()` (catch на `SystemException`).

---

## Поток данных и сценарии

1. Пользователь открывает `/catalog/` → лендинг с кнопкой,
   ведущей на `/catalog/list/`.
2. На странице каталога активна **витрина**: вкладки корневых
   разделов, поиск по названию, мультифильтр по ролям. Внутри
   вкладки услуги сгруппированы по этапам (подразделам).
3. По клику «Добавить» открывается мини‑карточка услуги:
   пользователь может изменить часы и грейд по каждой роли и —
   для `service`‑раздела — уровень обслуживания. Стоимость
   пересчитывается на лету.
4. Корзина (`/catalog/basket/`) показывает все добавленные услуги,
   сгруппированные по корневому разделу. Каждую строку можно
   редактировать; общая сумма пересчитывается AJAX‑запросами.
5. Авторизованный пользователь может сохранить корзину как
   **черновик**: `private` — виден только владельцу, `shared` —
   доступен указанным сотрудникам с захватом блокировки на время
   редактирования, `public` — доступен всем.
6. Загрузка черновика выполняется GET‑запросом `?load_draft=<id>`
   к `/catalog/basket/`: данные подставляются в `$_SESSION`, после
   чего происходит `LocalRedirect` для очистки query‑параметра.
7. Из корзины можно выгрузить расчёт в Excel
   (`?action=exportExcel&project_name=…`).

---

## Серверные классы (`ServiceCatalog`)

Все классы лежат в `local/php_interface/lib/ServiceCatalog/`,
пространство имён `Ithive\Goalsbazhen\ServiceCatalog`.

### `Repository`

Единая точка доступа к данным Bitrix. В конструкторе подключает
модули `iblock` и `highloadblock` и резолвит ID сущностей по их
кодам/именам таблиц. Внутри держит мемоизацию справочников
(подразделения, роли, грейды, дефолтные грейды для ролей) и
кэш скомпилированных `DataClass`’ов HL‑блоков. Основные группы
методов:

- **Геттеры справочников**: `getDepartments()`, `getRoles()`,
  `getGrades()`, `getRoleDefaultGrades()`, `getDefaultGradeForRole()`.
- **Состав услуг**: `getServiceComposition($serviceId)` →
  `[компоновка_ролей, эталонная_стоимость]`, где состав уже
  обогащён грейдом по умолчанию и стоимостью часа.
- **Разделы**: `getRootSections()`, `getSubSections($parentId)`,
  `getAllSectionsTree()`.
- **Услуги**: `getServiceElements($sectionId, $search, $filterIds)`
  с поиском и фильтрацией; `getServiceCriteria($ids)` — критерии
  уровней (для корзины).
- **Фильтр по ролям**: `getServiceIdsByRoles($roleIds)` — какие
  услуги содержат хотя бы одну из указанных ролей.
- **Админ‑операция**: `createService($name, $sectionId,
  $minCriteria, $rolesData)` — создаёт элемент инфоблока и
  заполняет состав в HL `sostav_table`, проверяя дубликаты ролей.

### `CartService`

Корзина хранится в **сессии PHP** под ключом
`SERVICE_CART_<userId|session_id>`. Это означает, что данные
живут до завершения сессии и существуют отдельно у каждого
пользователя/гостя.

Внутреннее представление одной услуги:

```php
[
  'NAME'              => 'Название услуги',
  'ROLES'             => [
      $roleId => [
          'ROLE_ID'   => int,
          'ROLE_NAME' => string,
          'RESULT'    => string,
          'HOURS'     => float,
          'STD_HOURS' => float,
          'GRADE_ID'  => int,
          'RATE'      => float,
          'COST'      => float, // RATE * HOURS, без коэффициента уровня
      ],
  ],
  'SERVICE_LEVEL'     => 'low' | 'medium' | 'high',
  'ROOT_SECTION_CODE' => 'cascade' | 'agile' | 'service' | '',
  'SECTION_NAME'      => 'Название этапа',
]
```

Ключевые методы: `add()`, `remove()`, `clear()`, `replace($all)`
(используется при загрузке черновика), `updateHours()`,
`updateGrade()`, `updateLevel()`, `getTotal()`.

Метод `canAddModel($rootCode)` запрещает класть в корзину
одновременно услуги из `cascade` и `agile` («каскадная» против
«гибкой» модели реализации). Парный к нему `hasModelConflict()`
обнаруживает уже состоявшийся конфликт (актуально при
загрузке черновика).

### `CostCalculator`

Чистые статические функции расчёта без зависимостей.

- `roleCost(rate, hours)` — базовая стоимость роли.
- `serviceCost(roles, level)` — сумма по ролям, умноженная на
  коэффициент уровня (см. таблицу в [Расчёт стоимости](#расчёт-стоимости)).
- `cartTotal(cart)` — сумма по услугам корзины.
- `getLevelCoefficient($level)`, `levelLabel($level)` — справочные.

### `DraftService`

Управляет черновиками поверх `drafts_table`. Делегирует:

- `DraftAccessRepository` — список пользователей с доступом
  (для `shared`).
- `DraftLockService` — блокировка черновика на время
  редактирования.

Три типа черновиков (`TYPE_PRIVATE`, `TYPE_SHARED`, `TYPE_PUBLIC`).
Полная матрица прав в `canAccess()`/`canEdit()`. Метод
`getAllDraftsGrouped($userId)` отдаёт три списка: `own` (свои
private + свои shared), `shared` (общие, к которым выдан доступ
другими), `public`.

### `DraftLockService`

Реализует pessimistic‑lock с эвристикой «protocol of three
timeouts»: см. [Конфигурация и константы](#конфигурация-и-константы).
Если блокировка просрочена — она автоматически снимается на
первой же проверке.

### `ExcelExporter`

Собирает книгу из трёх листов: «Сводная», «Разработка»,
«Поддержка». Группирует услуги по корневым разделам
(`service` → лист «Поддержка», остальное → «Разработка»),
в «Разработке» дополнительно группирует по этапам. На листе
«Сводная» считает суммы и применяет НДС.

---

## Компоненты Bitrix

Оба компонента в пакете `catalog_components` устроены по
одинаковому принципу: один `component.php` обслуживает и HTML‑
рендер страницы, и AJAX (`POST { ajax: 'Y', action: ..., ... }`),
а результат рендерится шаблоном `templates/.default/`.

### `catalog_components:service.catalog` — витрина

Действия:

- Выводит дерево «корневой раздел → этапы → услуги».
- Фильтры: поиск по названию (GET‑параметр `q`), мультивыбор
  ролей (`roles[]`), вкладки моделей (`root`).
- Карточка услуги: редактирование часов, грейдов и уровня
  обслуживания перед добавлением.
- Админ‑режим (группа `CATALOG_ADMIN_GROUP_ID`): кнопка «+ Услуга»
  для создания новой услуги с составом.

### `catalog_components:service.cart` — корзина

Действия:

- Группирует содержимое корзины по корневым разделам и
  отдельно подсвечивает блок «Поддержка» для услуг из раздела
  `service`.
- Редактирование/удаление позиций по AJAX.
- Сохранение/загрузка/переименование/удаление черновиков,
  изменение типа и состава доступов, поиск пользователей.
- Захват/освобождение/heartbeat блокировки для `shared`.
- Экспорт в Excel: `GET ?action=exportExcel&project_name=<имя>`.

Конфиг блокировок и преднабор черновиков передаются в шаблон
через `arResult['LOCK_CONFIG']` и `arResult['DRAFTS_GROUPED']`.

---

## AJAX‑контракты

Все AJAX‑эндпоинты — это `POST` на ту же страницу компонента
(`/catalog/list/` или `/catalog/basket/`) с полем `ajax=Y` и
полем `action`. Каждый защищён `check_bitrix_sessid()`:
при неверном CSRF‑токене ответ — `HTTP 403` с JSON
`{ success: 0, error: "Сессия истекла..." }`. Тело успешного
ответа — JSON, всегда содержит `success: 1`.

### `service.catalog` (`/catalog/list/`)

| `action` | Обязательные поля | Что делает |
|---|---|---|
| `addService` | `serviceId`, `hours[roleId]`, `grades[roleId]`, `level`, `rootSection`, `sectionName` | Кладёт услугу в корзину. Возвращает `total`. Проверяет конфликт моделей. |
| `removeService` | `serviceId` | Удаляет услугу из корзины. Возвращает `total`. |
| `updateHours` | `serviceId`, `roleId`, `hours` | Обновляет часы роли. Возвращает `serviceTotal`, `roleCost`, `total`. |
| `updateRoleGrade` | `serviceId`, `roleId`, `gradeId` | Меняет грейд роли (= ставку). Возвращает `serviceTotal`, `roleCost`, `roleRate`, `total`. |
| `updateServiceLevel` | `serviceId`, `level` | Меняет уровень обслуживания. Возвращает `serviceTotal`, `total`. |
| `getCartTotal` | — | Возвращает `total`. |
| `createService` | `serviceName`, `sectionId`, `minCriteria`, `roles[]` | **Только админ** (`CATALOG_ADMIN_GROUP_ID`). Создаёт услугу с составом ролей. |

### `service.cart` (`/catalog/basket/`)

Корзина:

| `action` | Обязательные поля |
|---|---|
| `removeService` | `serviceId` |
| `clearCart` | — |

Черновики (требуют авторизации):

| `action` | Обязательные поля | Примечание |
|---|---|---|
| `getDraftsList` | — | Возвращает `drafts: {own, shared, public}` и `counts`. |
| `getDraft` | `draft_id` | Один черновик с метаданными. |
| `createDraft` | `draft_name`, `draft_type` (`private`\|`shared`\|`public`), `access_users[]` (для shared) | Сохраняет текущую корзину как черновик. |
| `updateDraftData` | `draft_id` | Перезаписывает данные черновика текущей корзиной. Уважает блокировку. |
| `deleteDraft` | `draft_id` | Только владелец. |
| `renameDraft` | `draft_id`, `new_name` | Только владелец. |
| `changeDraftType` | `draft_id`, `new_type`, `access_users[]` | Только владелец. |
| `updateDraftAccess` | `draft_id`, `access_users[]` | Только владелец shared‑черновика. |
| `lockDraft` | `draft_id` | Захватить блокировку (или продлить свою). |
| `heartbeat` | `draft_id` | Продление блокировки. |
| `unlockDraft` | `draft_id` | Снять блокировку без сохранения. |
| `unlockAndSaveDraft` | `draft_id` | Сохранить и снять блокировку. |
| `searchUsers` | `search` | Поиск активных пользователей по NAME/LAST_NAME/LOGIN/EMAIL. |
| `getUsersInfo` | `user_ids[]` | Имя+аватар по списку ID. |
| `saveDraft` | `draft_name` | Алиас `createDraft` с `type=private`. |

Загрузка черновика — не AJAX: `GET /catalog/basket/?load_draft=<id>`.

Экспорт XLSX — не AJAX: `GET /catalog/basket/?action=exportExcel&project_name=<имя>`.
Если корзина пуста или есть конфликт моделей, сервер вернёт
HTML с `alert()` и `history.back()`.

---

## Расчёт стоимости

Базовая формула одной роли — `RATE × HOURS`. Стоимость услуги
получается суммированием по ролям и умножением на коэффициент
уровня обслуживания (значим только для услуг из раздела
`service`, для остальных коэффициент = 1.0):

| Уровень | Код | Коэффициент | Подпись |
|---|---|---|---|
| Низкий | `low` | 0.77 | «Низкий» |
| Средний | `medium` | 1.00 | «Средний» |
| Высокий | `high` | 1.30 | «Высокий» |

`CostCalculator::cartTotal()` округляет итог до целых рублей
(`round()`). НДС применяется только в Excel‑экспорте, через
формулу прямо в таблице (см. ниже).

---

## Черновики, доступы и блокировки

**Типы черновиков** (`DraftService::TYPE_*`):

- `private` — виден только владельцу.
- `shared` — владелец явно выдаёт доступ списку сотрудников.
  Pessimistic‑lock включён.
- `public` — виден всем авторизованным.

Доступы для `shared` хранятся в HL `draft_access_table` (по
паре `UF_DRAFT_ID, UF_USER_ID`).

**Жизненный цикл блокировки** (`DraftLockService`):

1. Клиент вызывает `lockDraft` при открытии черновика на
   редактирование. В `UF_LOCKED_BY` пишется ID, в `UF_LOCKED_AT`
   — текущее время.
2. Клиент шлёт `heartbeat` каждые `LOCK_CONFIRM_INTERVAL`
   секунд, чтобы освежить `UF_LOCKED_AT`.
3. Сервер при любой проверке блокировки сначала смотрит, не
   просрочена ли она (старше `LOCK_MAX_TIME` секунд). Если да —
   снимает её и считает черновик свободным.
4. При сохранении вызывается `unlockAndSaveDraft`, при отказе —
   `unlockDraft`.

JS должен показывать пользователю окно подтверждения
«Вы ещё здесь?» с обратным отсчётом в `LOCK_CONFIRM_TIMEOUT`
секунд (см. `getJsConfig()`).

---

## Экспорт в Excel

`ExcelExporter::export($byRoot, $projectName)`:

- Книга всегда содержит лист **«Сводная»** с двумя строками
  («…(Разработка)», «…(Поддержка)») и блоком итогов
  («ИТОГО без НДС» → «НДС 22%» → «ИТОГО с НДС»). Формулы
  считаются Excel‑ом, не PHP‑кодом.
- Лист **«Разработка»** добавляется, если в корзине есть
  услуги не из `service`. Внутри — двухуровневая группировка
  «Этап → задача → роль». При нескольких ролях ячейки задачи
  объединяются вертикально.
- Лист **«Поддержка»** добавляется при наличии услуг
  `service`. Дополнительный столбец «Коэфф. уровня обслуживания»
  отражает выбранный `SERVICE_LEVEL`.

Все стили (заливки, рамки, выравнивание) собраны в приватных
методах `headerStyle()`, `dataStyle()`, `stageStyle()`,
`totalRowStyle()`, `summaryTotalStyle()`.

Перед стримингом файла выполняется `while (ob_get_level())
ob_end_clean();` — чтобы прологом Bitrix‑шаблона не сломать
бинарный поток.

---

## Конфигурация и константы

| Константа | Значение | Где |
|---|---|---|
| `CATALOG_ADMIN_GROUP_ID` | `58` | `local/components/catalog_components/service.catalog/component.php:10` — группа пользователей, которым доступно создание услуг через UI |
| `CostCalculator::LEVEL_LOW/MEDIUM/HIGH` | `low`/`medium`/`high` | `local/php_interface/lib/ServiceCatalog/CostCalculator.php:8` |
| `LEVEL_COEFFICIENTS` | `[low=>0.77, medium=>1.0, high=>1.3]` | `CostCalculator.php:12` |
| `LEVEL_LABELS` | «Низкий/Средний/Высокий» | `CostCalculator.php:18` |
| `DraftLockService::LOCK_CONFIRM_INTERVAL` | `600` сек (10 мин) | `DraftLockService.php:10` — период heartbeat |
| `DraftLockService::LOCK_CONFIRM_TIMEOUT` | `120` сек (2 мин) | `DraftLockService.php:12` — таймаут диалога «вы здесь?» |
| `DraftLockService::LOCK_MAX_TIME` | `720` сек (12 мин) | `DraftLockService.php:14` — после этого блокировка считается протухшей |
| Ставка НДС | `0.22` (22%) | `ExcelExporter::buildSummarySheet()` — формула `=ROUND(D{total}*0.22,2)` |
| Бизнес‑правило «cascade vs agile» | — | `CartService::canAddModel()` / `hasModelConflict()` |
| Префикс автозагрузки | `Ithive\Goalsbazhen\` | `local/php_interface/lib/autoload.php:3` |
| Ключ сессии корзины | `SERVICE_CART_<userId|session_id>` | `CartService::__construct()` |

Все остальные «магические значения» (имена HL‑блоков, символьные
коды разделов `cascade`/`agile`/`service`, имя свойства
`MIN_CRITERIA`) описаны в разделе [Слой данных в Bitrix](#слой-данных-в-bitrix).

---

## Миграция на модель v2 (команда + назначения)

Корзина перешла с одной строки «роль×грейд×часы» на список
«команда проекта + назначения специалистов на роли». Старый формат
данных и v1‑черновики **несовместимы**.

**Что нужно сделать после деплоя кода** (один раз):

```bash
php local/php_interface/migrations/2026-05-20-cart-v2-reset.php
```

Скрипт:

1. Чистит HL‑блок `drafts_table` (все v1‑черновики удаляются).
2. Чистит HL‑блок `draft_access_table` (права на удалённые
   черновики становятся висячими).
3. Пытается удалить файлы битриксовых сессий, в которых лежит
   v1‑корзина (эвристика по содержимому файла).

Запуск идемпотентен. Сессии, которые скрипт не достал, не
страшны — `CartService` при создании проверяет структуру и
автоматически сбрасывает v1‑сессии в пустую v2 (см.
`looksLikeV1()` в `CartService.php`).

После миграции:
- Пользователи увидят пустую корзину и пустую команду.
- Раздел «Черновики» во всех трёх вкладках («Мои» / «Доступные» /
  «Публичные») будет пустым.
- На стороне UI всё работает сразу — никаких дополнительных
  действий с шаблонами или composer не требуется.

## Известные ограничения и TODO

- **Корзина живёт в `$_SESSION`.** Сценарий «открыл в одном
  браузере, продолжил на другом устройстве» работает только
  через ручное сохранение черновика. Сброс сессии = потеря
  невохранённой работы. Альтернатива — переместить состояние
  в HL‑блок «активная корзина пользователя».
- **Хардкод ID группы админов (`58`).** Если на другом портале
  ID отличается, нужно править константу. Имеет смысл вынести
  в опции компонента или в код группы (`CODE`) с резолвом по
  `CGroup::GetList`.
- **Хардкод ставки НДС в Excel.** При смене ставки придётся
  править `ExcelExporter::buildSummarySheet()` (формула в ячейке
  и подпись строки).
- **Хардкод имён HL‑блоков.** `Repository::resolveIds()` ищет
  HL‑блоки по `TABLE_NAME` (`depts_table`, `roles_table` …) —
  переименование в админке Bitrix сломает раздел.
- **`?v=' . time()` в шаблонах.** В `template.php` обоих
  компонентов CSS и JS подгружаются с cache‑bust по таймштампу;
  это полностью отключает HTTP‑кэширование. На бою лучше
  заменить на `?v=<релизный хэш>`.
- **Конфликт моделей при загрузке черновика.** `canAddModel()`
  работает только в момент добавления. Если в `shared`/`public`
  черновике уже сохранены услуги обеих моделей и пользователь
  его загружает — корзина окажется в состоянии конфликта
  (`hasModelConflict() === true`). UI показывает плашку, но
  предотвратить ситуацию заранее нечем.
- **`nav.js` инжектирует навигацию через `MutationObserver`,**
  опираясь на селекторы `#pagetitle`/`.pagetitle`/`.ui-entity-section-title`.
  При смене темы портала навигация может не появиться.
- **Поиск пользователей реализован в двух местах** с похожей,
  но не идентичной логикой: `DraftAccessRepository::searchUsers()`
  (используется в `searchUsers` AJAX компонента `service.cart` —
  на практике перекрывается прямым вызовом `UserTable::getList()`
  в `component.php:212`, который дополнительно ищет по EMAIL).
  Стоит унифицировать.
- **Нет миграций/фикстур** для требуемых сущностей Bitrix —
  инфоблок и HL‑блоки нужно создавать вручную через админку
  по описанию из таблицы выше.
- **Старые черновики без `UF_TYPE`** трактуются как `private`
  (см. `DraftService::enrichDraft()`). Это работает, но
  засоряет фильтры. После миграции данных условие в
  `getUserOwnDrafts()` можно упростить.
