<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$dir = $this->GetFolder();
$ver = '?v=' . time();

global $USER;
$userId  = $USER->GetID();
$cartKey = 'SERVICE_CART_' . ($userId ? $userId : session_id());
$inCart   = $_SESSION[$cartKey] ?? [];

$hasStages = !empty($arResult['STAGES']);
$selectedRoles = array_values(array_filter(array_map('intval', (array)($_GET['roles'] ?? []))));
?>

<link rel="stylesheet" href="<?= $dir ?>/style.css<?= $ver ?>">
<script defer src="<?= $dir ?>/script.js<?= $ver ?>"></script>

<div class="sc">
  <!-- ===== ШАПКА ===== -->
  <header class="sc-header">
    <div class="sc-toolbar">
            <div class="sc-mselect" id="role-filter">
        <button type="button" class="sc-input sc-input--select sc-mselect__btn" aria-haspopup="listbox" aria-expanded="false">
          Все роли
        </button>
        <div class="sc-mselect__menu" role="listbox">
          <div class="sc-mselect__head">
            <span class="sc-mselect__title">Роли</span>
            <button type="button" class="sc-mselect__clear" data-action="clear-roles">Сбросить</button>
          </div>
          <div class="sc-mselect__list">
            <?php foreach ($arResult['ROLES'] as $rid => $role): ?>
              <label class="sc-mselect__item">
                <input type="checkbox" value="<?= $rid ?>" <?= in_array((int)$rid, $selectedRoles, true) ? 'checked' : '' ?>>
                <span><?= htmlspecialcharsbx($role['NAME']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <input id="service-search" class="sc-input sc-input--search"
             value="<?= htmlspecialcharsbx($_GET['q'] ?? '') ?>"
             placeholder="Поиск услуг…">

      <div id="sc-total" class="sc-toolbar__total">
        Итого: <span class="sc-toolbar__sum"><?= number_format($arResult['CURRENT_TOTAL'], 0, '', ' ') ?></span> ₽
      </div>

      <button type="button" class="sc-btn sc-btn--primary sc-team-btn" id="open-team-modal" title="Команда проекта">
        <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
          <path d="M11 7a3 3 0 1 0-6 0 3 3 0 0 0 6 0Zm2 0a5 5 0 0 1-1.5 3.58A6 6 0 0 1 14 16H2a6 6 0 0 1 2.5-5.42A5 5 0 1 1 13 7Z" fill="currentColor"/>
        </svg>
        Команда
        <span class="sc-team-btn__count"><?= count($arResult['TEAM']) ?></span>
      </button>

      <?php if ($arResult['IS_CATALOG_ADMIN']): ?>
        <button class="sc-btn sc-btn--accent" id="admin-add-service-btn" title="Добавить услугу">＋ Услуга</button>
      <?php endif; ?>
    </div>

    <?php if (empty($arResult['TEAM'])): ?>
    <div class="sc-team-hint" id="sc-team-hint">
      <span class="sc-team-hint__icon">
        <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
          <path d="M9 1.5a5.5 5.5 0 0 0-3.3 9.9c.5.37.8.95.8 1.57V14a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1.03c0-.62.3-1.2.8-1.57A5.5 5.5 0 0 0 9 1.5ZM7 16.5h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
      </span>
      <span>Соберите команду проекта, чтобы видеть стоимость услуг с фактическими ставками специалистов.</span>
      <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm" data-open-team>Открыть</button>
    </div>
    <?php endif; ?>

        <!-- Корневые разделы (табы) -->
    <?php
      $baseParams = [];
      $qVal = trim((string)($_GET['q'] ?? ''));
      if ($qVal !== '') $baseParams['q'] = $qVal;
      if (!empty($selectedRoles)) $baseParams['roles'] = $selectedRoles;
    ?>
    <nav class="sc-tabs">
      <?php foreach ($arResult['ROOT_SECTIONS'] as $rootId => $rootSection):
        $params = $baseParams;
        $params['root'] = $rootId;
        $href = '?' . http_build_query($params);
      ?>
        <a href="<?= $href ?>"
           data-root="<?= (int)$rootId ?>"
           data-root-code="<?= htmlspecialcharsbx($rootSection['CODE'] ?? '') ?>"
           class="sc-tabs__item <?= $rootId == $arResult['ACTIVE_ROOT_ID'] ? 'is-active' : '' ?>">
          <?= htmlspecialcharsbx($rootSection['NAME']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div id="sc-info" class="sc-filters-info" style="display:none;"></div>

    <?php if ($hasStages): ?>
      <nav class="sc-stages">
        <?php foreach ($arResult['STAGES'] as $sid => $name): ?>
          <a href="#stage-<?= $sid ?>" class="sc-stages__link" data-stage-link="<?= $sid ?>">
            <?= htmlspecialcharsbx($name) ?>
          </a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <div class="sc-table-head">
      <div class="sc-th sc-th--status">Статус</div>
      <div class="sc-th sc-th--name">Название услуги</div>
      <div class="sc-th sc-th--cost">Стоимость</div>
      <div class="sc-th sc-th--stage">Этап</div>
    </div>
  </header>

  <!-- ===== СПИСОК УСЛУГ ===== -->
  <div class="sc-scroll" id="sc-scroll">

    <!-- Empty state (управляется JS) -->
    <div class="sc-empty" id="sc-empty">
      <div class="sc-empty__icon">🔎</div>
      <div class="sc-empty__title">Ничего не найдено</div>
      <div class="sc-empty__hint">Измените поиск или фильтры — мы покажем подходящие услуги</div>
      <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm" id="sc-clear-filters">Сбросить фильтры</button>
    </div>
    <?php foreach ($arResult['MAP'] as $sid => $sec): ?>
      <section class="sc-stage-block" id="stage-<?= $sid ?>" data-stage="<?= $sid ?>">
        <?php foreach ($sec['ITEMS'] as $svc):
          $id           = $svc['ID'];
          $inCartFlg    = isset($inCart[$id]);
          $rolesCsv     = implode(',', array_keys($svc['ROLES']));
          $roleNames    = implode(' ', array_column($svc['ROLES'], 'ROLE_NAME'));
          $roleCnt      = count($svc['ROLES']);
          $isServiceRoot = ($svc['ROOT_SECTION_CODE'] === 'service');
        ?>

          <!-- Строка услуги -->
          <div class="sc-row <?= $inCartFlg ? 'sc-row--active' : '' ?>"
               data-id="<?= $id ?>"
               data-name="<?= htmlspecialcharsbx($svc['NAME']) ?>"
               data-roles="<?= $rolesCsv ?>"
               data-result="<?= htmlspecialcharsbx($roleNames) ?>"
               data-stdcost="<?= $svc['STD_COST'] ?>"
               data-currentcost="<?= $svc['CURRENT_COST'] ?>"
               data-in-cart="<?= $inCartFlg ? '1' : '0' ?>"
               data-root-section="<?= $svc['ROOT_SECTION_CODE'] ?>"
               data-section-name="<?= htmlspecialcharsbx($svc['SECTION_NAME']) ?>">

            <div class="sc-row__btns">
              <button class="sc-btn-status <?= $inCartFlg ? 'is-added' : '' ?>" data-id="<?= $id ?>">
                <span class="sc-btn-status__icon"><?= $inCartFlg ? '✓' : '+' ?></span>
                <span class="sc-btn-status__text"><?= $inCartFlg ? 'Добавлено' : 'Добавить' ?></span>
              </button>
              <button class="sc-btn-toggle" aria-label="Раскрыть">
                <svg width="10" height="6" viewBox="0 0 10 6" fill="none"><path d="M1 1l4 4 4-4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </button>
            </div>

            <span class="sc-row__name"><?= htmlspecialcharsbx($svc['NAME']) ?></span>
            <span class="sc-row__cost"><?= number_format($svc['CURRENT_COST'], 0, '', ' ') ?> ₽</span>
            <span class="sc-row__tag"><?= htmlspecialcharsbx($svc['SECTION_NAME']) ?></span>
          </div>

          <!-- Детали услуги -->
          <div class="sc-details" data-id="<?= $id ?>">
            <?php
              $cartRoles = $arResult['CART_RAW']['services'][$id]['roles'] ?? [];
              $teamMap   = [];
              foreach ($arResult['TEAM'] as $m) $teamMap[$m['id']] = $m;
            ?>
            <div class="sc-roles">
              <?php $rowIdx = 0; foreach ($svc['ROLES'] as $r):
                $rid          = (int)$r['ROLE_ID'];
                $assignments  = $cartRoles[$rid]['assignments'] ?? [];
              ?>
                <div class="sc-role-block" data-service="<?= $id ?>" data-role="<?= $rid ?>" data-std="<?= $r['STD_HOURS'] ?>">
                  <div class="sc-role-block__head">
                    <div class="sc-role-block__name">
                      <strong><?= htmlspecialcharsbx($r['ROLE_NAME']) ?></strong>
                      <span class="sc-role-block__std">· норматив <?= number_format($r['STD_HOURS'], 0, '', ' ') ?> ч</span>
                    </div>
                    <?php if (!empty($r['RESULT'])): ?>
                      <div class="sc-role-block__result"><?= htmlspecialcharsbx($r['RESULT']) ?></div>
                    <?php endif; ?>
                  </div>

                  <?php if ($inCartFlg && !empty($assignments)): ?>
                    <div class="sc-assignments">
                      <?php foreach ($assignments as $a):
                        $specId  = $a['specialistId'] ?? null;
                        $spec    = $specId ? ($teamMap[$specId] ?? null) : null;
                        $rate    = $spec ? (int)$spec['rate'] : 0;
                        $hours   = (float)($a['hours'] ?? 0);
                        $cost    = round($rate * $hours);
                      ?>
                        <div class="sc-assignment" data-assignment-id="<?= htmlspecialcharsbx($a['id']) ?>">
                          <div class="sc-assignment__picker">
                            <?php if ($spec && $spec['photo']): ?>
                              <img src="<?= htmlspecialcharsbx($spec['photo']) ?>" alt="" class="sc-assignment__avatar">
                            <?php elseif ($spec): ?>
                              <span class="sc-assignment__avatar sc-assignment__avatar--ph"><?= mb_substr($spec['name'], 0, 1) ?></span>
                            <?php else: ?>
                              <span class="sc-assignment__avatar sc-assignment__avatar--ph sc-assignment__avatar--empty">?</span>
                            <?php endif; ?>
                            <select class="sc-input sc-input--select-sm assignment-spec">
                              <option value="" data-photo="" data-initial="?">— Выбрать специалиста —</option>
                              <?php foreach ($arResult['TEAM'] as $member): ?>
                                <option value="<?= htmlspecialcharsbx($member['id']) ?>"
                                        data-rate="<?= $member['rate'] ?>"
                                        data-photo="<?= htmlspecialcharsbx($member['photo']) ?>"
                                        data-initial="<?= htmlspecialcharsbx(mb_substr($member['name'], 0, 1)) ?>"
                                        <?= $specId === $member['id'] ? 'selected' : '' ?>>
                                  <?= htmlspecialcharsbx($member['name']) ?><?= $member['gradeName'] ? ' · ' . htmlspecialcharsbx($member['gradeName']) : '' ?> (<?= number_format($member['rate'], 0, '', ' ') ?> ₽/ч)
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <input type="number" min="0" step="0.5" class="sc-input sc-input--number assignment-hours" value="<?= $hours ?>">
                          <span class="sc-assignment__cost"><?= number_format($cost, 0, '', ' ') ?> ₽</span>
                          <button type="button" class="sc-btn-icon sc-btn-icon--danger assignment-remove" title="Убрать назначение" aria-label="Убрать назначение">
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                              <path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                            </svg>
                          </button>
                        </div>
                      <?php endforeach; ?>
                      <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm assignment-add">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                          <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        Ещё специалист
                      </button>
                    </div>
                  <?php elseif ($inCartFlg): ?>
                    <div class="sc-assignments">
                      <div class="sc-assignment-empty">Нет назначений</div>
                      <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm assignment-add">
                        <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                          <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                        </svg>
                        Выбрать специалиста
                      </button>
                    </div>
                  <?php else: ?>
                    <div class="sc-role-block__hint">Добавьте услугу в корзину, чтобы назначить специалистов.</div>
                  <?php endif; ?>
                </div>
              <?php $rowIdx++; endforeach; ?>
            </div>

            <?php if ($isServiceRoot && $svc['MIN_CRITERIA'] !== ''): ?>
              <div class="sc-criteria">
                <strong>Критерии уровней:</strong><br>
                <?= nl2br(htmlspecialcharsbx($svc['MIN_CRITERIA'])) ?>
              </div>
            <?php endif; ?>

            <?php if ($isServiceRoot): ?>
              <div class="sc-level" data-service="<?= $id ?>">
                <span class="sc-level__label">Уровень обслуживания:</span>
                <div class="sc-level__options">
                  <?php foreach (['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'] as $lv => $lbl): ?>
                    <label class="sc-level__option <?= $svc['CURRENT_LEVEL'] === $lv ? 'is-active' : '' ?>">
                      <input type="radio" name="level_<?= $id ?>" value="<?= $lv ?>"
                             <?= $svc['CURRENT_LEVEL'] === $lv ? 'checked' : '' ?>>
                      <span><?= $lbl ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>

            <?php if ($isServiceRoot): ?>
              <div class="sc-level" data-service="<?= $id ?>">
                <span class="sc-level__label">Уровень обслуживания:</span>
                <div class="sc-level__options">
                  <?php foreach (['low' => 'Низкий', 'medium' => 'Средний', 'high' => 'Высокий'] as $lv => $lbl): ?>
                    <label class="sc-level__option <?= $svc['CURRENT_LEVEL'] === $lv ? 'is-active' : '' ?>">
                      <input type="radio" name="level_<?= $id ?>" value="<?= $lv ?>"
                             <?= $svc['CURRENT_LEVEL'] === $lv ? 'checked' : '' ?>>
                      <span><?= $lbl ?></span>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endif; ?>
          </div>

        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  </div>
</div>

<!-- ===== МОДАЛЬНЫЕ ОКНА ===== -->
<div id="catalog-alert-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head"><h3>Внимание</h3><button class="sc-modal__close" onclick="closeCatalogAlertModal()">&times;</button></div>
    <div class="sc-modal__body"><p id="catalog-alert-message"></p></div>
    <div class="sc-modal__foot"><button type="button" class="sc-btn sc-btn--primary" onclick="closeCatalogAlertModal()">Понятно</button></div>
  </div>
</div>

<div id="catalog-confirm-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head"><h3>Подтверждение</h3><button class="sc-modal__close" onclick="closeCatalogConfirmModal(false)">&times;</button></div>
    <div class="sc-modal__body"><p id="catalog-confirm-message"></p></div>
    <div class="sc-modal__foot">
      <button type="button" class="sc-btn sc-btn--ghost" onclick="closeCatalogConfirmModal(false)">Отмена</button>
      <button type="button" class="sc-btn sc-btn--primary" onclick="closeCatalogConfirmModal(true)">Продолжить</button>
    </div>
  </div>
</div>

<!-- Модалка команды проекта -->
<div id="team-modal" class="sc-modal">
  <div class="sc-modal__box sc-modal__box--wide">
    <div class="sc-modal__head">
      <h3>Команда проекта</h3>
      <button class="sc-modal__close" type="button" data-close-team>&times;</button>
    </div>
    <div class="sc-modal__body">
      <div class="sc-team-list" id="team-list">
        <?php if (empty($arResult['TEAM'])): ?>
          <div class="sc-team-empty">Команда пока пуста. Добавьте специалистов ниже.</div>
        <?php else: foreach ($arResult['TEAM'] as $m): ?>
          <div class="sc-team-row" data-spec-id="<?= htmlspecialcharsbx($m['id']) ?>">
            <div class="sc-team-row__user">
              <?php if ($m['photo']): ?>
                <img src="<?= htmlspecialcharsbx($m['photo']) ?>" alt="" class="sc-team-row__avatar">
              <?php else: ?>
                <span class="sc-team-row__avatar sc-team-row__avatar--ph"><?= mb_substr($m['name'], 0, 1) ?></span>
              <?php endif; ?>
              <div class="sc-team-row__name"><?= htmlspecialcharsbx($m['name']) ?></div>
            </div>
            <select class="sc-input sc-input--select-sm sc-team-row__grade">
              <option value="">— грейд —</option>
              <?php foreach ($arResult['GRADES'] as $gid => $g): ?>
                <option value="<?= $gid ?>" <?= ($m['gradeId'] == $gid) ? 'selected' : '' ?>><?= htmlspecialcharsbx($g['NAME']) ?></option>
              <?php endforeach; ?>
            </select>
            <input type="number" min="0" class="sc-input sc-input--number sc-team-row__rate" value="<?= (int)$m['rate'] ?>" placeholder="Ставка ₽/ч">
            <button type="button" class="sc-btn-icon sc-btn-icon--danger sc-team-row__remove" title="Удалить из команды" aria-label="Удалить из команды">
              <svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
                <path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
              </svg>
            </button>
          </div>
        <?php endforeach; endif; ?>
      </div>

      <div class="sc-team-add">
        <div class="sc-team-add__title">Добавить специалиста</div>
        <div class="sc-team-add__row">
          <div class="sc-team-add__user">
            <input type="text" id="team-user-search" class="sc-input" placeholder="Поиск сотрудника…" autocomplete="off">
            <div class="sc-team-add__results" id="team-user-results"></div>
            <input type="hidden" id="team-user-id" value="">
            <div class="sc-team-add__selected" id="team-user-selected" style="display:none"></div>
          </div>
          <select class="sc-input sc-input--select-sm" id="team-grade-select">
            <option value="">— грейд —</option>
            <?php foreach ($arResult['GRADES'] as $gid => $g): ?>
              <option value="<?= $gid ?>"><?= htmlspecialcharsbx($g['NAME']) ?></option>
            <?php endforeach; ?>
          </select>
          <input type="number" min="0" id="team-rate-input" class="sc-input sc-input--number" placeholder="Ставка ₽/ч">
          <button type="button" class="sc-btn sc-btn--primary sc-btn--sm" id="team-add-btn">
            <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
              <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
            Добавить
          </button>
        </div>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--primary" type="button" data-close-team>Готово</button>
    </div>
  </div>
</div>

<?php if ($arResult['IS_CATALOG_ADMIN']): ?>
<!-- Модалка добавления услуги (админ) -->
<div id="add-service-modal" class="sc-modal" data-close-overlay="0">
  <div class="sc-modal__box sc-modal__box--wide">
    <div class="sc-modal__head">
      <h3>Новая услуга <span id="admin-services-counter" class="sc-badge sc-badge--success" style="display:none"></span></h3>
      <button class="sc-modal__close" onclick="closeAdminModal()">&times;</button>
    </div>
    <div class="sc-modal__body">
      <div id="admin-created-list" class="sc-admin-created" style="display:none"></div>
      <div class="sc-form-group">
        <label class="sc-form-label">Название <span class="sc-required">*</span></label>
        <input type="text" id="admin-service-name" class="sc-input" placeholder="Название услуги">
      </div>
      <div class="sc-form-group">
        <label class="sc-form-label">Раздел <span class="sc-required">*</span></label>
        <select id="admin-section-select" class="sc-input sc-input--select">
          <option value="">Выберите раздел…</option>
          <?php foreach ($arResult['ALL_SECTIONS'] as $rootSec):
            $rootCode = (string)($rootSec['CODE'] ?? '');
          ?>
            <optgroup label="<?= htmlspecialcharsbx($rootSec['NAME']) ?>">
              <?php if (empty($rootSec['CHILDREN'])): ?>
                <option value="<?= $rootSec['ID'] ?>" data-root-code="<?= htmlspecialcharsbx($rootCode) ?>"><?= htmlspecialcharsbx($rootSec['NAME']) ?></option>
              <?php else: ?>
                <?php foreach ($rootSec['CHILDREN'] as $child): ?>
                  <option value="<?= $child['ID'] ?>" data-root-code="<?= htmlspecialcharsbx($rootCode) ?>"><?= htmlspecialcharsbx($child['NAME']) ?></option>
                <?php endforeach; ?>
              <?php endif; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="sc-form-group" id="admin-min-criteria-group">
        <label class="sc-form-label">Критерии уровней обслуживания</label>
        <textarea id="admin-min-criteria" class="sc-input sc-input--textarea" rows="3" placeholder="Необязательно"></textarea>
      </div>
      <div class="sc-form-group">
        <label class="sc-form-label">Состав услуги <span class="sc-required">*</span></label>
        <table class="sc-admin-roles-table" id="admin-roles-table">
          <thead><tr><th>Роль</th><th>Результат</th><th>Часы</th><th></th></tr></thead>
          <tbody></tbody>
        </table>
        <button class="sc-btn sc-btn--ghost sc-btn--sm" id="admin-add-role-btn" style="margin-top:8px">+ Добавить роль</button>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" onclick="closeAdminModal()">Отмена</button>
      <button class="sc-btn sc-btn--ghost" id="admin-save-add-btn">Сохранить и создать ещё</button>
      <button class="sc-btn sc-btn--success" id="admin-save-btn">Сохранить</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
/* Глобальные модальные функции каталога */
function showCatalogAlert(msg) {
  document.getElementById('catalog-alert-message').textContent = msg;
  document.getElementById('catalog-alert-modal').classList.add('is-open');
}
function closeCatalogAlertModal() {
  document.getElementById('catalog-alert-modal').classList.remove('is-open');
}
let _catalogConfirmCb = null;
function showCatalogConfirm(msg, cb) {
  _catalogConfirmCb = cb;
  document.getElementById('catalog-confirm-message').textContent = msg;
  document.getElementById('catalog-confirm-modal').classList.add('is-open');
}
function closeCatalogConfirmModal(result) {
  document.getElementById('catalog-confirm-modal').classList.remove('is-open');
  if (_catalogConfirmCb) { _catalogConfirmCb(result); _catalogConfirmCb = null; }
}

<?php if ($arResult['IS_CATALOG_ADMIN']): ?>
/* Данные для формы админа */
window.__CATALOG_ROLES__ = <?= json_encode(array_map(fn($r) => $r['NAME'], $arResult['ROLES']), JSON_UNESCAPED_UNICODE) ?>;
window.__CATALOG_ROLE_IDS__ = <?= json_encode(array_keys($arResult['ROLES'])) ?>;
<?php endif; ?>
</script>
