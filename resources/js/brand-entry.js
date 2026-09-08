export const BRAND_AREA_STORAGE_KEY = 'neomatra:last-area';
export const LOCALE_SWITCH_STORAGE_KEY = 'neomatra:locale-switch';

const validArea = (area) => (area === 'admin' ? 'admin' : 'user');

const browserSessionStorage = () => {
    try {
        return globalThis.window?.sessionStorage ?? null;
    } catch {
        return null;
    }
};

export const markLocaleSwitchIntent = (storage) => {
    try {
        storage?.setItem(LOCALE_SWITCH_STORAGE_KEY, 'true');

        return true;
    } catch {
        return false;
    }
};

export const resolveBrandEntryMode = ({ area, storage }) => {
    const currentArea = validArea(area);
    let previousArea = null;
    let localeSwitch = false;

    try {
        previousArea = storage?.getItem(BRAND_AREA_STORAGE_KEY) ?? null;
        localeSwitch = storage?.getItem(LOCALE_SWITCH_STORAGE_KEY) === 'true';

        if (localeSwitch) {
            storage?.removeItem(LOCALE_SWITCH_STORAGE_KEY);
        }

        storage?.setItem(BRAND_AREA_STORAGE_KEY, currentArea);
    } catch {
        // Branding motion is optional and must never block page initialization.
    }

    if (localeSwitch) return 'locale';
    if (previousArea === currentArea) return 'none';

    return currentArea === 'admin' ? 'quiet' : 'full';
};

export const bindLocaleSwitchMarkers = ({ root, storage }) => {
    const forms = root?.querySelectorAll?.('[data-locale-switch-form]') ?? [];

    forms.forEach((form) => {
        if (form.dataset.brandLocaleBound === 'true') return;

        form.dataset.brandLocaleBound = 'true';
        form.addEventListener('submit', () => {
            markLocaleSwitchIntent(storage);
        });
    });

    return forms.length;
};

export const initializeBrandEntry = ({
    area,
    root = globalThis.document?.body,
    storage,
} = {}) => {
    if (!root) return 'none';

    const activeStorage = storage === undefined
        ? browserSessionStorage()
        : storage;

    bindLocaleSwitchMarkers({ root, storage: activeStorage });

    const mode = resolveBrandEntryMode({
        area: area ?? root.dataset.brandArea,
        storage: activeStorage,
    });

    root.classList.remove(
        'brand-entry--full',
        'brand-entry--quiet',
        'brand-entry--locale',
    );
    root.classList.toggle('brand-entry', mode !== 'none');

    if (mode !== 'none') {
        root.classList.add(`brand-entry--${mode}`);
    }

    root.dataset.brandEntryMode = mode;

    return mode;
};
