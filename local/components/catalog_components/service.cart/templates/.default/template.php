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
      <div class="cart-toolbar">
        <button type="button" class="sc-btn sc-btn--primary sc-team-btn" id="open-team-modal" title="Команда проекта">
          <svg viewBox="0 0 16 16" fill="none" aria-hidden="true">
            <path d="M11 7a3 3 0 1 0-6 0 3 3 0 0 0 6 0Zm2 0a5 5 0 0 1-1.5 3.58A6 6 0 0 1 14 16H2a6 6 0 0 1 2.5-5.42A5 5 0 1 1 13 7Z" fill="currentColor"/>
          </svg>
          Команда
          <span class="sc-team-btn__count"><?= count($arResult['TEAM']) ?></span>
        </button>
      </div>

      <?php if (empty($arResult['TEAM']) && !empty($arResult['ROOTS'])): ?>
      <div class="sc-team-hint">
        <span class="sc-team-hint__icon">
          <svg viewBox="0 0 18 18" fill="none" aria-hidden="true">
            <path d="M9 1.5a5.5 5.5 0 0 0-3.3 9.9c.5.37.8.95.8 1.57V14a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1.03c0-.62.3-1.2.8-1.57A5.5 5.5 0 0 0 9 1.5ZM7 16.5h4" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </span>
        <span>Соберите команду проекта, чтобы видеть стоимость услуг с фактическими ставками специалистов.</span>
        <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm" data-open-team>Открыть</button>
      </div>
      <?php endif; ?>

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

                  <?php
                    $cartRoles = $arResult['CART_RAW']['services'][$svc['ID']]['roles'] ?? [];
                    $teamMap   = [];
                    foreach ($arResult['TEAM'] as $m) $teamMap[$m['id']] = $m;
                  ?>
                  <div class="sc-roles">
                    <?php foreach ($svc['ROLES'] as $rid => $r):
                      $assignments = $cartRoles[$rid]['assignments'] ?? [];
                    ?>
                      <div class="sc-role-block" data-service="<?= $svc['ID'] ?>" data-role="<?= $rid ?>" data-std="<?= (float)($r['STD_HOURS'] ?? 0) ?>">
                        <div class="sc-role-block__head">
                          <div class="sc-role-block__name">
                            <strong><?= htmlspecialcharsbx($r['ROLE_NAME']) ?></strong>
                            <span class="sc-role-block__std">· норматив <?= number_format($r['STD_HOURS'] ?? 0, 0, '', ' ') ?> ч</span>
                          </div>
                          <?php if (!empty($r['RESULT'])): ?>
                            <div class="sc-role-block__result"><?= htmlspecialcharsbx($r['RESULT']) ?></div>
                          <?php endif; ?>
                        </div>
                        <?php if (!empty($assignments)): ?>
                          <div class="sc-assignments">
                            <?php foreach ($assignments as $a):
                              $specId = $a['specialistId'] ?? null;
                              $spec   = $specId ? ($teamMap[$specId] ?? null) : null;
                              $rate   = $spec ? (int)$spec['rate'] : 0;
                              $hours  = (float)($a['hours'] ?? 0);
                              $cost   = round($rate * $hours);
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
                        <?php else: ?>
                          <div class="sc-assignments">
                            <div class="sc-assignment-empty">Нет назначений</div>
                            <button type="button" class="sc-btn sc-btn--ghost sc-btn--sm assignment-add">
                              <svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
                                <path d="M6 1v10M1 6h10" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
                              </svg>
                              Выбрать специалиста
                            </button>
                          </div>
                        <?php endif; ?>
                      </div>
                    <?php endforeach; ?>
                  </div>
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
