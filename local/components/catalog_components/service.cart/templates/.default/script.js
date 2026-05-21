if (window.__SERVICE_CART_JS__) {
    console.warn('service-cart: duplicate load prevented');
} else {
    window.__SERVICE_CART_JS__ = true;

    BX.ready(() => {
        const config = window.__CART_CONFIG__ || {};
        const fmt = (n) => Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 0 });
        const $ = (sel, ctx = document) => ctx.querySelector(sel);
        const $$ = (sel, ctx = document) => [...ctx.querySelectorAll(sel)];

        const state = {
            drafts: config.initialDrafts || { own: [], shared: [], public: [] },
            counts: config.initialCounts || { own: 0, shared: 0, public: 0 },
            activeTab: 'own',
            editDraftId: null,
            editDraftName: null,
            editDraftType: null,
            dirty: false,
            baselineSig: null,
            pendingApplyDraftId: null,
            pendingApplyDraftName: null,
            exitContext: null,
            selectedUsers: [],
            heartbeatTimer: null,
            confirmCountdown: null,
        };

        const modals = $$('.sc-modal');
        modals.forEach((m) => {
            if (m && m.parentElement !== document.body) document.body.appendChild(m);
        });

        const syncModalLock = () => {
            const hasOpen = !!document.querySelector('.sc-modal.is-open');
            document.documentElement.classList.toggle('sc-modal-open', hasOpen);
            document.body.classList.toggle('sc-modal-open', hasOpen);
        };

        const openModal = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.add('is-open');
            syncModalLock();
        };

        const closeModal = (id) => {
            const modal = document.getElementById(id);
            if (!modal) return;
            modal.classList.remove('is-open');
            syncModalLock();
        };

        const closeAllModals = () => {
            modals.forEach((m) => m.classList.remove('is-open'));
            syncModalLock();
        };

        document.addEventListener('click', (e) => {
            const opened = e.target.closest('.sc-modal.is-open');
            if (!opened) return;
            if (e.target === opened) {
                if (opened.dataset.closeOverlay === '0') return;
                opened.classList.remove('is-open');
                syncModalLock();
            }
        });

        let _confirmCallback = null;
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-close]') || e.target.closest('[data-close]')) {
                const modal = e.target.closest('.sc-modal');
                if (modal) {
                    modal.classList.remove('is-open');
                    syncModalLock();
                }
            }
            if (e.target.matches('[data-close-cancel]') || e.target.closest('[data-close-cancel]')) {
                const modal = e.target.closest('.sc-modal');
                if (modal) {
                    modal.classList.remove('is-open');
                    syncModalLock();
                    if (modal.id === 'cart-confirm-modal' && _confirmCallback) {
                        _confirmCallback(false);
                        _confirmCallback = null;
                    }
                }
            }
        });
        document.addEventListener('keydown', (e) => {
            if (e.key !== 'Escape') return;
            const openList = $$('.sc-modal.is-open');
            if (!openList.length) return;
            const topModal = openList[openList.length - 1];
            if (topModal.dataset.closeOverlay === '0') return;
            topModal.classList.remove('is-open');
            syncModalLock();
        });
        const mo = new MutationObserver(syncModalLock);
        modals.forEach((m) => mo.observe(m, { attributes: true, attributeFilter: ['class'] }));

        const showAlert = (msg) => {
            const el = $('#cart-alert-message');
            if (el) el.textContent = msg;
            openModal('cart-alert-modal');
        };

        const showConfirm = (msg, cb, opts = {}) => {
            _confirmCallback = cb;
            const msgEl = $('#cart-confirm-message');
            if (msgEl) msgEl.textContent = msg;
            const okBtn = $('#cart-confirm-ok');
            if (okBtn) {
                okBtn.textContent = opts.okText || 'OK';
                okBtn.classList.remove('sc-btn--primary', 'sc-btn--danger');
                okBtn.classList.add(opts.danger ? 'sc-btn--danger' : 'sc-btn--primary');
            }
            openModal('cart-confirm-modal');
        };

        $('#cart-confirm-ok')?.addEventListener('click', () => {
            closeModal('cart-confirm-modal');
            if (_confirmCallback) {
                _confirmCallback(true);
                _confirmCallback = null;
            }
        });

        window.showCartAlert = showAlert;
        window.showCartConfirm = showConfirm;


        const ajax = (action, data = {}) => {
            return new Promise((resolve, reject) => {
                BX.ajax.post(config.pageUrl, { ajax: 'Y', sessid: BX.bitrix_sessid(), action, ...data }, (resp) => {
                    try {
                        resolve(JSON.parse(resp));
                    } catch (e) {
                        reject(e);
                    }
                });
            });
        };

        const recalcGrand = () => {
            let total = 0;
            $$('.cart-card__sum-val').forEach((el) => {
                total += Number(String(el.textContent).replace(/\s+/g, '')) || 0;
            });
            const gv = $('#grand-val');
            if (gv) gv.textContent = fmt(total);
        };

        const updateEmptyState = () => {
            const scroll = $('.cart-scroll');
            if (!scroll) return;
            if (!scroll.querySelector('.cart-card')) {
                scroll.innerHTML = `
                <div class="cart-empty">
                    <div class="cart-empty__icon">🛒</div>
                    <p class="cart-empty__title">Корзина пуста</p>
                    <p class="cart-empty__hint">Добавьте услуги из каталога</p>
                </div>`;
            }
        };

        const clearCartDom = () => {
            $$('.cart-card').forEach((c) => c.remove());
            $$('.cart-section').forEach((s) => s.remove());
            recalcGrand();
            updateEmptyState();
        };

        const getCartSignature = () => {
            const cards = $$('.cart-card').map((card) => {
                const sid = String(card.dataset.id || '');
                const level = String(card.dataset.level || '');
                const roles = $$('.sc-role-block', card)
                    .map((rb) => {
                        const roleId = String(rb.dataset.role || '');
                        const ass = $$('.sc-assignment', rb)
                            .map((a) => {
                                const aid  = String(a.dataset.assignmentId || '');
                                const spec = String(a.querySelector('.assignment-spec')?.value || '');
                                const hrs  = String(a.querySelector('.assignment-hours')?.value || '');
                                return [aid, spec, hrs].join(':');
                            })
                            .sort()
                            .join(',');
                        return roleId + '=' + ass;
                    })
                    .sort()
                    .join('|');
                return [sid, level, roles].join('#');
            }).sort();
            // также подмешиваем команду, чтобы изменение ставок тоже считалось dirty
            const team = $$('#team-list .sc-team-row').map((r) => {
                const id   = String(r.dataset.specId || '');
                const rate = String(r.querySelector('.sc-team-row__rate')?.value || '');
                const grd  = String(r.querySelector('.sc-team-row__grade')?.value || '');
                return [id, rate, grd].join(':');
            }).sort().join(';');
            return cards.join('~') + '@@' + team;
        };

        const storageKeys = {
            id: 'sc_editDraftId',
            name: 'sc_editDraftName',
            type: 'sc_editDraftType',
            dirty: 'sc_editDraftDirty',
            baseline: 'sc_editDraftBaselineSig',
            // For heartbeat restore
            hbId: 'activeDraftId',
            hbName: 'activeDraftName',
        };

        const refreshEditUI = () => {
            const bar = $('#draft-edit-bar');
            const label = $('#draft-edit-label');
            const dirtyBadge = $('#draft-dirty-badge');
            if (!bar) return;
            const isEditing = !!state.editDraftId;
            bar.style.display = isEditing ? '' : 'none';
            if (label) {
                if (isEditing) {
                    label.textContent = state.editDraftName ? `Вы редактируете: «${state.editDraftName}»` : 'Вы редактируете черновик';
                } else {
                    label.textContent = '';
                }
            }
            if (dirtyBadge) {
                dirtyBadge.style.display = isEditing && state.dirty ? '' : 'none';
            }
        };

        const setEditingState = ({ id, name, type }) => {
            state.editDraftId = String(id);
            state.editDraftName = name || '';
            state.editDraftType = type || 'private';
            state.dirty = false;
            state.baselineSig = null;
            sessionStorage.setItem(storageKeys.id, String(id));
            sessionStorage.setItem(storageKeys.name, name || '');
            sessionStorage.setItem(storageKeys.type, type || 'private');
            sessionStorage.setItem(storageKeys.dirty, '0');
            sessionStorage.removeItem(storageKeys.baseline);
            refreshEditUI();
        };

        const markDirty = () => {
            if (!state.editDraftId) return;
            if (state.dirty) return;
            state.dirty = true;
            sessionStorage.setItem(storageKeys.dirty, '1');
            refreshEditUI();
        };

        const setClean = () => {
            state.dirty = false;
            sessionStorage.setItem(storageKeys.dirty, '0');
            // Update baseline to current cart
            state.baselineSig = getCartSignature();
            sessionStorage.setItem(storageKeys.baseline, state.baselineSig);
            refreshEditUI();
        };

        const clearEditingState = () => {
            state.editDraftId = null;
            state.editDraftName = null;
            state.editDraftType = null;
            state.dirty = false;
            state.baselineSig = null;
            sessionStorage.removeItem(storageKeys.id);
            sessionStorage.removeItem(storageKeys.name);
            sessionStorage.removeItem(storageKeys.type);
            sessionStorage.removeItem(storageKeys.dirty);
            sessionStorage.removeItem(storageKeys.baseline);
            stopCartSignatureObserver();
            refreshEditUI();
        };

        let cartSigObserver = null;
        let cartSigDebounce = null;

        const startCartSignatureObserver = () => {
            if (cartSigObserver) return;
            const root = $('.cart-scroll') || document.body;
            if (!root) return;
            const check = () => {
                if (!state.editDraftId || state.dirty) return;
                const current = getCartSignature();
                const baseline = state.baselineSig || sessionStorage.getItem(storageKeys.baseline) || '';
                if (baseline && current !== baseline) {
                    markDirty();
                }
            };
            cartSigObserver = new MutationObserver(() => {
                if (cartSigDebounce) clearTimeout(cartSigDebounce);
                cartSigDebounce = setTimeout(check, 120);
            });
            cartSigObserver.observe(root, {
                subtree: true,
                childList: true,
                characterData: true,
                attributes: true,
                attributeFilter: ['data-level', 'value', 'class'],
            });
        };

        const stopCartSignatureObserver = () => {
            if (cartSigObserver) {
                cartSigObserver.disconnect();
                cartSigObserver = null;
            }
            if (cartSigDebounce) {
                clearTimeout(cartSigDebounce);
                cartSigDebounce = null;
            }
        };

        const stopHeartbeat = () => {
            if (state.heartbeatTimer) {
                clearInterval(state.heartbeatTimer);
                state.heartbeatTimer = null;
            }
            if (state.confirmCountdown) {
                clearTimeout(state.confirmCountdown);
                state.confirmCountdown = null;
            }
            sessionStorage.removeItem(storageKeys.hbId);
            sessionStorage.removeItem(storageKeys.hbName);
        };

        const showHeartbeatConfirm = () => {
            const draftName = state.editDraftName || 'черновик';
            $('#heartbeat-draft-name').textContent = draftName;
            $('#heartbeat-timeout').textContent = Math.round((config.confirmTimeout || 600) / 60);
            const timerBar = $('#heartbeat-timer-bar');
            if (timerBar) {
                timerBar.style.width = '100%';
                timerBar.style.transition = `width ${(config.confirmTimeout || 600)}s linear`;
                setTimeout(() => {
                    timerBar.style.width = '0%';
                }, 50);
            }
            openModal('heartbeat-modal');
            state.confirmCountdown = setTimeout(() => {
                closeModal('heartbeat-modal');
                finishEditing(true);
            }, (config.confirmTimeout || 600) * 1000);
        };

        const startHeartbeat = (draftId, draftName) => {
            stopHeartbeat();
            state.heartbeatTimer = setInterval(() => {
                showHeartbeatConfirm();
            }, (config.heartbeatInterval || 900) * 1000);
            sessionStorage.setItem(storageKeys.hbId, String(draftId));
            sessionStorage.setItem(storageKeys.hbName, draftName || '');
        };

        const finishEditing = async (save = true) => {
            const draftId = state.editDraftId;
            if (!draftId) return;
            try {
                if (save) {
                    await ajax('unlockAndSaveDraft', { draft_id: draftId });
                } else {
                    await ajax('unlockDraft', { draft_id: draftId });
                }
            } catch (e) {
                console.error('finishEditing error:', e);
            }
            stopHeartbeat();
            clearEditingState();
        };

        $('#heartbeat-continue')?.addEventListener('click', async () => {
            if (state.confirmCountdown) {
                clearTimeout(state.confirmCountdown);
                state.confirmCountdown = null;
            }
            closeModal('heartbeat-modal');
            if (state.editDraftId) {
                await ajax('heartbeat', { draft_id: state.editDraftId });
            }
        });

        $('#heartbeat-stop')?.addEventListener('click', () => {
            if (state.confirmCountdown) {
                clearTimeout(state.confirmCountdown);
                state.confirmCountdown = null;
            }
            closeModal('heartbeat-modal');
            finishEditing(true);
        });

        window.addEventListener('beforeunload', () => {
            if (state.editDraftId && state.editDraftType && state.editDraftType !== 'private') {
                navigator.sendBeacon(
                    config.pageUrl,
                    new URLSearchParams({
                        ajax: 'Y',
                        action: 'unlockAndSaveDraft',
                        draft_id: state.editDraftId,
                    })
                );
            }
        });

        document.addEventListener('click', (ev) => {
            const togBtn = ev.target.closest('.cart-card__toggle');
            if (togBtn) {
                const card = togBtn.closest('.cart-card');
                const isOpen = card.classList.toggle('is-open');
                togBtn.textContent = isOpen ? 'Скрыть' : 'Подробнее';
                return;
            }
            const delBtn = ev.target.closest('.cart-card__remove');
            if (delBtn) {
                const sid = delBtn.dataset.id;
                const card = delBtn.closest('.cart-card');
                showConfirm(
                    'Удалить услугу из корзины?',
                    async (ok) => {
                        if (!ok) return;
                        const r = await ajax('removeService', { serviceId: sid });
                        if (r && r.success === false) {
                            showAlert(r.error || 'Ошибка');
                            return;
                        }
                        card?.remove();
                        recalcGrand();
                        updateEmptyState();
                        $$('.cart-section').forEach((sec) => {
                            if (!sec.querySelector('.cart-card')) sec.remove();
                        });
                        markDirty();
                    },
                    { danger: true, okText: 'Удалить' }
                );
                return;
            }
            if (ev.target.closest('#btn-clear')) {
                showConfirm(
                    'Удалить все услуги из корзины?',
                    async (ok) => {
                        if (!ok) return;
                        const r = await ajax('clearCart');
                        if (r && r.success === false) {
                            showAlert(r.error || 'Ошибка');
                            return;
                        }
                        clearCartDom();
                        markDirty();
                    },
                    { danger: true, okText: 'Очистить' }
                );
                return;
            }
        });

        $('#export-btn')?.addEventListener('click', () => {
            if (!$('.cart-card')) {
                showAlert('Корзина пуста');
                return;
            }
            $('#project-name-input').value = '';
            openModal('export-modal');
            $('#project-name-input').focus();
        });

        $('#export-submit')?.addEventListener('click', () => {
            const name = $('#project-name-input').value.trim();
            if (!name) {
                showAlert('Введите название проекта');
                return;
            }
            closeModal('export-modal');
            window.location.href = config.pageUrl + '?action=exportExcel&project_name=' + encodeURIComponent(name);
        });

        $('#project-name-input')?.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') $('#export-submit')?.click();
        });

        const findDraftById = (id) => {
            const intId = parseInt(id, 10);
            for (const type of ['own', 'shared', 'public']) {
                const found = state.drafts[type]?.find((d) => d.ID === intId);
                if (found) return found;
            }
            return null;
        };

        const renderDraftCard = (draft, isOwnTab) => {
            const lockHtml = draft.IS_LOCKED && !draft.LOCKED_BY_ME
                ? `<div class="draft-card__lock">🔒 Редактирует ${BX.util.htmlspecialchars(draft.LOCKED_BY_NAME)}</div>`
                : '';
            const ownerHtml = !isOwnTab
                ? `<div class="draft-card__owner">
                    ${draft.OWNER_PHOTO ? `<img src="${draft.OWNER_PHOTO}" class="draft-card__avatar">` : ''}
                    <span>${BX.util.htmlspecialchars(draft.OWNER_NAME)}</span>
                  </div>`
                : '';
            const typeBadge = draft.TYPE !== 'private'
                ? `<span class="draft-card__type draft-card__type--${draft.TYPE}">${BX.util.htmlspecialchars(draft.TYPE_LABEL)}</span>`
                : '';
            const isLocked = draft.IS_LOCKED && !draft.LOCKED_BY_ME;
            const applyBtnClass = isLocked ? 'sc-btn--ghost disabled' : 'sc-btn--primary';
            const applyBtnDisabled = isLocked ? 'disabled' : '';

            let actionsHtml = `
                <button class="sc-btn ${applyBtnClass} sc-btn--sm draft-apply-btn"
                        data-id="${draft.ID}"
                        data-name="${BX.util.htmlspecialchars(draft.NAME)}"
                        ${applyBtnDisabled}>
                    Применить
                </button>
            `;

            if (draft.IS_OWNER) {
                actionsHtml += `
                    <button class="sc-btn sc-btn--ghost sc-btn--sm draft-edit-btn" data-id="${draft.ID}" title="Настройки">⚙️</button>
                    <button class="sc-btn sc-btn--ghost sc-btn--sm draft-delete-btn" data-id="${draft.ID}" title="Удалить">🗑️</button>
                `;
            }

            return `
                <div class="draft-card" data-id="${draft.ID}">
                    <div class="draft-card__main">
                        <div class="draft-card__info">
                            <div class="draft-card__header">
                                <span class="draft-card__name">${BX.util.htmlspecialchars(draft.NAME)}</span>
                                ${typeBadge}
                            </div>
                            <div class="draft-card__meta">
                                <span class="draft-card__date">${draft.DATE_CREATE}</span>
                                ${ownerHtml}
                            </div>
                            ${lockHtml}
                        </div>
                        <div class="draft-card__actions">
                            ${actionsHtml}
                        </div>
                    </div>
                </div>
            `;
        };

        const renderDraftsList = (drafts, containerId, isOwnTab = false) => {
            const container = document.getElementById(containerId);
            if (!container) return;
            if (!drafts || drafts.length === 0) {
                container.innerHTML = `<div class="drafts-empty">Нет черновиков</div>`;
                return;
            }
            container.innerHTML = drafts.map((d) => renderDraftCard(d, isOwnTab)).join('');
        };

        const refreshDraftsUI = () => {
            renderDraftsList(state.drafts.own, 'drafts-list-own', true);
            renderDraftsList(state.drafts.shared, 'drafts-list-shared', false);
            renderDraftsList(state.drafts.public, 'drafts-list-public', false);
            $('#tab-count-own').textContent = state.counts.own;
            $('#tab-count-shared').textContent = state.counts.shared;
            $('#tab-count-public').textContent = state.counts.public;
            const totalCount = state.counts.own + state.counts.shared + state.counts.public;
            const badge = $('#drafts-btn .sc-badge');
            if (badge) {
                badge.textContent = totalCount;
                badge.style.display = totalCount > 0 ? '' : 'none';
            }
        };

        const loadDrafts = async () => {
            try {
                const resp = await ajax('getDraftsList');
                if (resp.success) {
                    state.drafts = resp.drafts;
                    state.counts = resp.counts;
                    refreshDraftsUI();
                }
            } catch (e) {
                console.error('Load drafts error:', e);
            }
        };

        document.addEventListener('click', (e) => {
            const tabBtn = e.target.closest('.drafts-tabs__item');
            if (!tabBtn) return;
            const tab = tabBtn.dataset.tab;
            if (!tab) return;
            state.activeTab = tab;
            $$('.drafts-tabs__item').forEach((t) => t.classList.remove('is-active'));
            tabBtn.classList.add('is-active');
            $$('[data-tab-content]').forEach((c) => {
                c.style.display = c.dataset.tabContent === tab ? '' : 'none';
            });
        });

        $('#drafts-btn')?.addEventListener('click', () => {
            loadDrafts().then(() => openModal('drafts-modal'));
        });

        document.addEventListener('click', (e) => {
            const applyBtn = e.target.closest('.draft-apply-btn');
            if (!applyBtn || applyBtn.disabled) return;
            const targetId = String(applyBtn.dataset.id || '');
            const targetName = String(applyBtn.dataset.name || '');
            if (state.editDraftId && String(state.editDraftId) !== targetId) {
                openExitModal({ mode: 'switch', nextDraftId: targetId, nextDraftName: targetName });
                return;
            }
            state.pendingApplyDraftId = targetId;
            state.pendingApplyDraftName = targetName;
            $('#draft-apply-name').textContent = state.pendingApplyDraftName;
            openModal('draft-apply-modal');
        });

        $('#draft-apply-submit')?.addEventListener('click', async () => {
            const draftId = state.pendingApplyDraftId;
            if (!draftId) return;
            const draft = findDraftById(draftId);
            const draftType = draft?.TYPE || 'private';
            const draftName = draft?.NAME || state.pendingApplyDraftName || '';
            closeAllModals();
            setEditingState({ id: draftId, name: draftName, type: draftType });
            if (draftType !== 'private') {
                const lockResp = await ajax('lockDraft', { draft_id: draftId });
                if (!lockResp.success) {
                    showAlert(lockResp.error || 'Не удалось заблокировать черновик');
                    clearEditingState();
                    return;
                }
                startHeartbeat(draftId, draftName);
            }
            window.location.href = config.pageUrl + '?load_draft=' + encodeURIComponent(draftId);
        });

        $('#draft-save-btn')?.addEventListener('click', () => {
            if (!$('.cart-card')) {
                showAlert('Корзина пуста. Добавьте услуги перед сохранением.');
                return;
            }
            $('#draft-form-id').value = '';
            $('#draft-form-mode').value = 'create';
            $('#draft-form-title').textContent = 'Новый черновик';
            $('#draft-form-name').value = '';
            $('input[name="draft_type"][value="private"]').checked = true;
            state.selectedUsers = [];
            renderSelectedUsers();
            updateUsersGroupVisibility();
            const searchInput = $('#user-search-input');
            const searchResults = $('#user-search-results');
            if (searchInput) searchInput.value = '';
            if (searchResults) {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
            openModal('draft-form-modal');
            setTimeout(() => initUserSelector(), 60);
            $('#draft-form-name').focus();
        });

        document.addEventListener('click', (e) => {
            const editBtn = e.target.closest('.draft-edit-btn');
            if (!editBtn) return;
            const draftId = editBtn.dataset.id;
            const draft = findDraftById(draftId);
            if (!draft) return;
            $('#draft-form-id').value = draftId;
            $('#draft-form-mode').value = 'edit';
            $('#draft-form-title').textContent = 'Настройки черновика';
            $('#draft-form-name').value = draft.NAME;
            const typeRadio = $(`input[name="draft_type"][value="${draft.TYPE}"]`);
            if (typeRadio) typeRadio.checked = true;
            setSelectedUsersFrom(draft.ACCESS_USERS);
            renderSelectedUsers();
            updateUsersGroupVisibility();
            const searchInput = $('#user-search-input');
            const searchResults = $('#user-search-results');
            if (searchInput) searchInput.value = '';
            if (searchResults) {
                searchResults.style.display = 'none';
                searchResults.innerHTML = '';
            }
            closeModal('drafts-modal');
            openModal('draft-form-modal');
            setTimeout(() => initUserSelector(), 60);
        });

        document.addEventListener('click', (e) => {
            const deleteBtn = e.target.closest('.draft-delete-btn');
            if (!deleteBtn) return;
            const draftId = String(deleteBtn.dataset.id || '');
            showConfirm(
                'Удалить черновик?',
                async (ok) => {
                    if (!ok) return;
                    const resp = await ajax('deleteDraft', { draft_id: draftId });
                    if (!resp.success) {
                        showAlert(resp.error || 'Ошибка удаления');
                        return;
                    }

                    if (state.editDraftId && String(state.editDraftId) === String(draftId)) {
                        stopHeartbeat();
                        clearEditingState();
                        try {
                            const r = await ajax('clearCart');
                            if (r && r.success === false) {
                                showAlert(r.error || 'Не удалось очистить корзину');
                            } else {
                                clearCartDom();
                                showAlert('Черновик удалён. Корзина очищена.');
                            }
                        } catch (err) {
                            console.error(err);
                            showAlert('Черновик удалён, но корзину очистить не удалось');
                        }
                    }
                    await loadDrafts();
                },
                { danger: true, okText: 'Удалить' }
            );
        });


        const openExitModal = ({ mode = 'exit', nextDraftId = null, nextDraftName = '' } = {}) => {
            if (!state.editDraftId) return;
            state.exitContext = { mode, nextDraftId, nextDraftName };
            const currentName = state.editDraftName ? `«${state.editDraftName}»` : 'черновик';
            const msgEl = $('#draft-exit-message');
            const hintEl = $('#draft-exit-hint');
            const btnSave = $('#draft-exit-save');
            const btnNoSave = $('#draft-exit-nosave');
            if (msgEl) {
                if (mode === 'switch' && nextDraftId) {
                    msgEl.textContent = `Вы завершаете редактирование черновика ${currentName}. Сохранить изменения перед переходом к «${nextDraftName}»?`;
                } else {
                    msgEl.textContent = `Вы завершаете редактирование черновика ${currentName}. Сохранить изменения?`;
                }
            }
            if (hintEl) {
                if (state.dirty) {
                    hintEl.style.display = '';
                    hintEl.textContent = 'Есть несохранённые изменения в корзине.';
                } else {
                    hintEl.style.display = 'none';
                    hintEl.textContent = '';
                }
            }
            if (btnSave) btnSave.textContent = mode === 'switch' ? 'Сохранить и перейти' : 'Сохранить и выйти';
            if (btnNoSave) btnNoSave.textContent = mode === 'switch' ? 'Перейти без сохранения' : 'Выйти без сохранения';
            openModal('draft-exit-modal');
        };

        const applyDraftNow = async (draftId) => {
            const draft = findDraftById(draftId);
            const draftType = draft?.TYPE || 'private';
            const draftName = draft?.NAME || '';

            setEditingState({ id: draftId, name: draftName, type: draftType });

            if (draftType !== 'private') {
                const lockResp = await ajax('lockDraft', { draft_id: draftId });
                if (!lockResp.success) {
                    showAlert(lockResp.error || 'Не удалось заблокировать черновик');
                    clearEditingState();
                    return;
                }
                startHeartbeat(draftId, draftName);
            }
            window.location.href = config.pageUrl + '?load_draft=' + encodeURIComponent(draftId);
        };

        const exitEditing = async ({ save, thenApplyDraftId = null, thenApplyDraftName = '' } = {}) => {
            const currentId = state.editDraftId;
            const currentType = state.editDraftType || 'private';
            closeModal('draft-exit-modal');
            try {
                if (save) {
                    if (currentType !== 'private') {
                        await ajax('unlockAndSaveDraft', { draft_id: currentId });
                    } else if (state.dirty) {
                        await ajax('updateDraftData', { draft_id: currentId });
                    }
                } else {
                    if (currentType !== 'private') {
                        await ajax('unlockDraft', { draft_id: currentId });
                    }
                }
            } catch (e) {
                console.error('exitEditing error:', e);
            }
            stopHeartbeat();
            clearEditingState();

            if (!save && !thenApplyDraftId && currentId) {

                window.location.href = config.pageUrl + '?load_draft=' + encodeURIComponent(currentId);
                return;
            }
            if (thenApplyDraftId) {
                await applyDraftNow(thenApplyDraftId);
            }
        };

        $('#draft-exit-btn')?.addEventListener('click', () => {
            if (!state.editDraftId) return;
            openExitModal({ mode: 'exit' });
        });

        $('#draft-exit-save')?.addEventListener('click', () => {
            const ctx = state.exitContext || { mode: 'exit' };
            exitEditing({ save: true, thenApplyDraftId: ctx.nextDraftId, thenApplyDraftName: ctx.nextDraftName });
            state.exitContext = null;
        });

        $('#draft-exit-nosave')?.addEventListener('click', () => {
            const ctx = state.exitContext || { mode: 'exit' };
            exitEditing({ save: false, thenApplyDraftId: ctx.nextDraftId, thenApplyDraftName: ctx.nextDraftName });
            state.exitContext = null;
        });

        document.addEventListener('click', (e) => {
            if (e.target.closest('#draft-exit-cancel') || e.target.closest('#draft-exit-modal [data-close-cancel]')) {
                state.exitContext = null;
            }
        });


        const updateUsersGroupVisibility = () => {
            const type = $('input[name="draft_type"]:checked')?.value;
            const group = $('#draft-users-group');
            if (group) {
                group.style.display = type === 'shared' ? '' : 'none';
                if (type === 'shared') initUserSelector();
            }
        };


        const normalizeUser = (raw) => ({
            id: parseInt(raw.id || raw.ID || 0, 10),
            name: String(raw.name || raw.NAME || '').trim() || 'Пользователь #' + (raw.id || raw.ID || '?'),
            avatar: String(raw.avatar || raw.photo || raw.PHOTO || ''),
        });


        const isUserSelected = (userId) => {
            const intId = parseInt(userId, 10);
            return state.selectedUsers.some((u) => u.id === intId);
        };


        const addSelectedUser = (raw) => {
            const user = normalizeUser(raw);
            if (!user.id || isUserSelected(user.id)) return false;
            state.selectedUsers.push(user);
            renderSelectedUsers();
            return true;
        };


        const removeSelectedUser = (userId) => {
            const intId = parseInt(userId, 10);
            state.selectedUsers = state.selectedUsers.filter((u) => u.id !== intId);
            renderSelectedUsers();
        };


        const setSelectedUsersFrom = (accessUsers) => {
            state.selectedUsers = Object.values(accessUsers || {})
                .map(normalizeUser)
                .filter((u) => u.id > 0);
        };

        const renderSelectedUsers = () => {
            const container = $('#draft-users-selected');
            if (!container) return;
            if (state.selectedUsers.length === 0) {
                container.innerHTML = '<div class="draft-users-empty">Пользователи не выбраны</div>';
                return;
            }
            container.innerHTML = state.selectedUsers
                .map(
                    (u) => `
                <div class="draft-user-tag" data-id="${u.id}">
                    ${u.avatar ? `<img src="${u.avatar}" class="draft-user-tag__avatar" onerror="this.style.display='none'">` : ''}
                    <span>${BX.util.htmlspecialchars(u.name)}</span>
                    <button type="button" class="draft-user-tag__remove" data-id="${u.id}" title="Убрать">&times;</button>
                </div>`
                )
                .join('');
        };


        $$('input[name="draft_type"]').forEach((radio) => {
            radio.addEventListener('change', updateUsersGroupVisibility);
        });


        document.addEventListener('click', (e) => {
            const removeBtn = e.target.closest('.draft-user-tag__remove');
            if (!removeBtn) return;
            e.preventDefault();
            e.stopPropagation();
            removeSelectedUser(removeBtn.dataset.id);
        });


        let userSearchInitialized = false;
        let userSearchTimeout = null;

        const initUserSelector = () => {
            if (userSearchInitialized) return;
            const input = $('#user-search-input');
            const results = $('#user-search-results');
            const spinner = $('#user-search-spinner');
            if (!input || !results) return;

            input.addEventListener('input', () => {
                clearTimeout(userSearchTimeout);
                const query = input.value.trim();
                if (query.length < 2) {
                    results.style.display = 'none';
                    results.innerHTML = '';
                    if (spinner) spinner.style.display = 'none';
                    return;
                }
                if (spinner) spinner.style.display = '';

                userSearchTimeout = setTimeout(async () => {
                    try {
                        const resp = await ajax('searchUsers', { search: query });
                        if (spinner) spinner.style.display = 'none';

                        if (input.value.trim() !== query) return;

                        if (resp.success && resp.users && resp.users.length > 0) {

                            const filtered = resp.users.filter((u) => !isUserSelected(u.id));
                            if (filtered.length > 0) {
                                results.innerHTML = filtered
                                    .map((u) => {
                                        const nu = normalizeUser(u);
                                        const initial = (nu.name[0] || '?').toUpperCase();
                                        return `
                                        <div class="sc-user-search__item" data-id="${nu.id}" data-name="${BX.util.htmlspecialchars(nu.name)}" data-avatar="${nu.avatar}">
                                            ${
                                                nu.avatar
                                                    ? `<img src="${nu.avatar}" class="sc-user-search__avatar" onerror="this.outerHTML='<span class=\\'sc-user-search__avatar sc-user-search__avatar--empty\\'>${initial}</span>'">`
                                                    : `<span class="sc-user-search__avatar sc-user-search__avatar--empty">${initial}</span>`
                                            }
                                            <span class="sc-user-search__name">${BX.util.htmlspecialchars(nu.name)}</span>
                                        </div>`;
                                    })
                                    .join('');
                            } else {
                                results.innerHTML = '<div class="sc-user-search__empty">Все найденные уже добавлены</div>';
                            }
                            results.style.display = 'block';
                        } else {
                            results.innerHTML = '<div class="sc-user-search__empty">Пользователи не найдены</div>';
                            results.style.display = 'block';
                        }
                    } catch (e) {
                        console.error('User search error:', e);
                        if (spinner) spinner.style.display = 'none';
                        results.innerHTML = '<div class="sc-user-search__empty">Ошибка поиска</div>';
                        results.style.display = 'block';
                    }
                }, 300);
            });

            results.addEventListener('click', (e) => {
                const item = e.target.closest('.sc-user-search__item');
                if (!item) return;
                addSelectedUser({
                    id: item.dataset.id,
                    name: item.dataset.name,
                    avatar: item.dataset.avatar,
                });
                input.value = '';
                results.style.display = 'none';
                results.innerHTML = '';
                input.focus();
            });

            input.addEventListener('keydown', (e) => {
                if (results.style.display === 'none') return;
                const items = [...results.querySelectorAll('.sc-user-search__item')];
                if (!items.length) return;
                const current = results.querySelector('.sc-user-search__item--focused');
                let idx = current ? items.indexOf(current) : -1;

                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    idx = Math.min(idx + 1, items.length - 1);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    idx = Math.max(idx - 1, 0);
                } else if (e.key === 'Enter' && current) {
                    e.preventDefault();
                    current.click();
                    return;
                } else if (e.key === 'Escape') {
                    results.style.display = 'none';
                    return;
                } else {
                    return;
                }

                items.forEach((it) => it.classList.remove('sc-user-search__item--focused'));
                if (items[idx]) {
                    items[idx].classList.add('sc-user-search__item--focused');
                    items[idx].scrollIntoView({ block: 'nearest' });
                }
            });

            document.addEventListener('click', (e) => {
                if (!e.target.closest('#draft-users-search')) {
                    results.style.display = 'none';
                }
            });

            input.addEventListener('focus', () => {
                if (input.value.trim().length >= 2 && results.innerHTML) {
                    results.style.display = 'block';
                }
            });

            userSearchInitialized = true;
        };


        $('#draft-form-submit')?.addEventListener('click', async () => {
            const mode = $('#draft-form-mode').value;
            const name = $('#draft-form-name').value.trim();
            const type = $('input[name="draft_type"]:checked')?.value || 'private';
            const draftId = $('#draft-form-id').value;

            if (!name) {
                showAlert('Введите название черновика');
                return;
            }
            if (type === 'shared' && state.selectedUsers.length === 0) {
                showAlert('Выберите хотя бы одного пользователя для доступа');
                return;
            }

            const accessUsers = type === 'shared'
                ? [...new Set(state.selectedUsers.map((u) => u.id).filter(Boolean))]
                : [];

            let resp;
            if (mode === 'create') {
                resp = await ajax('createDraft', {
                    draft_name: name,
                    draft_type: type,
                    access_users: JSON.stringify(accessUsers),
                });
            } else {
                resp = await ajax('renameDraft', { draft_id: draftId, new_name: name });
                if (resp.success) {
                    resp = await ajax('changeDraftType', {
                        draft_id: draftId,
                        new_type: type,
                        access_users: JSON.stringify(accessUsers),
                    });
                }
            }

            if (resp && resp.success) {
                closeAllModals();
                await loadDrafts();
                showAlert(mode === 'create' ? 'Черновик создан' : 'Черновик обновлён');
            } else {
                showAlert(resp?.error || 'Ошибка сохранения');
            }
        });

        const formModal = $('#draft-form-modal');
        if (formModal) {
            const observer = new MutationObserver(() => {
                if (!formModal.classList.contains('is-open')) {
                    const searchInput = $('#user-search-input');
                    const searchResults = $('#user-search-results');
                    if (searchInput) searchInput.value = '';
                    if (searchResults) {
                        searchResults.style.display = 'none';
                        searchResults.innerHTML = '';
                    }
                }
            });
            observer.observe(formModal, { attributes: true, attributeFilter: ['class'] });
        }

        const restoreEditState = () => {
            const id = sessionStorage.getItem(storageKeys.id);
            const name = sessionStorage.getItem(storageKeys.name);
            const type = sessionStorage.getItem(storageKeys.type);
            const dirty = sessionStorage.getItem(storageKeys.dirty);
            const baseline = sessionStorage.getItem(storageKeys.baseline);

            if (!id) {
                refreshEditUI();
                return;
            }

            state.editDraftId = id;
            state.editDraftName = name || '';
            state.editDraftType = type || 'private';
            state.dirty = dirty === '1';
            state.baselineSig = baseline || null;

            const sig = getCartSignature();
            if (!state.baselineSig) {
                state.baselineSig = sig;
                sessionStorage.setItem(storageKeys.baseline, sig);
            }
            if (!state.dirty && sig !== state.baselineSig) {
                state.dirty = true;
                sessionStorage.setItem(storageKeys.dirty, '1');
            }

            refreshEditUI();
            startCartSignatureObserver();

            if (state.editDraftType !== 'private') {
                const hbId = sessionStorage.getItem(storageKeys.hbId);
                const hbName = sessionStorage.getItem(storageKeys.hbName);
                if (hbId && hbName && String(hbId) === String(id)) {
                    startHeartbeat(hbId, hbName);
                }
            }
        };

        restoreEditState();
        refreshDraftsUI();

        /* ==============================================================
           НАЗНАЧЕНИЯ И КОМАНДА (phase 3)
           ============================================================== */

        const fmtCost = (n) => Math.round(n).toLocaleString('ru-RU');

        const getCardLevelCoeff = (card) => {
            const lv = String(card?.dataset.level || 'medium');
            return lv === 'high' ? 1.3 : lv === 'low' ? 0.77 : 1.0;
        };

        const recalcCardSum = (card) => {
            if (!card) return;
            let base = 0;
            $$('.sc-assignment', card).forEach((a) => {
                const rate  = parseFloat(a.querySelector('.assignment-spec')?.selectedOptions[0]?.dataset.rate) || 0;
                const hours = parseFloat(a.querySelector('.assignment-hours')?.value) || 0;
                base += rate * hours;
            });
            const sum = Math.round(base * getCardLevelCoeff(card));
            const el = card.querySelector('.cart-card__sum-val');
            if (el) el.textContent = fmtCost(sum);
        };

        const updateAssignmentCostCell = (assignmentEl) => {
            const rate  = parseFloat(assignmentEl.querySelector('.assignment-spec')?.selectedOptions[0]?.dataset.rate) || 0;
            const hours = parseFloat(assignmentEl.querySelector('.assignment-hours')?.value) || 0;
            const cell  = assignmentEl.querySelector('.sc-assignment__cost');
            if (cell) cell.textContent = fmtCost(rate * hours) + ' ₽';
        };

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
            const card         = e.target.closest('.cart-card');
            const serviceId    = roleBlock?.dataset.service;
            const roleId       = roleBlock?.dataset.role;
            const assignmentId = assignmentEl?.dataset.assignmentId;
            if (!serviceId || !roleId || !assignmentId) return;

            if (isSpec) updateAssignmentAvatar(assignmentEl);
            updateAssignmentCostCell(assignmentEl);
            recalcCardSum(card);

            const payload = { serviceId, roleId, assignmentId };
            if (isSpec) payload.specialistId = e.target.value;
            if (isHrs)  payload.hours        = Math.max(0, parseFloat(e.target.value) || 0);

            const d = await ajax('updateAssignment', payload);
            if (d.success) {
                if (typeof d.total !== 'undefined') {
                    const gv = $('#grand-val');
                    if (gv) gv.textContent = fmtCost(d.total);
                }
                markDirty();
            } else {
                showAlert(d.error || 'Не удалось сохранить назначение');
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
                const d = await ajax('addAssignment', { serviceId, roleId });
                addBtn.disabled = false;
                if (d.success) {
                    // простой путь — перезагружаем, чтобы заново отрендерить
                    location.reload();
                } else {
                    showAlert(d.error || 'Не удалось добавить назначение');
                }
                return;
            }

            const rmBtn = e.target.closest('.assignment-remove');
            if (rmBtn) {
                const assignmentEl = rmBtn.closest('.sc-assignment');
                const roleBlock    = rmBtn.closest('.sc-role-block');
                const card         = rmBtn.closest('.cart-card');
                const serviceId    = roleBlock?.dataset.service;
                const roleId       = roleBlock?.dataset.role;
                const assignmentId = assignmentEl?.dataset.assignmentId;
                if (!serviceId || !roleId || !assignmentId) return;
                rmBtn.disabled = true;
                const d = await ajax('removeAssignment', { serviceId, roleId, assignmentId });
                if (d.success) {
                    assignmentEl.remove();
                    recalcCardSum(card);
                    const gv = $('#grand-val');
                    if (gv && typeof d.total !== 'undefined') gv.textContent = fmtCost(d.total);
                    markDirty();
                } else {
                    showAlert(d.error || 'Не удалось убрать назначение');
                    rmBtn.disabled = false;
                }
                return;
            }
        });

        /* модалка команды */

        const teamModal = $('#team-modal');
        const openTeamModal = () => teamModal?.classList.add('is-open');
        const closeTeamModal = () => teamModal?.classList.remove('is-open');

        $('#open-team-modal')?.addEventListener('click', openTeamModal);
        document.addEventListener('click', (e) => {
            if (e.target.closest('[data-open-team]')) { e.preventDefault(); openTeamModal(); }
            if (e.target.closest('[data-close-team]')) { e.preventDefault(); closeTeamModal(); }
        });

        let searchTimer = null;
        const teamSearchInput   = $('#team-user-search');
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
            users.forEach((u) => {
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
                const d = await ajax('searchUsers', { search: q });
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

        $('#team-add-btn')?.addEventListener('click', async (e) => {
            e.preventDefault();
            const bitrixUserId = parseInt(teamUserIdInput?.value || '0', 10);
            const rate         = parseInt($('#team-rate-input')?.value || '0', 10);
            const gradeId      = parseInt($('#team-grade-select')?.value || '0', 10);

            if (!bitrixUserId) { showAlert('Выберите сотрудника из списка'); return; }
            if (!rate || rate < 0) { showAlert('Укажите ставку ₽/час'); return; }

            const d = await ajax('addTeamMember', { bitrixUserId, rate, gradeId: gradeId || '' });
            if (d.success) { location.reload(); }
            else { showAlert(d.error || 'Не удалось добавить специалиста'); }
        });

        $('#team-list')?.addEventListener('change', async (e) => {
            const row = e.target.closest('.sc-team-row');
            if (!row) return;
            const specialistId = row.dataset.specId;
            if (e.target.matches('.sc-team-row__rate')) {
                const rate = Math.max(0, parseInt(e.target.value, 10) || 0);
                const d = await ajax('updateTeamMember', { specialistId, rate });
                if (d.success) {
                    const gv = $('#grand-val');
                    if (gv && typeof d.total !== 'undefined') gv.textContent = fmtCost(d.total);
                    markDirty();
                    // карточки услуг тоже надо пересчитать — но проще перерисовать
                    setTimeout(() => location.reload(), 200);
                } else {
                    showAlert(d.error || 'Не удалось сохранить');
                }
            } else if (e.target.matches('.sc-team-row__grade')) {
                const gradeId = e.target.value || '';
                const d = await ajax('updateTeamMember', { specialistId, gradeId });
                if (!d.success) showAlert(d.error || 'Не удалось сохранить');
            }
        });

        $('#team-list')?.addEventListener('click', async (e) => {
            const rm = e.target.closest('.sc-team-row__remove');
            if (!rm) return;
            const row = rm.closest('.sc-team-row');
            const specialistId = row.dataset.specId;

            showConfirm('Убрать специалиста из команды? Все его назначения будут сняты.', async (ok) => {
                if (!ok) return;
                const d = await ajax('removeTeamMember', { specialistId });
                if (d.success) { location.reload(); }
                else { showAlert(d.error || 'Не удалось удалить'); }
            });
        });

        /* уровень обслуживания (radio) */
        document.addEventListener('change', async (e) => {
            if (!e.target.matches('.sc-level input[type="radio"]')) return;
            const radio = e.target;
            const serviceId = radio.closest('.sc-level').dataset.service;
            const level     = radio.value;
            const card      = $(`.cart-card[data-id="${serviceId}"]`);
            if (card) card.dataset.level = level;
            recalcCardSum(card);

            const d = await ajax('updateServiceLevel', { serviceId, level });
            if (d.success) {
                const gv = $('#grand-val');
                if (gv && typeof d.total !== 'undefined') gv.textContent = fmtCost(d.total);
                markDirty();
            } else {
                showAlert(d.error || 'Не удалось обновить уровень');
            }
        });

    });
}