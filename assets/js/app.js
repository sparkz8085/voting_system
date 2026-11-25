/* ==========================================================================
   CampusVote — interactive behaviour
   Sidebar navigation, toasts, confirmation dialogs, form states, password
   tools, ballot selection, countdowns, print helpers and avatar fallbacks.
   ========================================================================== */

(function () {
    'use strict';

    const body = document.body;

    /* ---------------------------------------------------------------------
       Sidebar navigation (admin + voter shells)
       --------------------------------------------------------------------- */

    const sidebar = document.getElementById('appSidebar');
    const sidebarToggles = document.querySelectorAll('[data-sidebar-toggle]');
    const sidebarClosers = document.querySelectorAll('[data-sidebar-close]');

    function setSidebar(open) {
        if (!sidebar) {
            return;
        }
        body.classList.toggle('sidebar-open', open);
        sidebarToggles.forEach((toggle) => {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (open) {
            const focusTarget = sidebar.querySelector('a, button');
            if (focusTarget) {
                focusTarget.focus({ preventScroll: true });
            }
        }
    }

    sidebarToggles.forEach((toggle) => {
        toggle.addEventListener('click', () => {
            setSidebar(!body.classList.contains('sidebar-open'));
        });
    });

    sidebarClosers.forEach((closer) => {
        closer.addEventListener('click', () => setSidebar(false));
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('sidebar-open')) {
            setSidebar(false);
        }
    });

    window.addEventListener('resize', () => {
        if (window.innerWidth > 1024 && body.classList.contains('sidebar-open')) {
            setSidebar(false);
        }
    });

    /* ---------------------------------------------------------------------
       Notification toasts
       --------------------------------------------------------------------- */

    const toasts = document.querySelectorAll('.toast');
    toasts.forEach((toastEl) => {
        if (!window.bootstrap || !bootstrap.Toast) {
            return;
        }
        const instance = bootstrap.Toast.getOrCreateInstance(toastEl, {
            autohide: toastEl.dataset.autohide !== 'false',
            delay: Number.parseInt(toastEl.dataset.delay || '5500', 10),
        });
        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
            const container = document.getElementById('flashToasts');
            if (container && !container.querySelector('.toast')) {
                container.remove();
            }
        });
        instance.show();
    });

    /* ---------------------------------------------------------------------
       Confirmation dialogs for destructive / final actions
       --------------------------------------------------------------------- */

    const confirmModalEl = document.getElementById('confirmModal');
    const confirmForms = document.querySelectorAll('form[data-confirm]');

    if (confirmModalEl && confirmForms.length && window.bootstrap) {
        const confirmModal = new bootstrap.Modal(confirmModalEl);
        const titleEl = confirmModalEl.querySelector('#confirmModalTitle');
        const messageEl = confirmModalEl.querySelector('[data-confirm-message]');
        const acceptBtn = confirmModalEl.querySelector('[data-confirm-accept]');
        let pendingForm = null;

        const variantClasses = ['btn-danger', 'btn-warning', 'btn-brand', 'btn-primary'];

        confirmForms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                if (form.dataset.confirmed === '1') {
                    delete form.dataset.confirmed;
                    setFormLoading(form, event.submitter);
                    return;
                }
                event.preventDefault();
                pendingForm = form;

                const variant = form.dataset.confirmVariant || 'danger';
                if (titleEl) {
                    titleEl.textContent = form.dataset.confirmTitle || 'Confirm action';
                }
                if (messageEl) {
                    messageEl.textContent = form.dataset.confirm || 'Please confirm that you want to continue.';
                }
                if (acceptBtn) {
                    acceptBtn.classList.remove(...variantClasses);
                    acceptBtn.classList.add('btn', variant === 'primary' ? 'btn-brand' : 'btn-' + variant);
                    acceptBtn.textContent = form.dataset.confirmButton || 'Confirm';
                }
                confirmModal.show();
            });
        });

        if (acceptBtn) {
            acceptBtn.addEventListener('click', () => {
                if (!pendingForm) {
                    confirmModal.hide();
                    return;
                }
                const form = pendingForm;
                pendingForm = null;
                form.dataset.confirmed = '1';
                confirmModal.hide();
                if (typeof form.requestSubmit === 'function') {
                    form.requestSubmit();
                } else {
                    form.submit();
                }
            });
        }

        confirmModalEl.addEventListener('hidden.bs.modal', () => {
            pendingForm = null;
        });
    }

    /* ---------------------------------------------------------------------
       Form loading states
       --------------------------------------------------------------------- */

    function setFormLoading(form, submitter) {
        form.setAttribute('aria-busy', 'true');
        const button = submitter || form.querySelector('[data-loading-button]') || form.querySelector('button[type="submit"]');
        if (button) {
            button.classList.add('is-loading');
            button.setAttribute('aria-disabled', 'true');
        }
    }

    document.querySelectorAll('form[data-loading-form]').forEach((form) => {
        if (form.dataset.confirm) {
            return;
        }
        form.addEventListener('submit', (event) => {
            if (form.dataset.submitting === '1') {
                event.preventDefault();
                return;
            }
            form.dataset.submitting = '1';
            setFormLoading(form, event.submitter);
        });
    });

    /* ---------------------------------------------------------------------
       Password visibility toggles
       --------------------------------------------------------------------- */

    function togglePasswordField(button) {
        let input = null;

        if (button.dataset.togglePassword) {
            input = document.getElementById(button.dataset.togglePassword)
                || document.querySelector('input[name="' + button.dataset.togglePassword + '"]');
        } else {
            const field = button.closest('.password-field');
            if (field) {
                input = field.querySelector('input');
            }
        }
        if (!input) {
            return;
        }

        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        button.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        const icon = button.querySelector('i');
        if (icon) {
            icon.classList.toggle('bi-eye', !show);
            icon.classList.toggle('bi-eye-slash', show);
        }
    }

    document.querySelectorAll('[data-password-toggle], [data-toggle-password]').forEach((button) => {
        button.addEventListener('click', () => togglePasswordField(button));
    });

    /* ---------------------------------------------------------------------
       Password strength meter
       --------------------------------------------------------------------- */

    function findStrengthMeter(input) {
        const field = input.closest('.password-field');
        if (field && field.nextElementSibling && field.nextElementSibling.matches('[data-password-strength]')) {
            return field.nextElementSibling;
        }
        let node = input.nextElementSibling;
        while (node) {
            if (node.matches && node.matches('[data-password-strength]')) {
                return node;
            }
            if (node.matches && node.matches('.form-control, .password-field')) {
                break;
            }
            node = node.nextElementSibling;
        }
        return null;
    }

    function scorePassword(value) {
        if (!value) {
            return { score: 0, label: 'Use letters and numbers' };
        }
        let variety = 0;
        if (/[a-z]/.test(value)) variety += 1;
        if (/[A-Z]/.test(value)) variety += 1;
        if (/[0-9]/.test(value)) variety += 1;
        if (/[^A-Za-z0-9]/.test(value)) variety += 1;

        let score = 0;
        if (value.length >= 8) score += 1;
        if (value.length >= 12) score += 1;
        if (variety >= 2) score += 1;
        if (variety >= 3) score += 1;
        if (variety >= 4 && value.length >= 10) score += 1;

        const levels = [
            { label: 'Too short', color: '#f87171', width: 12 },
            { label: 'Weak', color: '#f87171', width: 30 },
            { label: 'Fair', color: '#fbbf24', width: 55 },
            { label: 'Good', color: '#60a5fa', width: 78 },
            { label: 'Strong', color: '#34d399', width: 100 },
        ];

        if (value.length < 8) {
            return { score: 1, ...levels[0] };
        }
        const scoreIndex = Math.min(4, Math.max(1, score));
        return { score: scoreIndex, ...levels[scoreIndex] };
    }

    document.querySelectorAll('[data-password-strength-source]').forEach((input) => {
        const meter = findStrengthMeter(input);
        if (!meter) {
            return;
        }
        const bar = meter.querySelector('.password-strength__bar');
        const label = meter.querySelector('.password-strength__label');

        const update = () => {
            const result = scorePassword(input.value);
            if (bar) {
                bar.style.width = result.width + '%';
                bar.style.backgroundColor = result.color;
            }
            if (label) {
                label.textContent = result.label;
            }
            meter.dataset.score = String(result.score);
        };

        input.addEventListener('input', update);
        update();
    });

    /* ---------------------------------------------------------------------
       Ballot selection summary
       --------------------------------------------------------------------- */

    const ballotForm = document.querySelector('[data-ballot-form]');
    if (ballotForm) {
        const candidates = Array.from(ballotForm.querySelectorAll('[data-ballot-candidate]'));
        const summary = ballotForm.querySelector('[data-ballot-summary]');
        const choiceBox = ballotForm.querySelector('[data-ballot-choice]');
        const emptyBox = ballotForm.querySelector('[data-ballot-empty]');
        const stateEl = ballotForm.querySelector('[data-ballot-state]');
        const nameEl = ballotForm.querySelector('[data-ballot-name]');
        const partyEl = ballotForm.querySelector('[data-ballot-party]');
        const orderEl = ballotForm.querySelector('[data-ballot-order]');
        const avatarEl = ballotForm.querySelector('[data-ballot-avatar]');
        const submitBtn = ballotForm.querySelector('[data-ballot-submit]');

        function initials(name) {
            const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
            if (!parts.length) {
                return '?';
            }
            const first = parts[0].charAt(0);
            const last = parts.length > 1 ? parts[parts.length - 1].charAt(0) : '';
            return (first + last).toUpperCase();
        }

        function updateBallot() {
            const selected = ballotForm.querySelector('input[name="candidate_id"]:checked');
            candidates.forEach((candidate) => {
                const input = candidate.querySelector('input[name="candidate_id"]');
                candidate.classList.toggle('is-selected', Boolean(input && input.checked));
            });

            if (!selected) {
                if (choiceBox) choiceBox.hidden = true;
                if (emptyBox) emptyBox.hidden = false;
                if (stateEl) {
                    stateEl.textContent = 'Nothing selected';
                    stateEl.classList.remove('is-ready');
                }
                if (submitBtn) submitBtn.disabled = true;
                if (summary) summary.dataset.selection = '';
                return;
            }

            const name = selected.dataset.candidateName || '';
            const party = selected.dataset.candidateParty || 'Independent candidate';
            const order = selected.dataset.candidateOrder || '—';

            if (nameEl) nameEl.textContent = name;
            if (partyEl) partyEl.textContent = party;
            if (orderEl) orderEl.textContent = order === '—' ? order : '#' + order;
            if (avatarEl) avatarEl.textContent = initials(name);
            if (choiceBox) choiceBox.hidden = false;
            if (emptyBox) emptyBox.hidden = true;
            if (stateEl) {
                stateEl.textContent = 'Ready to submit';
                stateEl.classList.add('is-ready');
            }
            if (submitBtn) submitBtn.disabled = false;
            if (summary) summary.dataset.selection = selected.value;
        }

        ballotForm.querySelectorAll('input[name="candidate_id"]').forEach((input) => {
            input.addEventListener('change', updateBallot);
        });

        if (submitBtn && !ballotForm.querySelector('input[name="candidate_id"]:checked')) {
            submitBtn.disabled = true;
        }
        updateBallot();
    }

    /* ---------------------------------------------------------------------
       Countdown timers
       --------------------------------------------------------------------- */

    const countdowns = document.querySelectorAll('[data-countdown-end]');

    function formatRemaining(ms) {
        const totalSeconds = Math.max(0, Math.floor(ms / 1000));
        const days = Math.floor(totalSeconds / 86400);
        const hours = Math.floor((totalSeconds % 86400) / 3600);
        const minutes = Math.floor((totalSeconds % 3600) / 60);
        const seconds = totalSeconds % 60;
        const pad = (value) => String(value).padStart(2, '0');

        if (days > 0) {
            return days + 'd ' + pad(hours) + 'h ' + pad(minutes) + 'm';
        }
        if (hours > 0) {
            return pad(hours) + 'h ' + pad(minutes) + 'm ' + pad(seconds) + 's';
        }
        return pad(minutes) + 'm ' + pad(seconds) + 's';
    }

    if (countdowns.length) {
        let reloadScheduled = false;

        const refreshCountdowns = () => {
            const now = Date.now();
            countdowns.forEach((el) => {
                const target = Date.parse(el.dataset.countdownEnd || '');
                if (Number.isNaN(target)) {
                    return;
                }
                const remaining = target - now;
                if (remaining <= 0) {
                    el.textContent = 'now';
                    if (!reloadScheduled) {
                        reloadScheduled = true;
                        window.setTimeout(() => window.location.reload(), 1200);
                    }
                    return;
                }
                el.textContent = formatRemaining(remaining);
            });
        };

        refreshCountdowns();
        window.setInterval(refreshCountdowns, 1000);
    }

    /* ---------------------------------------------------------------------
       Election switchers
       --------------------------------------------------------------------- */

    document.querySelectorAll('[data-election-switch]').forEach((select) => {
        select.addEventListener('change', () => {
            const value = select.value;
            if (!value) {
                return;
            }
            const target = new URL(window.location.href);
            target.searchParams.set('election_id', value);
            window.location.assign(target.toString());
        });
    });

    /* ---------------------------------------------------------------------
       Print helpers
       --------------------------------------------------------------------- */

    document.querySelectorAll('[data-print]').forEach((button) => {
        button.addEventListener('click', () => window.print());
    });

    /* ---------------------------------------------------------------------
       Modals that should open immediately (validation errors, resets)
       --------------------------------------------------------------------- */

    document.querySelectorAll('[data-open-on-load="true"]').forEach((modalEl) => {
        if (!window.bootstrap || !bootstrap.Modal) {
            return;
        }
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        window.setTimeout(() => modal.show(), 150);
    });

    /* ---------------------------------------------------------------------
       Avatar photo fallbacks
       --------------------------------------------------------------------- */

    function hideBrokenAvatar(image) {
        image.style.display = 'none';
    }

    document.querySelectorAll('[data-avatar-image]').forEach((image) => {
        if (image.complete && image.naturalWidth === 0) {
            hideBrokenAvatar(image);
        }
        image.addEventListener('error', () => hideBrokenAvatar(image));
    });

    /* ---------------------------------------------------------------------
       Mobile site menu convenience
       --------------------------------------------------------------------- */

    document.querySelectorAll('#siteMenu a').forEach((link) => {
        link.addEventListener('click', () => {
            const menu = document.getElementById('siteMenu');
            if (menu && window.bootstrap && bootstrap.Collapse) {
                const instance = bootstrap.Collapse.getInstance(menu);
                if (instance) {
                    instance.hide();
                }
            }
        });
    });
})();
