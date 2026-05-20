<?php
if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true) die();

$dir = $this->GetFolder();
$v   = '?v=' . time();

global $USER;
$userId = $USER->GetID();

$lockConfig = $arResult['LOCK_CONFIG'] ?? [
    'heartbeat_interval' => 900,
    'confirm_timeout'    => 600,
];
?>

<link rel="stylesheet" href="<?= $dir ?>/style.css<?= $v ?>">
<script defer src="<?= $dir ?>/script.js<?= $v ?>"></script>

<div class="cart">
  <section class="cart-main">
    <div class="cart-scroll">
      <?php if ($arResult['MODEL_CONFLICT']): ?>
        <div class="cart-alert cart-alert--danger">
          <strong>⚠ Конфликт моделей!</strong> В корзине есть услуги из каскадной и гибкой моделей одновременно. Удалите лишнее.
        </div>
      <?php endif; ?>

      <?php if (empty($arResult['ROOTS'])): ?>
        <div class="cart-empty">
          <div class="cart-empty__icon">🛒</div>
          <p class="cart-empty__title">Корзина пуста</p>
          <p class="cart-empty__hint">Добавьте услуги из каталога</p>
        </div>
      <?php else: ?>
        <?php foreach ($arResult['ROOTS'] as $rootId => $root): ?>
          <div class="cart-section">
            <div class="cart-section__head">
              <h3 class="cart-section__title"><?= htmlspecialcharsbx($root['NAME']) ?></h3>
            </div>

            <?php foreach ($root['ITEMS'] as $svc):
              $level          = $svc['SERVICE_LEVEL'] ?? 'medium';
              $isServiceStage = $svc['IS_SERVICE_STAGE'] ?? false;
              $levelCoeff     = $isServiceStage
                ? ($level === 'high' ? 1.3 : ($level === 'low' ? 0.77 : 1.0))
                : 1.0;
            ?>
              <article class="cart-card" data-id="<?= $svc['ID'] ?>" data-level="<?= htmlspecialcharsbx($level) ?>">
                <header class="cart-card__head">
                  <span class="cart-card__tag"><?= htmlspecialcharsbx($svc['SECTION_NAME']) ?></span>
                  <span class="cart-card__name"><?= htmlspecialcharsbx($svc['NAME']) ?></span>
                  <span class="cart-card__sum">
                    <span class="cart-card__sum-val"><?= number_format($svc['SUM'], 0, '', ' ') ?></span> ₽
                  </span>
                  <button class="cart-card__remove" data-id="<?= $svc['ID'] ?>" title="Удалить">
                    <svg width="16" height="16" viewBox="0 0 16 16" fill="none">
                      <path d="M2 4h12M5.333 4V2.667a1.333 1.333 0 011.334-1.334h2.666a1.333 1.333 0 011.334 1.334V4m2 0v9.333a1.333 1.333 0 01-1.334 1.334H4.667a1.333 1.333 0 01-1.334-1.334V4h9.334z" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                  </button>
                  <button class="cart-card__toggle">Подробнее</button>
                </header>

                <div class="cart-card__body">
                  <?php if ($isServiceStage && !empty($svc['MIN_CRITERIA'])): ?>
                    <div class="cart-criteria">
                      <strong>Критерии уровней обслуживания:</strong>
                      <p><?= nl2br(htmlspecialcharsbx($svc['MIN_CRITERIA'])) ?></p>
                    </div>
                  <?php endif; ?>

                  <?php if ($isServiceStage): ?>
                    <div class="cart-level-badge">
                      Уровень: <strong><?= htmlspecialcharsbx(\Ithive\Goalsbazhen\ServiceCatalog\CostCalculator::levelLabel($level)) ?></strong>
                      <?php if ($level !== 'medium'): ?>
                        <span class="cart-level-badge__coeff">(×<?= $level === 'low' ? '0.77' : '1.3' ?>)</span>
                      <?php endif; ?>
                    </div>
                  <?php endif; ?>

                  <table class="cart-table">
                    <thead><tr>
                      <th>Результат</th>
                      <th>Роль</th>
                      <th class="cart-table__center">Категория</th>
                      <th class="cart-table__center">Ставка ₽/ч</th>
                      <th class="cart-table__center">Часы</th>
                      <th class="cart-table__center">Стоимость, ₽</th>
                    </tr></thead>
                    <tbody>
                      <?php foreach ($svc['ROLES'] as $rid => $r):
                        $roleCost = ($r['RATE'] ?? 0) * ($r['HOURS'] ?? 0) * $levelCoeff;
                      ?>
                        <tr data-service="<?= $svc['ID'] ?>" data-role="<?= $rid ?>" data-grade-id="<?= (int)($r['GRADE_ID'] ?? 0) ?>" data-hours="<?= (int)($r['HOURS'] ?? 0) ?>" data-rate="<?= (float)($r['RATE'] ?? 0) ?>">
                          <td><?= htmlspecialcharsbx($r['RESULT']) ?></td>
                          <td><?= htmlspecialcharsbx($r['ROLE_NAME']) ?></td>
                          <td class="cart-table__grade cart-table__center"><?= htmlspecialcharsbx($r['GRADE_NAME'] ?? '') ?></td>
                          <td class="cart-table__mono cart-table__center"><?= number_format($r['RATE'] ?? 0, 0, '', ' ') ?></td>
                          <td class="cart-table__mono cart-table__center"><?= (int)($r['HOURS'] ?? 0) ?></td>
                          <td class="cart-table__mono cart-table__cost cart-table__center"><?= number_format($roleCost, 0, '', ' ') ?></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <!-- Футер -->
    <footer class="cart-footer">
      <div class="cart-footer__left">
        <?php if ($arResult['HAS_DRAFTS'] && $userId): ?>
          <button class="sc-btn sc-btn--success" id="draft-save-btn">
            💾 Сохранить как черновик
          </button>

          <button class="sc-btn sc-btn--ghost" id="drafts-btn">
            📁 Применить черновик
            <?php
              $totalDrafts = array_sum($arResult['DRAFT_COUNTS']);
              if ($totalDrafts > 0):
            ?>
              <span class="sc-badge sc-badge--info"><?= $totalDrafts ?></span>
            <?php endif; ?>
          </button>
        <?php endif; ?>
        <button class="sc-btn sc-btn--primary" id="export-btn" <?= $arResult['MODEL_CONFLICT'] ? 'disabled' : '' ?>>
          📊 Выгрузить отчёт
        </button>
      </div>
      <div class="cart-footer__right">
        <div class="cart-footer__draft-edit" id="draft-edit-bar" style="display:none">
          <span class="cart-footer__draft-label" id="draft-edit-label"></span>
          <span class="sc-badge sc-badge--info" id="draft-dirty-badge" style="display:none">есть изменения</span>
          <button class="sc-btn sc-btn--ghost sc-btn--sm" id="draft-exit-btn">Выйти из редактирования</button>
        </div>
<button class="sc-btn sc-btn--danger sc-btn--sm" id="btn-clear">Очистить</button>
        <div class="cart-footer__total">
          Итого: <span id="grand-val"><?= number_format($arResult['GRAND_TOTAL'], 0, '', ' ') ?></span> ₽
        </div>
      </div>
    </footer>
  </section>
</div>

<!-- ===== МОДАЛЬНЫЕ ОКНА ===== -->

<!-- Экспорт -->
<div id="export-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head"><h3>Выгрузка отчёта</h3><button class="sc-modal__close" data-close>&times;</button></div>
    <div class="sc-modal__body">
      <p>Введите название проекта:</p>
      <input type="text" id="project-name-input" class="sc-input" style="width:100%;margin-top:10px" placeholder="Название проекта">
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" data-close>Отмена</button>
      <button class="sc-btn sc-btn--primary" id="export-submit">Выгрузить</button>
    </div>
  </div>
</div>

<!-- Черновики: главная модалка -->
<div id="drafts-modal" class="sc-modal" data-close-overlay="0">
  <div class="sc-modal__box sc-modal__box--wide">
    <div class="sc-modal__head">
      <h3>Черновики</h3>
      <button class="sc-modal__close" data-close>&times;</button>
    </div>
    <div class="sc-modal__body drafts-modal-body">
      <!-- Табы -->
      <div class="drafts-tabs">
        <button class="drafts-tabs__item is-active" data-tab="own">
          Мои
          <span class="drafts-tabs__count" id="tab-count-own"><?= $arResult['DRAFT_COUNTS']['own'] ?></span>
        </button>
        <button class="drafts-tabs__item" data-tab="shared">
          Доступные мне
          <span class="drafts-tabs__count" id="tab-count-shared"><?= $arResult['DRAFT_COUNTS']['shared'] ?></span>
        </button>
        <button class="drafts-tabs__item" data-tab="public">
          Публичные
          <span class="drafts-tabs__count" id="tab-count-public"><?= $arResult['DRAFT_COUNTS']['public'] ?></span>
        </button>
      </div>

      <!-- Контент табов -->
      <div class="drafts-content">
        <div class="drafts-list" id="drafts-list-own" data-tab-content="own">
          <!-- Заполняется JS -->
        </div>
        <div class="drafts-list" id="drafts-list-shared" data-tab-content="shared" style="display:none">
          <!-- Заполняется JS -->
        </div>
        <div class="drafts-list" id="drafts-list-public" data-tab-content="public" style="display:none">
          <!-- Заполняется JS -->
        </div>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" data-close>Закрыть</button>
    </div>
  </div>
</div>

<!-- Черновики: создание/редактирование -->
<div id="draft-form-modal" class="sc-modal" data-close-overlay="0">
  <div class="sc-modal__box">
    <div class="sc-modal__head">
      <h3 id="draft-form-title">Новый черновик</h3>
      <button class="sc-modal__close" data-close>&times;</button>
    </div>
    <div class="sc-modal__body">
      <input type="hidden" id="draft-form-id" value="">
      <input type="hidden" id="draft-form-mode" value="create">
      
      <div class="sc-form-group">
        <label class="sc-form-label">Название <span class="sc-required">*</span></label>
        <input type="text" id="draft-form-name" class="sc-input" style="width:100%" placeholder="Название черновика">
      </div>
      
      <div class="sc-form-group">
        <label class="sc-form-label">Тип доступа</label>
        <div class="draft-type-selector">
          <label class="draft-type-option">
            <input type="radio" name="draft_type" value="private" checked>
            <span class="draft-type-option__content">
              <span class="draft-type-option__icon">🔒</span>
              <span class="draft-type-option__text">
                <strong>Личный</strong>
                <small>Виден только вам</small>
              </span>
            </span>
          </label>
          <label class="draft-type-option">
            <input type="radio" name="draft_type" value="shared">
            <span class="draft-type-option__content">
              <span class="draft-type-option__icon">👥</span>
              <span class="draft-type-option__text">
                <strong>Общий</strong>
                <small>Выбранным пользователям</small>
              </span>
            </span>
          </label>
          <label class="draft-type-option">
            <input type="radio" name="draft_type" value="public">
            <span class="draft-type-option__content">
              <span class="draft-type-option__icon">🌍</span>
              <span class="draft-type-option__text">
                <strong>Публичный</strong>
                <small>Доступен всем</small>
              </span>
            </span>
          </label>
        </div>
      </div>
      
      <div class="sc-form-group" id="draft-users-group" style="display:none">
        <label class="sc-form-label">Пользователи с доступом</label>
        <div id="draft-users-selected" class="draft-users-selected"></div>
        <div class="sc-user-search" id="draft-users-search">
          <div class="sc-user-search__field">
            <svg class="sc-user-search__icon" width="16" height="16" viewBox="0 0 16 16" fill="none">
              <path d="M7.333 12.667A5.333 5.333 0 107.333 2a5.333 5.333 0 000 10.667zM14 14l-2.9-2.9" stroke="currentColor" stroke-width="1.3" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>
            <input type="text" id="user-search-input" class="sc-user-search__input"
                   placeholder="Поиск по имени или фамилии…" autocomplete="off">
            <span class="sc-user-search__spinner" id="user-search-spinner" style="display:none"></span>
          </div>
          <div class="sc-user-search__dropdown" id="user-search-results" style="display:none"></div>
        </div>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" data-close>Отмена</button>
      <button class="sc-btn sc-btn--success" id="draft-form-submit">Сохранить</button>
    </div>
  </div>
</div>

<!-- Черновики: подтверждение применения -->
<div id="draft-apply-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head">
      <h3>Применить черновик</h3>
      <button class="sc-modal__close" data-close>&times;</button>
    </div>
    <div class="sc-modal__body">
      <p>Черновик "<span id="draft-apply-name"></span>" будет загружен в корзину.</p>
      <p class="text-muted">Текущее содержимое корзины будет заменено.</p>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" data-close>Отмена</button>
      <button class="sc-btn sc-btn--primary" id="draft-apply-submit">Применить</button>
    </div>
  </div>
</div>


<!-- Черновики: выход из режима редактирования / переключение -->
<div id="draft-exit-modal" class="sc-modal" data-close-overlay="0">
  <div class="sc-modal__box">
    <div class="sc-modal__head">
      <h3>Завершение редактирования</h3>
      <button class="sc-modal__close" data-close-cancel>&times;</button>
    </div>
    <div class="sc-modal__body">
      <p id="draft-exit-message"></p>
      <p class="text-muted" id="draft-exit-hint" style="display:none"></p>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" id="draft-exit-cancel" data-close-cancel>Отмена</button>
      <button class="sc-btn sc-btn--ghost" id="draft-exit-nosave">Выйти без сохранения</button>
      <button class="sc-btn sc-btn--success" id="draft-exit-save">Сохранить и выйти</button>
    </div>
  </div>
</div>

<!-- Черновики: сохранение изменений -->
<div id="draft-save-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head">
      <h3>Сохранить изменения</h3>
      <button class="sc-modal__close" data-close>&times;</button>
    </div>
    <div class="sc-modal__body">
      <p>Сохранить изменения корзины?</p>
      <div class="draft-save-options">
        <button class="draft-save-option" id="save-to-current">
          <span class="draft-save-option__icon">💾</span>
          <span class="draft-save-option__text">
            <strong>В текущий черновик</strong>
            <small id="save-to-current-name"></small>
          </span>
        </button>
        <button class="draft-save-option" id="save-as-new">
          <span class="draft-save-option__icon">➕</span>
          <span class="draft-save-option__text">
            <strong>Создать новый</strong>
            <small>Сохранить как новый черновик</small>
          </span>
        </button>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" data-close>Отмена</button>
    </div>
  </div>
</div>

<!-- Heartbeat: подтверждение продолжения -->
<div id="heartbeat-modal" class="sc-modal" data-close-overlay="0">
  <div class="sc-modal__box">
    <div class="sc-modal__head">
      <h3>Продолжить редактирование?</h3>
    </div>
    <div class="sc-modal__body">
      <p>Вы редактируете общий черновик "<span id="heartbeat-draft-name"></span>".</p>
      <p>Хотите продолжить? Если не ответить в течение <span id="heartbeat-timeout"></span> минут, изменения будут сохранены и черновик разблокируется.</p>
      <div class="heartbeat-timer">
        <div class="heartbeat-timer__bar" id="heartbeat-timer-bar"></div>
      </div>
    </div>
    <div class="sc-modal__foot">
      <button class="sc-btn sc-btn--ghost" id="heartbeat-stop">Завершить и сохранить</button>
      <button class="sc-btn sc-btn--primary" id="heartbeat-continue">Продолжить</button>
    </div>
  </div>
</div>

<!-- Общие: алерт -->
<div id="cart-alert-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head"><h3>Внимание</h3><button class="sc-modal__close" data-close>&times;</button></div>
    <div class="sc-modal__body"><p id="cart-alert-message"></p></div>
    <div class="sc-modal__foot"><button class="sc-btn sc-btn--primary" data-close>OK</button></div>
  </div>
</div>

<!-- Общие: подтверждение -->
<div id="cart-confirm-modal" class="sc-modal">
  <div class="sc-modal__box">
    <div class="sc-modal__head"><h3>Подтверждение</h3><button class="sc-modal__close" data-close-cancel>&times;</button></div>
    <div class="sc-modal__body"><p id="cart-confirm-message"></p></div>
    <div class="sc-modal__foot">
      <button id="cart-confirm-cancel" class="sc-btn sc-btn--ghost" data-close-cancel>Отмена</button>
      <button id="cart-confirm-ok" class="sc-btn sc-btn--primary">OK</button>
    </div>
  </div>
</div>

<script>
/* Конфигурация для JS */
window.__CART_CONFIG__ = {
  heartbeatInterval: <?= (int)$lockConfig['heartbeat_interval'] ?>,
  confirmTimeout: <?= (int)$lockConfig['confirm_timeout'] ?>,
  userId: <?= (int)$userId ?>,
  pageUrl: '<?= $APPLICATION->GetCurPage() ?>',
  draftTypes: <?= json_encode($arResult['DRAFT_TYPES'], JSON_UNESCAPED_UNICODE) ?>,
  initialDrafts: <?= json_encode($arResult['DRAFTS_GROUPED'], JSON_UNESCAPED_UNICODE) ?>,
  initialCounts: <?= json_encode($arResult['DRAFT_COUNTS']) ?>,
};
</script>
