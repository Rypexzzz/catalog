if (window.__SERVICE_CATALOG_JS__) {
  console.warn('service-catalog: duplicate load prevented');
} else {
  window.__SERVICE_CATALOG_JS__ = true;

  BX.ready(() => {

    const $  = (sel, ctx = document) => ctx.querySelector(sel);
    const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];
    const fmt = n => Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 });

    let rows        = $$('.sc-row');
    const search    = $('#service-search');
    const roleFilter = $('#role-filter');

    // --- State (источник правды для фильтров) ---
    const state = {
      q: (search?.value || '').trim(),
      roles: [],
    };

    const getSelectedRoleIds = () => {
      if (!roleFilter) return [];
      return [...roleFilter.querySelectorAll('input[type="checkbox"]:checked')].map(i => String(i.value));
    };
    const setRoleChecked = (roleId, checked) => {
      if (!roleFilter) return;
      const inp = roleFilter.querySelector(`input[type="checkbox"][value="${roleId}"]`);
      if (inp) inp.checked = !!checked;
    };
    const getSelectedRoleLabelsByIds = (roleIds) => {
      if (!roleFilter) return [];
      return roleIds.map(rid => {
        const inp = roleFilter.querySelector(`input[type="checkbox"][value="${rid}"]`);
        return (inp?.closest('label')?.querySelector('span')?.textContent || '').trim() || rid;
      });
    };

    const getSelectedRoleLabels = () => getSelectedRoleLabelsByIds(getSelectedRoleIds());

    const syncStateFromUI = () => {
      state.q = (search?.value || '').trim();
      state.roles = getSelectedRoleIds();
    };
    const syncUIFromState = () => {
      if (search) search.value = state.q;
      if (roleFilter) {
        roleFilter.querySelectorAll('input[type="checkbox"]').forEach(i => {
          i.checked = state.roles.includes(String(i.value));
        });
      }
      updateRoleBtnLabel();
    };

    // initial sync: подхватываем значения, которые уже выставлены сервером (checked + q)
    syncStateFromUI();

    const infoBox   = $('#sc-info');
    const totalBox  = $('#sc-total');
    let scrollBox = $('.sc-scroll');
    const emptyBox = $('#sc-empty');
    const clearFiltersBtn = $('#sc-clear-filters');

    if (clearFiltersBtn) {
      clearFiltersBtn.addEventListener('click', () => {
        state.q = '';
        state.roles = [];
        syncUIFromState();
        applyFilters();
      });
    }

    /* ==============================================================
       Компенсация ширины скроллбара для заголовков таблицы
       ============================================================== */
    const updateScrollbarVar = () => {
      const sc = document.querySelector('.sc');
      scrollBox = document.querySelector('.sc-scroll');
      if (!sc || !scrollBox) return;
      const sb = Math.max(0, scrollBox.offsetWidth - scrollBox.clientWidth);
      sc.style.setProperty('--sc-scrollbar', sb + 'px');
    };
    window.addEventListener('resize', updateScrollbarVar);


    const modals = $$('.sc-modal');
    const portalModalsToBody = () => {
      modals.forEach(m => {
        if (m && m.parentElement !== document.body) document.body.appendChild(m);
      });
    };
    portalModalsToBody();

    const syncModalLock = () => {
      const hasOpen = !!document.querySelector('.sc-modal.is-open');
      document.documentElement.classList.toggle('sc-modal-open', hasOpen);
      document.body.classList.toggle('sc-modal-open', hasOpen);
    };

    const closeModalEl = (modalEl) => {
      if (!modalEl) return;
      const id = modalEl.id || '';
      // аккуратные фолбэки, чтобы не потерять колбэки подтверждений
      if (id === 'catalog-confirm-modal' && typeof window.closeCatalogConfirmModal === 'function') {
        window.closeCatalogConfirmModal(false);
      } else if (id === 'catalog-alert-modal' && typeof window.closeCatalogAlertModal === 'function') {
        window.closeCatalogAlertModal();
      } else if (id === 'add-service-modal' && typeof window.closeAdminModal === 'function') {
        window.closeAdminModal();
      } else {
        modalEl.classList.remove('is-open');
      }
      syncModalLock();
    };

    // click outside (overlay)
    document.addEventListener('click', (e) => {
      const opened = e.target.closest('.sc-modal.is-open');
      if (!opened) return;
      // Для некоторых модалок (например добавление услуги) запрещаем закрытие по overlay
      if (e.target === opened) {
        if (String(opened.dataset.closeOverlay || '1') === '0') return;
        closeModalEl(opened);
      }
    });

    // esc to close topmost
    document.addEventListener('keydown', (e) => {
      if (e.key !== 'Escape') return;
      const openList = $$('.sc-modal.is-open');
      if (!openList.length) return;
      closeModalEl(openList[openList.length - 1]);
    });

    // следим за открытием/закрытием
    const mo = new MutationObserver(syncModalLock);
    modals.forEach(m => mo.observe(m, { attributes: true, attributeFilter: ['class'] }));
    syncModalLock();


    const roleBtn  = roleFilter ? roleFilter.querySelector('.sc-mselect__btn') : null;
    const roleMenu = roleFilter ? roleFilter.querySelector('.sc-mselect__menu') : null;

    const closeRoleMenu = () => {
      if (!roleFilter) return;
      roleFilter.classList.remove('is-open');
      if (roleBtn) roleBtn.setAttribute('aria-expanded', 'false');
    };

    const updateRoleBtnLabel = () => {
      if (!roleBtn) return;
      const labels = getSelectedRoleLabelsByIds(state.roles);
      if (!labels.length) {
        roleBtn.textContent = 'Все роли';
        return;
      }
      if (labels.length === 1) {
        roleBtn.textContent = labels[0];
        return;
      }
      if (labels.length === 2) {
        roleBtn.textContent = labels.join(', ');
        return;
      }
      roleBtn.textContent = `Роли: ${labels.length}`;
    };

    if (roleBtn && roleMenu) {
      roleBtn.addEventListener('click', e => {
        e.preventDefault();
        const open = roleFilter.classList.toggle('is-open');
        roleBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      });

      document.addEventListener('click', e => {
        if (!roleFilter.classList.contains('is-open')) return;
        if (e.target.closest('#role-filter')) return;
        closeRoleMenu();
      });

      // сброс
      roleMenu.addEventListener('click', e => {
        const btn = e.target.closest('[data-action="clear-roles"]');
        if (!btn) return;
        e.preventDefault();
        roleFilter.querySelectorAll('input[type="checkbox"]').forEach(i => (i.checked = false));
        syncStateFromUI();
        updateRoleBtnLabel();
        applyFilters();
      });

      // изменение чекбоксов
      roleMenu.addEventListener('change', e => {
        if (!e.target.matches('input[type="checkbox"]')) return;
        syncStateFromUI();
        updateRoleBtnLabel();
        applyFilters();
      });

      // первичная синхронизация ролей из HTML (PHP мог проставить checked)
      syncStateFromUI();
      updateRoleBtnLabel();
    }



    window.showCatalogAlert = msg => {
      $('#catalog-alert-message').textContent = msg;
      $('#catalog-alert-modal').classList.add('is-open');
    };
    window.closeCatalogAlertModal = () => $('#catalog-alert-modal').classList.remove('is-open');

    let _confirmCb = null;
    window.showCatalogConfirm = (msg, cb) => {
      _confirmCb = cb;
      $('#catalog-confirm-message').textContent = msg;
      $('#catalog-confirm-modal').classList.add('is-open');
    };
    window.closeCatalogConfirmModal = ok => {
      $('#catalog-confirm-modal').classList.remove('is-open');
      if (_confirmCb) { _confirmCb(ok); _confirmCb = null; }
    };

    // --- URL state: q + roles[] (shareable links) ---
    const stripRoleParams = (sp) => {
      // remove roles, roles[], roles[0]...
      const keys = [...sp.keys()];
      keys.forEach(k => {
        if (k === 'roles' || k === 'roles[]' || k.startsWith('roles[')) sp.delete(k);
      });
    };

    const buildUrlForRoot = (rootId) => {
      const url = new URL(location.href);
      if (rootId) url.searchParams.set('root', String(rootId));

      // используем state, чтобы состояние было одинаковым везде (включая после AJAX-подгрузки)
      const q = (state.q || '').trim();
      if (q) url.searchParams.set('q', q);
      else url.searchParams.delete('q');

      stripRoleParams(url.searchParams);
      (state.roles || []).forEach(id => url.searchParams.append('roles[]', String(id)));

      // keep other params intact (e.g. load_draft etc are not used here)
      return url.pathname + (url.searchParams.toString() ? ('?' + url.searchParams.toString()) : '');
    };

    const buildFetchUrlForRoot = (rootId) => {
      const url = new URL(location.href);
      if (rootId) url.searchParams.set('root', String(rootId));
      url.searchParams.delete('q');
      stripRoleParams(url.searchParams);
      return url.pathname + (url.searchParams.toString() ? ('?' + url.searchParams.toString()) : '');
    };

    const updateTabHrefs = () => {
      $$('.sc-tabs__item').forEach(a => {
        const root = a.dataset.root || '';
        a.setAttribute('href', buildUrlForRoot(root));
      });
    };

    const updateAdminCriteriaVisibility = () => {
      const group = $('#admin-min-criteria-group');
      const ta = $('#admin-min-criteria');
      const sel = $('#admin-section-select');
      if (!group || !ta || !sel) return;

      const opt = sel.selectedOptions ? sel.selectedOptions[0] : null;
      const rootCode = (opt?.dataset?.rootCode || '').toString();
      const isService = (rootCode === 'service');

      group.style.display = isService ? '' : 'none';
      ta.disabled = !isService;
      if (!isService) ta.value = '';
    };

    let _urlSyncTimer = null;
    const syncUrl = ({replace = true} = {}) => {
      const active = $('.sc-tabs__item.is-active');
      const root = active?.dataset.root || new URL(location.href).searchParams.get('root') || '';
      const newUrl = buildUrlForRoot(root);

      if (_urlSyncTimer) clearTimeout(_urlSyncTimer);
      _urlSyncTimer = setTimeout(() => {
        if (replace) history.replaceState(null, '', newUrl);
        updateTabHrefs();
      }, 150);
    };

    document.addEventListener('click', e => {
      const tab = e.target.closest('.sc-tabs__item');
      if (!tab || tab.classList.contains('is-active')) return;
      
      e.preventDefault();

      // фиксируем текущее состояние фильтров перед переключением вкладки
      syncStateFromUI();
      const rootId = tab.dataset.root || '';
      const pushUrl = buildUrlForRoot(rootId);
      const fetchUrl = buildFetchUrlForRoot(rootId);
      if (!pushUrl || !fetchUrl) return;

      // Показываем загрузку
      scrollBox = $('.sc-scroll');
      scrollBox.style.opacity = '0.5';
      scrollBox.style.pointerEvents = 'none';

      fetch(fetchUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(html => {
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');

          // Заменяем контент
          const newScroll = doc.querySelector('.sc-scroll');
          const newStages = doc.querySelector('.sc-stages');

          if (newScroll) {
            scrollBox.innerHTML = newScroll.innerHTML;
          }

          // Обновляем навигацию по этапам
          const oldStages = $('.sc-stages');
          if (newStages && oldStages) {
            oldStages.innerHTML = newStages.innerHTML;
          } else if (newStages && !oldStages) {
            const tableHead = $('.sc-table-head');
            if (tableHead) {
              tableHead.insertAdjacentHTML('beforebegin', newStages.outerHTML);
            }
          } else if (!newStages && oldStages) {
            oldStages.remove();
          }

          // Обновляем активный таб
          $$('.sc-tabs__item').forEach(t => t.classList.remove('is-active'));
          tab.classList.add('is-active');

          // Обновляем URL без перезагрузки
          history.pushState(null, '', pushUrl);

          // Переинициализируем
          rows = $$('.sc-row');
          scrollBox = $('.sc-scroll');
          scrollBox.style.opacity = '';
          scrollBox.style.pointerEvents = '';

          // скроллбар мог поменяться (другая вкладка/высота контента)
          updateScrollbarVar();

          // Применяем текущие фильтры к подгруженному контенту
          applyFilters();

          updateActiveStage();
          updateTabHrefs();
        })
        .catch(err => {
          console.error('Tab load error:', err);
          scrollBox.style.opacity = '';
          scrollBox.style.pointerEvents = '';
        });
    });

    // Обработка кнопки "Назад" в браузере
    window.addEventListener('popstate', () => location.reload());

    const getLevelCoeff = serviceId => {
      const det = $(`.sc-details[data-id="${serviceId}"]`);
      if (!det) return 1.0;
      const checked = det.querySelector('.sc-level input[type="radio"]:checked');
      if (!checked) return 1.0;
      return checked.value === 'high' ? 1.3 : checked.value === 'low' ? 0.77 : 1.0;
    };

    // Пересчитать стоимость услуги исходя из назначений на странице.
    const recalcServiceCost = serviceId => {
      const det = $(`.sc-details[data-id="${serviceId}"]`);
      if (!det) return 0;
      let base = 0;
      $$('.sc-assignment', det).forEach(a => {
        const sel   = a.querySelector('.assignment-spec');
        const hInp  = a.querySelector('.assignment-hours');
        if (!sel || !hInp) return;
        const rate  = parseFloat(sel.selectedOptions[0]?.dataset.rate) || 0;
        const hours = parseFloat(hInp.value) || 0;
        base += rate * hours;
      });
      return Math.round(base * getLevelCoeff(serviceId));
    };

    const updateAssignmentCostCell = (assignmentEl) => {
      const sel   = assignmentEl.querySelector('.assignment-spec');
      const hInp  = assignmentEl.querySelector('.assignment-hours');
      const cell  = assignmentEl.querySelector('.sc-assignment__cost');
      if (!sel || !hInp || !cell) return;
      const rate  = parseFloat(sel.selectedOptions[0]?.dataset.rate) || 0;
      const hours = parseFloat(hInp.value) || 0;
      cell.textContent = fmt(Math.round(rate * hours)) + ' ₽';
    };

    const updateServiceCostInRow = serviceId => {
      const row = $(`.sc-row[data-id="${serviceId}"]`);
      if (!row) return;
      const cost = recalcServiceCost(serviceId);
      const el   = row.querySelector('.sc-row__cost');
      if (el) el.textContent = fmt(cost) + ' ₽';
    };

    const makeToken = (text, onRemove) => {
      const el = document.createElement('span');
      el.className = 'sc-token';
      el.innerHTML = BX.util.htmlspecialchars(text) + ' <button>&times;</button>';
      el.querySelector('button').onclick = onRemove;
      return el;
    };

    function applyFilters() {
      // На всякий случай всегда читаем текущее состояние из UI
      syncStateFromUI();

      const svRaw = (state.q || '').trim();
      const sv = svRaw.toLowerCase();
      const selectedRoleIds = state.roles || [];

      $$('.sc-details.is-open').forEach(d => {
        d.classList.remove('is-open');
        const prev = d.previousElementSibling;
        if (prev) {
          prev.classList.remove('is-expanded');
          const svg = prev.querySelector('.sc-btn-toggle svg');
          if (svg) svg.style.transform = '';
        }
      });

      rows = $$('.sc-row');

      rows.forEach(r => {
        const okName = !sv || r.dataset.name.toLowerCase().includes(sv) ||
                       r.dataset.result.toLowerCase().includes(sv);
        const okRole = !selectedRoleIds.length || selectedRoleIds.some(v => r.dataset.roles.split(',').includes(v));
        r.style.display = (okName && okRole) ? '' : 'none';
        const det = $(`.sc-details[data-id="${r.dataset.id}"]`);
        if (det) det.style.display = (okName && okRole) ? '' : 'none';
      });

      $$('.sc-stage-block').forEach(st => {
        const has = $$('.sc-row', st).some(r => r.style.display !== 'none');
        st.style.display = has ? '' : 'none';
      });

      // Empty state
      if (emptyBox) {
        const visibleCount = rows.filter(r => r.style.display !== 'none').length;
        emptyBox.style.display = visibleCount ? 'none' : 'block';
      }

      infoBox.innerHTML = '';
      if (svRaw) {
        infoBox.appendChild(makeToken('Поиск: ' + svRaw, () => {
          state.q = '';
          syncUIFromState();
          applyFilters();
        }));
      }
      if (selectedRoleIds.length) {
        const selectedLabels = getSelectedRoleLabelsByIds(selectedRoleIds);
        selectedRoleIds.forEach((rid, idx) => {
          const lbl = selectedLabels[idx] || rid;
          infoBox.appendChild(makeToken('Роль: ' + lbl, () => {
            state.roles = (state.roles || []).filter(x => String(x) !== String(rid));
            syncUIFromState();
            applyFilters();
          }));
        });
      }
      infoBox.style.display = infoBox.children.length ? 'flex' : 'none';

      // синхронизируем URL для шеринга (без перезагрузки)
      syncUrl({replace: true});
    }

    search.addEventListener('input', applyFilters);
    applyFilters();

    (() => {
      const sp = new URL(location.href).searchParams;
      const hasQ = !!(sp.get('q') || '').trim();
      const hasRoles = [...sp.keys()].some(k => k === 'roles' || k === 'roles[]' || k.startsWith('roles['));
      if (!hasQ && !hasRoles) return;

      const active = $('.sc-tabs__item.is-active');
      const rootId = active?.dataset.root || sp.get('root') || '';
      const fetchUrl = buildFetchUrlForRoot(rootId);
      if (!fetchUrl) return;

      // небольшой визуальный индикатор, чтобы не было «скачка»
      if (scrollBox) {
        scrollBox.style.opacity = '0.6';
        scrollBox.style.pointerEvents = 'none';
      }

      fetch(fetchUrl, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(r => r.text())
        .then(html => {
          const parser = new DOMParser();
          const doc = parser.parseFromString(html, 'text/html');
          const newScroll = doc.querySelector('.sc-scroll');
          const newStages = doc.querySelector('.sc-stages');

          scrollBox = $('.sc-scroll');
          if (newScroll && scrollBox) {
            scrollBox.innerHTML = newScroll.innerHTML;
          }

          const oldStages = $('.sc-stages');
          if (newStages && oldStages) {
            oldStages.innerHTML = newStages.innerHTML;
          } else if (newStages && !oldStages) {
            const tableHead = $('.sc-table-head');
            if (tableHead) tableHead.insertAdjacentHTML('beforebegin', newStages.outerHTML);
          } else if (!newStages && oldStages) {
            oldStages.remove();
          }

          rows = $$('.sc-row');
          if (scrollBox) {
            scrollBox.style.opacity = '';
            scrollBox.style.pointerEvents = '';
          }
          applyFilters();
          updateActiveStage();
          updateTabHrefs();
        })
        .catch(err => {
          console.error('Initial unfiltered hydrate error:', err);
          if (scrollBox) {
            scrollBox.style.opacity = '';
            scrollBox.style.pointerEvents = '';
          }
        });
    })();

    const updateTotal = sum => {
      const el = totalBox.querySelector('.sc-toolbar__sum');
      if (el) el.textContent = fmt(sum);
      else totalBox.textContent = 'Итого: ' + fmt(sum) + ' ₽';
    };

    document.addEventListener('click', ev => {
      const togBtn = ev.target.closest('.sc-btn-toggle');
      if (togBtn) {
        const row = togBtn.closest('.sc-row');
        const det = $(`.sc-details[data-id="${row.dataset.id}"]`);
        if (!det) return;
        const isOpen = det.classList.toggle('is-open');
        row.classList.toggle('is-expanded', isOpen);
        const svg = togBtn.querySelector('svg');
        if (svg) svg.style.transform = isOpen ? 'rotate(180deg)' : '';
        if (isOpen) det.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        return;
      }

      const statusBtn = ev.target.closest('.sc-btn-status');
      if (statusBtn) {
        const isAdd     = !statusBtn.classList.contains('is-added');
        const serviceId = statusBtn.dataset.id;
        const row       = statusBtn.closest('.sc-row');
        const det       = $(`.sc-details[data-id="${serviceId}"]`);
        statusBtn.disabled = true;

        let level = 'medium';
        const rootSection = row?.dataset.rootSection || '';
        const sectionName = row?.dataset.sectionName || '';

        if (det) {
          const checked = det.querySelector('.sc-level input[type="radio"]:checked');
          if (checked) level = checked.value;
        }

        BX.ajax.post(location.href, {
          ajax: 'Y',
          sessid: BX.bitrix_sessid(),
          action: isAdd ? 'addService' : 'removeService',
          serviceId, level, rootSection, sectionName
        }, resp => {
          try {
            const j = JSON.parse(resp);
            if (j.success) {
              statusBtn.classList.toggle('is-added', isAdd);
              statusBtn.querySelector('.sc-btn-status__icon').textContent = isAdd ? '✓' : '+';
              statusBtn.querySelector('.sc-btn-status__text').textContent = isAdd ? 'Добавлено' : 'Добавить';
              if (row) {
                row.dataset.inCart = isAdd ? '1' : '0';
                row.classList.toggle('sc-row--active', isAdd);
              }
              updateTotal(j.total);
              // После добавления/удаления услуги — перезагрузим, чтобы перерисовать содержимое карточки.
              // (UI сразу выходит из режима «не настроена», и наоборот.)
              setTimeout(() => location.reload(), 200);
            } else if (j.error) {
              showCatalogAlert(j.error);
            }
          } catch (e) { console.error(e); }
          statusBtn.disabled = false;
        });
      }
    });


    document.addEventListener('change', e => {
      if (!e.target.matches('.sc-level input[type="radio"]')) return;
      const radio     = e.target;
      const serviceId = radio.closest('.sc-level').dataset.service;
      const level     = radio.value;
      const coeff     = level === 'high' ? 1.3 : level === 'low' ? 0.77 : 1.0;

      $$('.sc-level__option', radio.closest('.sc-level__options'))
        .forEach(o => o.classList.toggle('is-active', o.contains(radio)));

      const det = $(`.sc-details[data-id="${serviceId}"]`);
      if (det) $$('tbody tr', det).forEach(tr => updateRoleCostCell(tr, coeff));

      updateServiceCostInRow(serviceId);

      const row    = $(`.sc-row[data-id="${serviceId}"]`);
      const inCart = row?.dataset.inCart === '1';
      if (!inCart) return;

      BX.ajax.post(location.href, {
        ajax: 'Y',
        sessid: BX.bitrix_sessid(),
        action: 'updateServiceLevel',
        serviceId, level
      }, resp => {
        try {
          const d = JSON.parse(resp);
          if (d.success) {
            updateTotal(d.total);
            const costEl = row?.querySelector('.sc-row__cost');
            if (costEl) costEl.textContent = fmt(d.serviceTotal) + ' ₽';
          }
        } catch (e) { console.error(e); }
      });
    });


    function updateActiveStage() {
      const navLinks = $$('.sc-stages__link');
      scrollBox = $('.sc-scroll');
      if (!scrollBox || !navLinks.length) return;
      const scrollPos = scrollBox.scrollTop;
      const headerH   = document.querySelector('.sc-header')?.offsetHeight || 0;
      let active = null, minDist = Infinity;

      $$('.sc-stage-block').forEach(sec => {
        const dist = Math.abs(sec.offsetTop - headerH - scrollPos);
        if (dist < minDist) { minDist = dist; active = sec; }
      });

      navLinks.forEach(l => l.classList.remove('is-active'));
      if (active) {
        const link = navLinks.find(l => l.dataset.stageLink === active.dataset.stage);
        if (link) link.classList.add('is-active');
      }
    }

    document.addEventListener('click', e => {
      const stageLink = e.target.closest('.sc-stages__link');
      if (!stageLink) return;
      e.preventDefault();
      const target = $(stageLink.getAttribute('href'));
      scrollBox = $('.sc-scroll');
      if (!target || !scrollBox) return;
      const headerH = document.querySelector('.sc-header')?.offsetHeight || 0;
      scrollBox.scrollTo({ top: target.offsetTop - headerH - 15, behavior: 'smooth' });
    });

    // первичная компенсация скроллбара
    updateScrollbarVar();

    updateActiveStage();
    if (scrollBox) scrollBox.addEventListener('scroll', updateActiveStage);

    const adminBtn   = $('#admin-add-service-btn');
    const adminModal = $('#add-service-modal');

    if (adminBtn && adminModal) {
      const rolesMap = window.__CATALOG_ROLES__ || {};
      const roleIds  = window.__CATALOG_ROLE_IDS__ || [];

      let addedCount = 0;
      const counterBadge = $('#admin-services-counter');
      const createdList = $('#admin-created-list');

      const setAddedCount = (n) => {
        addedCount = n;
        if (!counterBadge) return;
        if (addedCount > 0) {
          counterBadge.style.display = '';
          counterBadge.textContent = `Добавлено: ${addedCount}`;
        } else {
          counterBadge.style.display = 'none';
          counterBadge.textContent = '';
        }
      };

      const addCreatedItem = ({name, sectionLabel}) => {
        if (!createdList) return;
        createdList.style.display = '';
        const div = document.createElement('div');
        div.className = 'sc-admin-created__item';
        div.innerHTML = `
          <div class="sc-admin-created__left">
            <div class="sc-admin-created__title">${BX.util.htmlspecialchars(name)}</div>
            ${sectionLabel ? `<div class="sc-admin-created__meta">${BX.util.htmlspecialchars(sectionLabel)}</div>` : ''}
          </div>
          <div class="sc-badge sc-badge--success">Готово</div>
        `;
        createdList.appendChild(div);
      };

      const resetAdminForm = () => {
        const adminSectionSelect = $('#admin-section-select');
        $('#admin-service-name').value = '';
        if (adminSectionSelect) adminSectionSelect.selectedIndex = 0;
        $('#admin-min-criteria').value = '';
        $('#admin-roles-table tbody').innerHTML = '';
        updateAdminCriteriaVisibility();
        addAdminRole();
        $('#admin-service-name')?.focus();
      };

      window.closeAdminModal = () => adminModal.classList.remove('is-open');

      const adminSectionSelectEl = $('#admin-section-select');
      if (adminSectionSelectEl) {
        adminSectionSelectEl.addEventListener('change', () => {
          updateAdminCriteriaVisibility();
        });
      }

      adminBtn.addEventListener('click', () => {
        adminModal.classList.add('is-open');
        // новый сеанс добавления
        setAddedCount(0);
        if (createdList) { createdList.innerHTML = ''; createdList.style.display = 'none'; }
        resetAdminForm();
      });

      function addAdminRole() {
        const tbody = $('#admin-roles-table tbody');
        const tr    = document.createElement('tr');
        let options = '<option value="">Выберите роль…</option>';
        roleIds.forEach((id, i) => {
          options += `<option value="${id}">${BX.util.htmlspecialchars(rolesMap[id] || rolesMap[i] || '')}</option>`;
        });
        tr.innerHTML = `
          <td><select class="sc-input sc-input--select-sm">${options}</select></td>
          <td><input type="text" class="sc-input" placeholder="Результат"></td>
          <td><input type="number" min="0" class="sc-input sc-input--number" value="0"></td>
          <td><button class="sc-btn-icon sc-btn-icon--danger" title="Удалить">&times;</button></td>`;
        tbody.appendChild(tr);
        tr.querySelector('.sc-btn-icon--danger').onclick = () => { tr.remove(); };
      }

      $('#admin-add-role-btn').addEventListener('click', addAdminRole);

      const getSectionLabel = () => {
        const sel = $('#admin-section-select');
        const opt = sel?.selectedOptions ? sel.selectedOptions[0] : null;
        return (opt?.textContent || '').trim();
      };

      const submitAdminService = ({andCreateAnother}) => {
        const name      = $('#admin-service-name').value.trim();
        const sectionId = $('#admin-section-select').value;
        const criteria  = $('#admin-min-criteria').value.trim();

        if (!name)      { showCatalogAlert('Укажите название'); return; }
        if (!sectionId) { showCatalogAlert('Выберите раздел'); return; }

        const rolesData = [];
        const seenRoles = new Set();
        let dupError = '';


        $$('#admin-roles-table tbody tr').forEach(tr => {
          const sel = tr.querySelector('select');
          const inp = tr.querySelectorAll('input');
          const roleId = sel?.value ? String(sel.value) : '';

          if (!roleId) return;

          if (seenRoles.has(roleId)) {
            const roleName = (window.__CATALOG_ROLES__ && window.__CATALOG_ROLES__[roleId]) ? window.__CATALOG_ROLES__[roleId] : roleId;
            dupError = `Роль "${roleName}" добавлена дважды. Удалите дубликат.`;
            return;
          }
          seenRoles.add(roleId);

          rolesData.push({
            roleId: roleId,
            result: (inp[0]?.value || '').trim(),
            hours: parseInt(inp[1]?.value, 10) || 0
          });
        });

        if (dupError) { showCatalogAlert(dupError); return; }

        if (!rolesData.length) { showCatalogAlert('Добавьте хотя бы одну роль'); return; }

        const btnMain = $('#admin-save-btn');
        const btnMore = $('#admin-save-add-btn');
        [btnMain, btnMore].forEach(b => { if (b) b.disabled = true; });
        if (btnMain) btnMain.textContent = 'Сохранение…';
        if (btnMore) btnMore.textContent = 'Сохранение…';

        BX.ajax.post(location.href, {
          ajax: 'Y',
          sessid: BX.bitrix_sessid(),
          action: 'createService',
          serviceName: name, sectionId, minCriteria: criteria, roles: rolesData
        }, resp => {
          try {
            const d = JSON.parse(resp);
            if (d.success) {
              setAddedCount(addedCount + 1);
              addCreatedItem({ name, sectionLabel: getSectionLabel() });

              if (andCreateAnother) {
                resetAdminForm();
              } else {
                // финал: показываем статус и перезагружаем страницу
                closeAdminModal();
                showCatalogAlert(`Добавлено услуг: ${addedCount}`);
                setTimeout(() => { location.reload(); }, 1200);
              }
            } else {
              showCatalogAlert(d.error || 'Ошибка');
            }
          } catch (e) { showCatalogAlert('Ошибка сервера'); }
          if (btnMain) btnMain.disabled = false;
          if (btnMore) btnMore.disabled = false;
          if (btnMain) btnMain.textContent = 'Сохранить';
          if (btnMore) btnMore.textContent = 'Сохранить и создать ещё';
        });
      };

      $('#admin-save-btn').addEventListener('click', () => submitAdminService({andCreateAnother:false}));
      $('#admin-save-add-btn').addEventListener('click', () => submitAdminService({andCreateAnother:true}));
    }

    /* ==============================================================
       НАЗНАЧЕНИЯ (assignments) — изменение специалиста, часов, добавление/удаление
       ============================================================== */

    const ajax = (data) => new Promise((resolve) => {
      BX.ajax.post(location.href, { ajax: 'Y', sessid: BX.bitrix_sessid(), ...data }, (resp) => {
        try { resolve(JSON.parse(resp)); } catch { resolve({ success: 0, error: 'Ошибка ответа' }); }
      });
    });

    const updateAssignmentAvatar = (assignmentEl) => {
      const sel  = assignmentEl.querySelector('.assignment-spec');
      const ava  = assignmentEl.querySelector('.sc-assignment__avatar');
      if (!sel || !ava) return;
      const opt   = sel.selectedOptions[0];
      const photo = opt?.dataset.photo || '';
      const init  = opt?.dataset.initial || '?';
      if (photo) {
        if (ava.tagName !== 'IMG') {
          const img = document.createElement('img');
          img.className = 'sc-assignment__avatar';
          img.alt = '';
          img.src = photo;
          ava.replaceWith(img);
        } else {
          ava.src = photo;
          ava.classList.remove('sc-assignment__avatar--ph', 'sc-assignment__avatar--empty');
        }
      } else {
        if (ava.tagName === 'IMG') {
          const span = document.createElement('span');
          span.className = 'sc-assignment__avatar sc-assignment__avatar--ph' + (sel.value ? '' : ' sc-assignment__avatar--empty');
          span.textContent = sel.value ? init : '?';
          ava.replaceWith(span);
        } else {
          ava.textContent = sel.value ? init : '?';
          ava.classList.toggle('sc-assignment__avatar--empty', !sel.value);
        }
      }
    };

    document.addEventListener('change', async (e) => {
      const isSpec = e.target.matches('.assignment-spec');
      const isHrs  = e.target.matches('.assignment-hours');
      if (!isSpec && !isHrs) return;

      const assignmentEl = e.target.closest('.sc-assignment');
      const roleBlock    = e.target.closest('.sc-role-block');
      const serviceId    = roleBlock?.dataset.service;
      const roleId       = roleBlock?.dataset.role;
      const assignmentId = assignmentEl?.dataset.assignmentId;
      if (!assignmentId || !serviceId || !roleId) return;

      if (isSpec) updateAssignmentAvatar(assignmentEl);
      updateAssignmentCostCell(assignmentEl);
      updateServiceCostInRow(serviceId);

      const payload = { action: 'updateAssignment', serviceId, roleId, assignmentId };
      if (isSpec) payload.specialistId = e.target.value;
      if (isHrs)  payload.hours        = Math.max(0, parseFloat(e.target.value) || 0);

      const d = await ajax(payload);
      if (d.success) {
        updateTotal(d.total);
      } else {
        showCatalogAlert(d.error || 'Не удалось сохранить назначение');
      }
    });

    document.addEventListener('click', async (e) => {
      const addBtn = e.target.closest('.assignment-add');
      if (addBtn) {
        const roleBlock = addBtn.closest('.sc-role-block');
        const serviceId = roleBlock?.dataset.service;
        const roleId    = roleBlock?.dataset.role;
        if (!serviceId || !roleId) return;
        addBtn.disabled = true;
        const d = await ajax({ action: 'addAssignment', serviceId, roleId });
        addBtn.disabled = false;
        if (d.success) {
          // Простейший вариант: перерисовать страницу. Альтернатива — клонировать DOM.
          location.reload();
        } else {
          showCatalogAlert(d.error || 'Не удалось добавить назначение');
        }
        return;
      }

      const rmBtn = e.target.closest('.assignment-remove');
      if (rmBtn) {
        const assignmentEl = rmBtn.closest('.sc-assignment');
        const roleBlock    = rmBtn.closest('.sc-role-block');
        const serviceId    = roleBlock?.dataset.service;
        const roleId       = roleBlock?.dataset.role;
        const assignmentId = assignmentEl?.dataset.assignmentId;
        if (!serviceId || !roleId || !assignmentId) return;
        rmBtn.disabled = true;
        const d = await ajax({ action: 'removeAssignment', serviceId, roleId, assignmentId });
        if (d.success) {
          assignmentEl.remove();
          updateServiceCostInRow(serviceId);
          updateTotal(d.total);
        } else {
          showCatalogAlert(d.error || 'Не удалось убрать назначение');
          rmBtn.disabled = false;
        }
        return;
      }
    });

    /* ==============================================================
       МОДАЛКА КОМАНДЫ ПРОЕКТА
       ============================================================== */

    const teamModal = $('#team-modal');
    const teamBtn   = $('#open-team-modal');
    const teamCountEl = teamBtn?.querySelector('.sc-team-btn__count');

    const openTeamModal = () => { if (teamModal) teamModal.classList.add('is-open'); };
    const closeTeamModal = () => { if (teamModal) teamModal.classList.remove('is-open'); };

    teamBtn?.addEventListener('click', openTeamModal);
    document.addEventListener('click', (e) => {
      if (e.target.closest('[data-open-team]')) { e.preventDefault(); openTeamModal(); }
      if (e.target.closest('[data-close-team]')) { e.preventDefault(); closeTeamModal(); }
    });

    // Поиск пользователей с debounce
    let searchTimer = null;
    const teamSearchInput  = $('#team-user-search');
    const teamSearchResults = $('#team-user-results');
    const teamUserIdInput   = $('#team-user-id');
    const teamUserSelected  = $('#team-user-selected');

    const renderUserResults = (users) => {
      if (!teamSearchResults) return;
      teamSearchResults.innerHTML = '';
      if (!users.length) {
        teamSearchResults.innerHTML = '<div class="sc-team-add__no-results">Ничего не найдено</div>';
        return;
      }
      users.forEach(u => {
        const row = document.createElement('div');
        row.className = 'sc-team-add__user-row';
        row.dataset.userId = u.id;
        row.innerHTML = `
          ${u.avatar ? `<img src="${u.avatar}" class="sc-team-row__avatar">` : `<span class="sc-team-row__avatar sc-team-row__avatar--ph">${BX.util.htmlspecialchars(u.name.charAt(0))}</span>`}
          <span>${BX.util.htmlspecialchars(u.name)}</span>
        `;
        row.addEventListener('click', () => {
          if (teamUserIdInput) teamUserIdInput.value = u.id;
          if (teamUserSelected) {
            teamUserSelected.style.display = '';
            teamUserSelected.innerHTML = row.innerHTML + ` <button type="button" class="sc-btn-icon" data-clear-user title="Сбросить" aria-label="Сбросить выбор"><svg width="12" height="12" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M3 3l8 8M11 3l-8 8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg></button>`;
          }
          if (teamSearchInput) { teamSearchInput.value = ''; teamSearchInput.style.display = 'none'; }
          teamSearchResults.innerHTML = '';
        });
        teamSearchResults.appendChild(row);
      });
    };

    teamSearchInput?.addEventListener('input', () => {
      const q = teamSearchInput.value.trim();
      if (searchTimer) clearTimeout(searchTimer);
      if (q.length < 2) { teamSearchResults.innerHTML = ''; return; }
      searchTimer = setTimeout(async () => {
        const d = await ajax({ action: 'searchUsers', search: q });
        if (d.success) renderUserResults(d.users || []);
      }, 250);
    });

    teamUserSelected?.addEventListener('click', (e) => {
      if (e.target.closest('[data-clear-user]')) {
        if (teamUserIdInput) teamUserIdInput.value = '';
        teamUserSelected.style.display = 'none';
        teamUserSelected.innerHTML = '';
        if (teamSearchInput) { teamSearchInput.style.display = ''; teamSearchInput.focus(); }
      }
    });

    // Добавить специалиста
    $('#team-add-btn')?.addEventListener('click', async (e) => {
      e.preventDefault();
      const bitrixUserId = parseInt(teamUserIdInput?.value || '0', 10);
      const rate         = parseInt($('#team-rate-input')?.value || '0', 10);
      const gradeId      = parseInt($('#team-grade-select')?.value || '0', 10);

      if (!bitrixUserId) { showCatalogAlert('Выберите сотрудника из списка'); return; }
      if (!rate || rate < 0) { showCatalogAlert('Укажите ставку ₽/час'); return; }

      const d = await ajax({ action: 'addTeamMember', bitrixUserId, rate, gradeId: gradeId || '' });
      if (d.success) { location.reload(); }
      else { showCatalogAlert(d.error || 'Не удалось добавить специалиста'); }
    });

    // Изменение/удаление существующего специалиста — обработчики в списке
    $('#team-list')?.addEventListener('change', async (e) => {
      const row = e.target.closest('.sc-team-row');
      if (!row) return;
      const specialistId = row.dataset.specId;
      if (e.target.matches('.sc-team-row__rate')) {
        const rate = Math.max(0, parseInt(e.target.value, 10) || 0);
        const d = await ajax({ action: 'updateTeamMember', specialistId, rate });
        if (d.success) { updateTotal(d.total); }
        else { showCatalogAlert(d.error || 'Не удалось сохранить'); }
      } else if (e.target.matches('.sc-team-row__grade')) {
        const gradeId = e.target.value || '';
        const d = await ajax({ action: 'updateTeamMember', specialistId, gradeId });
        if (!d.success) showCatalogAlert(d.error || 'Не удалось сохранить');
      }
    });

    $('#team-list')?.addEventListener('click', async (e) => {
      const rm = e.target.closest('.sc-team-row__remove');
      if (!rm) return;
      const row = rm.closest('.sc-team-row');
      const specialistId = row.dataset.specId;

      showCatalogConfirm('Убрать специалиста из команды? Все его назначения будут сняты.', async (ok) => {
        if (!ok) return;
        const d = await ajax({ action: 'removeTeamMember', specialistId });
        if (d.success) { location.reload(); }
        else { showCatalogAlert(d.error || 'Не удалось удалить'); }
      });
    });

  });
}