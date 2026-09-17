import { initializeBrandEntry } from './brand-entry';
import { initializeListingDiscoveryProgress } from './listing-discovery-progress';

initializeBrandEntry({ area: 'admin' });
initializeListingDiscoveryProgress();

let adminTranslations = {};

try {
    adminTranslations = JSON.parse(document.body.dataset.adminTranslations || '{}');
} catch {
    adminTranslations = {};
}

const adminText = (key, fallback, replacements = {}) => {
    let value = typeof adminTranslations[key] === 'string'
        ? adminTranslations[key]
        : fallback;

    Object.entries(replacements).forEach(([name, replacement]) => {
        value = value.replaceAll(`:${name}`, String(replacement));
    });

    return value;
};

const adminShell = document.querySelector('[data-admin-shell]');

if (adminShell) {
    const sidebar = adminShell.querySelector('[data-admin-sidebar]');
    const sidebarToggle = adminShell.querySelector('[data-admin-menu-toggle], [data-sidebar-toggle]');
    let sidebarOverlay = adminShell.querySelector('[data-sidebar-overlay]');
    const mobileSidebar = window.matchMedia('(max-width: 64rem)');
    let sidebarOpen = false;
    let focusBeforeOpen = null;

    if (sidebar && sidebarToggle && !sidebarOverlay) {
        sidebarOverlay = document.createElement('button');
        sidebarOverlay.className = 'admin-sidebar-overlay';
        sidebarOverlay.type = 'button';
        sidebarOverlay.dataset.sidebarOverlay = '';
        sidebarOverlay.setAttribute(
            'aria-label',
            adminText('close_navigation', 'Close navigation menu'),
        );
        sidebarOverlay.hidden = true;
        adminShell.append(sidebarOverlay);
    }

    const setSidebarOpen = (open, { restoreFocus = true } = {}) => {
        if (!sidebar || !sidebarToggle || !sidebarOverlay) {
            return;
        }

        sidebarOpen = mobileSidebar.matches && open;
        adminShell.classList.toggle('is-sidebar-open', sidebarOpen);
        sidebarToggle.setAttribute('aria-expanded', String(sidebarOpen));
        sidebarOverlay.hidden = !sidebarOpen;

        if (mobileSidebar.matches) {
            sidebar.setAttribute('aria-hidden', String(!sidebarOpen));
        } else {
            sidebar.removeAttribute('aria-hidden');
        }

        if (sidebarOpen) {
            focusBeforeOpen = document.activeElement;
            const firstLink = sidebar.querySelector('a[href], button:not(:disabled)');

            firstLink?.focus();
        } else if (restoreFocus && focusBeforeOpen instanceof HTMLElement) {
            focusBeforeOpen.focus();
            focusBeforeOpen = null;
        }
    };

    sidebarToggle?.addEventListener('click', () => {
        setSidebarOpen(!sidebarOpen);
    });

    sidebarOverlay?.addEventListener('click', () => {
        setSidebarOpen(false);
    });

    sidebar?.querySelectorAll('a[href]').forEach((link) => {
        link.addEventListener('click', () => {
            if (mobileSidebar.matches) {
                setSidebarOpen(false, { restoreFocus: false });
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && sidebarOpen) {
            setSidebarOpen(false);
        }
    });

    mobileSidebar.addEventListener('change', () => {
        setSidebarOpen(false, { restoreFocus: false });
    });

    setSidebarOpen(false, { restoreFocus: false });
}

document.querySelectorAll('[data-auto-submit]').forEach((control) => {
    control.addEventListener('change', () => {
        control.closest('form')?.requestSubmit();
    });
});

document.querySelectorAll('[data-copy-target]').forEach((button) => {
    button.addEventListener('click', async () => {
        if (!(button instanceof HTMLButtonElement) || button.disabled) {
            return;
        }

        const targetSelector = button.dataset.copyTarget;
        const statusSelector = button.dataset.copyStatus;
        const target = targetSelector ? document.querySelector(targetSelector) : null;
        const status = statusSelector ? document.querySelector(statusSelector) : null;

        if (!(target instanceof HTMLTextAreaElement || target instanceof HTMLInputElement)) {
            return;
        }

        button.disabled = true;

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(target.value);
            } else {
                target.focus();
                target.select();

                if (!document.execCommand('copy')) {
                    throw new Error('Copy command unavailable');
                }
            }

            if (status instanceof HTMLElement) {
                status.textContent = button.dataset.copySuccess || '';
            }
        } catch {
            if (status instanceof HTMLElement) {
                status.textContent = button.dataset.copyFailure || '';
            }
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('[data-dialog-open]').forEach((button) => {
    button.addEventListener('click', () => {
        const dialogId = button.dataset.dialogOpen;
        const dialog = dialogId ? document.getElementById(dialogId) : null;

        if (!(dialog instanceof HTMLElement) || dialog.tagName !== 'DIALOG') {
            return;
        }

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        dialog.querySelector('[data-dialog-close]')?.focus();
    });
});

document.querySelectorAll('[data-admin-dialog]').forEach((dialog) => {
    dialog.querySelectorAll('[data-dialog-close]').forEach((button) => {
        button.addEventListener('click', () => {
            if (typeof dialog.close === 'function') {
                dialog.close();
            } else {
                dialog.removeAttribute('open');
            }
        });
    });
});

document.addEventListener('submit', (event) => {
    const form = event.target;

    if (
        !(form instanceof HTMLFormElement)
        || (
            !form.matches('[data-admin-form], [data-confirm]')
            && !form.querySelector('[data-disable-on-submit]')
        )
    ) {
        return;
    }

    if (form.dataset.submitting === 'true') {
        event.preventDefault();

        return;
    }

    const confirmation = form.dataset.confirm;

    if (confirmation && !window.confirm(confirmation)) {
        event.preventDefault();

        return;
    }

    form.dataset.submitting = 'true';
    form.setAttribute('aria-busy', 'true');

    const submitter = event.submitter;
    const busyLabel = submitter?.dataset.busyLabel
        || form.dataset.busyLabel
        || adminText('processing', 'Processing…');

    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach((button) => {
        button.disabled = true;
    });

    if (submitter instanceof HTMLButtonElement) {
        submitter.textContent = busyLabel;
    } else if (submitter instanceof HTMLInputElement) {
        submitter.value = busyLabel;
    }
});
