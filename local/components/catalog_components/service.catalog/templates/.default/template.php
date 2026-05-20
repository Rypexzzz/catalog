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

      <?php if ($arResult['IS_CATALOG_ADMIN']): ?>
        <button class="sc-btn sc-btn--accent" id="admin-add-service-btn" title="Добавить услугу">＋ Услуга</button>
      <?php endif; ?>
    </div>

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
            <table class="sc-detail-table">
              <thead><tr>
                <th class="sdt-result">Результат</th>
                <th class="sdt-role">Роль</th>
                <th class="sdt-grade">Категория</th>
                <th class="sdt-rate">Ставка (₽/ч)</th>
                <th class="sdt-hours">Часы</th>
                <th class="sdt-cost">Стоимость (₽)</th>
                <?php if ($isServiceRoot): ?>
                  <th class="sdt-crit">Критерии уровней</th>
                <?php endif; ?>
              </tr></thead>
              <tbody>
                <?php $rowIdx = 0; foreach ($svc['ROLES'] as $r): ?>
                  <tr data-rate="<?= $r['RATE'] ?>" data-std="<?= $r['STD_HOURS'] ?>">
                    <td class="sdt-result"><?= htmlspecialcharsbx($r['RESULT']) ?></td>
                    <td class="sdt-role"><?= htmlspecialcharsbx($r['ROLE_NAME']) ?></td>
                    <td class="sdt-grade">
                      <!-- ИСПРАВЛЕНО: select для КАЖДОЙ роли (не только первой) -->
                      <select class="role-grade-select sc-input sc-input--select-sm"
                              data-service="<?= $id ?>"
                              data-role="<?= $r['ROLE_ID'] ?>">
                        <?php foreach ($arResult['GRADES'] as $gradeId => $grade): ?>
                          <option value="<?= $gradeId ?>"
                                  data-rate="<?= $grade['RATE'] ?>"
                                  <?= ($r['GRADE_ID'] == $gradeId) ? 'selected' : '' ?>>
                            <?= htmlspecialcharsbx($grade['NAME']) ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </td>
                    <td class="sdt-rate"><?= number_format($r['RATE'], 0, '', ' ') ?></td>
                    <td class="sdt-hours">
                      <input type="number" min="0" class="role-hours sc-input sc-input--number"
                             data-role="<?= $r['ROLE_ID'] ?>"
                             data-std="<?= $r['STD_HOURS'] ?>"
                             value="<?= $r['HOURS'] ?>">
                    </td>
                    <td class="sdt-cost"><?= number_format($r['COST'], 0, '', ' ') ?></td>
                    <?php if ($isServiceRoot && $rowIdx === 0): ?>
                      <td class="sdt-crit" rowspan="<?= $roleCnt ?>">
                        <?= $svc['MIN_CRITERIA'] !== '' ? nl2br(htmlspecialcharsbx($svc['MIN_CRITERIA'])) : '—' ?>
                      </td>
                    <?php endif; ?>
                  </tr>
                <?php $rowIdx++; endforeach; ?>
              </tbody>
            </table>

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
