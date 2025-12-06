document.addEventListener('DOMContentLoaded', function () {
    const html = document.documentElement;

    // ==========================
    // Theme init
    // ==========================
    const savedTheme  = localStorage.getItem('theme')  || 'light';
    const savedAccent = localStorage.getItem('accent') || 'blue';
    html.setAttribute('data-theme',  savedTheme);
    html.setAttribute('data-accent', savedAccent);

    const darkToggle   = document.getElementById('darkToggle');
    const accentTheme  = document.getElementById('accentTheme');

    if (accentTheme) {
        accentTheme.value = savedAccent;
    }

    if (darkToggle) {
        darkToggle.addEventListener('click', () => {
            const current = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
            html.setAttribute('data-theme', current);
            localStorage.setItem('theme', current);
        });
    }

    if (accentTheme) {
        accentTheme.addEventListener('change', () => {
            const val = accentTheme.value || 'blue';
            html.setAttribute('data-accent', val);
            localStorage.setItem('accent', val);
        });
    }

    // ==========================
    // Toast helper
    // ==========================
    window.showToast = function (msg) {
        let toast = document.querySelector('.toast');
        if (!toast) {
            toast = document.createElement('div');
            toast.className = 'toast';
            document.body.appendChild(toast);
        }
        toast.textContent = msg;
        toast.style.display = 'block';
        toast.style.opacity = '1';
        setTimeout(() => {
            toast.style.transition = 'opacity 0.4s ease';
            toast.style.opacity = '0';
            setTimeout(() => {
                toast.style.display = 'none';
                toast.style.transition = '';
            }, 400);
        }, 2200);
    };

    // Auto-show toast from data-toast attribute on body
    const toastMsg = document.body.getAttribute('data-toast');
    if (toastMsg) {
        showToast(toastMsg);
    }

    // ==========================
    // DataTables init
    // ==========================
    if (window.jQuery && jQuery.fn.DataTable) {
        jQuery('.datatable').DataTable();
    }

    // ==========================
    // Select2 (works inside modals)
    // ==========================
    if (window.jQuery && jQuery.fn.select2) {
        jQuery('.select2').each(function () {
            const $this       = jQuery(this);
            const $parentModal = $this.closest('.modal'); // if inside modal

            $this.select2({
                width: '100%',
                tags: true,
                placeholder: 'Select or type...',
                dropdownParent: $parentModal.length ? $parentModal : jQuery('body')
            });
        });
    }

    // ==========================
    // Tagify
    // ==========================
    if (window.Tagify) {
        document.querySelectorAll('input.tagify').forEach(function (input) {
            new Tagify(input);
        });
    }

    // ==========================
    // AJAX trade form
    // ==========================
    const tradeForm = document.getElementById('tradeForm');
    if (tradeForm) {
        tradeForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(tradeForm);

            fetch('api/trades.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message || 'Saved');
                    setTimeout(() => window.location.reload(), 1200);
                } else {
                    showToast(data.message || 'Error saving');
                }
            })
            .catch(() => {
                showToast('Network error');
            });
        });
    }

    // ==========================
    // Add Trade modal
    // ==========================
    const addModal    = document.getElementById('addTradeModal');
    const openAddBtn  = document.getElementById('openAddTrade');
    const closeAddBtn = document.getElementById('closeAddTrade');

    function openAddModal() {
        if (addModal) addModal.classList.add('open');
    }
    function closeAddModal() {
        if (addModal) addModal.classList.remove('open');
    }

    if (openAddBtn) {
        openAddBtn.addEventListener('click', openAddModal);
    }
    if (closeAddBtn) {
        closeAddBtn.addEventListener('click', closeAddModal);
    }
    if (addModal) {
        addModal.addEventListener('click', (e) => {
            if (e.target === addModal) closeAddModal();
        });
    }

    // ==========================
    // View Trade modal
    // ==========================
    const viewModal    = document.getElementById('viewTradeModal');
    const closeViewBtn = document.getElementById('closeViewTrade');

    function openViewModal() {
        if (viewModal) viewModal.classList.add('open');
    }
    function closeViewModal() {
        if (viewModal) viewModal.classList.remove('open');
    }

    if (closeViewBtn) {
        closeViewBtn.addEventListener('click', closeViewModal);
    }
    if (viewModal) {
        viewModal.addEventListener('click', (e) => {
            if (e.target === viewModal) closeViewModal();
        });
    }

    // Fill modal when clicking "View"
    document.querySelectorAll('.btn-view-trade').forEach(btn => {
        btn.addEventListener('click', () => {
            const tr = btn.closest('tr');
            if (!tr || !viewModal) return;
            const raw = tr.getAttribute('data-trade');
            if (!raw) return;

            let data;
            try { data = JSON.parse(raw); } catch { return; }

            const set = (id, val) => {
                const el = document.getElementById(id);
                if (el) el.textContent = val ?? '';
            };

            set('v_trade_no',      data.trade_no);
            set('v_trade_date',    data.trade_date);
            set('v_day',           data.day);
            set('v_no_trades',     data.no_trades);
            set('v_opening_bal',   data.opening_bal);
            set('v_closing_bal',   data.closing_bal);
            set('v_profit',        data.profit);
            set('v_loss',          data.loss);
            set('v_setup_type',    data.setup_type);
            set('v_entry_reason',  data.entry_reason);
            set('v_rule_followed', data.rule_followed);
            set('v_emotion',       data.emotion);

            // Strategy tags
            const stratEl = document.getElementById('v_strategy_tags');
            if (stratEl) {
                stratEl.innerHTML = '';
                if (data.strategy_tags) {
                    String(data.strategy_tags).split(',').forEach(t => {
                        t = t.trim();
                        if (!t) return;
                        const span = document.createElement('span');
                        span.className = 'badge';
                        span.textContent = t;
                        stratEl.appendChild(span);
                    });
                }
            }

            // Mistake tags
            const mistakeEl = document.getElementById('v_mistake_tags');
            if (mistakeEl) {
                mistakeEl.innerHTML = '';
                if (data.mistake_tags) {
                    String(data.mistake_tags).split(',').forEach(t => {
                        t = t.trim();
                        if (!t) return;
                        const span = document.createElement('span');
                        span.className = 'badge';
                        span.textContent = t;
                        mistakeEl.appendChild(span);
                    });
                }
            }

            // Screenshot
            const shotEl = document.getElementById('v_screenshot');
            if (shotEl) {
                shotEl.innerHTML = '';
                if (data.screenshot_path) {
                    const a = document.createElement('a');
                    a.href = data.screenshot_path;
                    a.target = '_blank';
                    a.textContent = 'View screenshot';
                    shotEl.appendChild(a);
                } else {
                    shotEl.textContent = '-';
                }
            }

            // Notes
            const notesEl = document.getElementById('v_notes');
            if (notesEl) {
                notesEl.textContent = data.notes ?? '';
            }

            openViewModal();
        });
    });

    // ==========================
    // Auto Day from Date
    // ==========================
    const tradeDateInput = document.getElementById('trade_date');
    const dayInput       = document.getElementById('day');

    if (tradeDateInput && dayInput) {
        const dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];

        function updateDayFromDate() {
            const val = tradeDateInput.value;
            if (!val) {
                dayInput.value = '';
                return;
            }
            // val is "YYYY-MM-DD"
            const d = new Date(val + 'T00:00:00');
            if (!isNaN(d)) {
                dayInput.value = dayNames[d.getDay()];
            }
        }

        tradeDateInput.addEventListener('change', updateDayFromDate);
        tradeDateInput.addEventListener('input', updateDayFromDate);
    }

    // ==========================
    // Auto Profit / Loss from Opening & Closing Bal
    // ==========================
    const openingInput = document.getElementById('opening_bal');
    const closingInput = document.getElementById('closing_bal');
    const profitInput  = document.getElementById('profit');
    const lossInput    = document.getElementById('loss');

    function recalcPL() {
        if (!openingInput || !closingInput || !profitInput || !lossInput) return;

        const open  = parseFloat(openingInput.value);
        const close = parseFloat(closingInput.value);

        if (isNaN(open) || isNaN(close)) {
            profitInput.value = '';
            lossInput.value   = '';
            return;
        }

        const diff = close - open;

        if (diff > 0) {
            profitInput.value = diff.toFixed(2);
            lossInput.value   = '';
        } else if (diff < 0) {
            profitInput.value = '';
            lossInput.value   = Math.abs(diff).toFixed(2);
        } else {
            profitInput.value = '';
            lossInput.value   = '';
        }
    }

    if (openingInput && closingInput) {
        openingInput.addEventListener('input', recalcPL);
        closingInput.addEventListener('input', recalcPL);
    }
});