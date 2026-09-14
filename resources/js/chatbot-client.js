export class ChatbotRequestError extends Error {
    constructor(message, { status = null, payload = null, code = null } = {}) {
        super(message);
        this.name = 'ChatbotRequestError';
        this.status = status;
        this.payload = payload;
        this.code = code;
    }
}

const readJson = async (response) => {
    try {
        return await response.json();
    } catch {
        return null;
    }
};

const SOURCE_KINDS = new Set(['web', 'knowledge', 'marketplace']);
const HISTORY_ROLES = new Set(['user', 'assistant']);
const MAX_HISTORY_MESSAGES = 6;
const MAX_HISTORY_CONTENT_LENGTH = 4000;
const MAX_SOURCES = 8;
const MAX_SEARCH_ENTRY_POINT_HTML_LENGTH = 20000;
const DEFAULT_PIPELINE_STAGE_DELAYS = [0, 320, 720, 1160, 1600];
const DEFAULT_MARKETPLACE_SCAN_INTERVAL = 280;
const DEFAULT_TEXTAREA_MAX_ROWS = 5;

const trimmedText = (value, maxLength) => (
    typeof value === 'string'
        ? value.trim().slice(0, maxLength)
        : ''
);

export const resizeTextarea = (
    textarea,
    {
        maxRows = DEFAULT_TEXTAREA_MAX_ROWS,
        getComputedStyleImpl = globalThis.getComputedStyle,
    } = {},
) => {
    if (!textarea?.style || typeof getComputedStyleImpl !== 'function') {
        return null;
    }

    const styles = getComputedStyleImpl(textarea);
    const lineHeight = Number.parseFloat(styles.lineHeight) || 20;
    const verticalChrome = [
        styles.paddingTop,
        styles.paddingBottom,
        styles.borderTopWidth,
        styles.borderBottomWidth,
    ].reduce((total, value) => total + (Number.parseFloat(value) || 0), 0);
    const maximumHeight = Math.ceil(
        (lineHeight * Math.max(1, Number(maxRows) || DEFAULT_TEXTAREA_MAX_ROWS))
        + verticalChrome,
    );

    textarea.style.height = 'auto';

    const scrollHeight = Math.max(
        Number(textarea.scrollHeight) || 0,
        Math.ceil(lineHeight + verticalChrome),
    );
    const height = Math.min(scrollHeight, maximumHeight);
    const overflowY = scrollHeight > maximumHeight ? 'auto' : 'hidden';

    textarea.style.height = `${height}px`;
    textarea.style.overflowY = overflowY;

    return { height, maximumHeight, overflowY };
};

export const createTextareaAutoResize = (
    textarea,
    options = {},
) => {
    let composing = false;
    const resize = () => resizeTextarea(textarea, options);
    const startComposition = () => { composing = true; };
    const finishComposition = () => {
        composing = false;
        resize();
    };

    textarea?.addEventListener?.('input', resize);
    textarea?.addEventListener?.('compositionstart', startComposition);
    textarea?.addEventListener?.('compositionend', finishComposition);
    resize();

    return {
        destroy: () => {
            textarea?.removeEventListener?.('input', resize);
            textarea?.removeEventListener?.('compositionstart', startComposition);
            textarea?.removeEventListener?.('compositionend', finishComposition);
        },
        isComposing: () => composing,
        reset: resize,
        resize,
    };
};

export const textareaEnterAction = (
    event,
    value,
    compositionActive = false,
) => {
    if (event?.key !== 'Enter') {
        return 'ignore';
    }

    if (compositionActive || event.isComposing || event.keyCode === 229) {
        return 'ignore';
    }

    if (event.shiftKey) {
        return 'newline';
    }

    return typeof value === 'string' && value.trim() !== ''
        ? 'submit'
        : 'empty';
};

export const parseChatTranslations = (value) => {
    if (typeof value !== 'string' || value.trim() === '') {
        return {};
    }

    try {
        const parsed = JSON.parse(value);

        return parsed && typeof parsed === 'object' && !Array.isArray(parsed)
            ? parsed
            : {};
    } catch {
        return {};
    }
};

export const normalizeHttpUrl = (value) => {
    if (typeof value !== 'string' || value.trim() === '') {
        return null;
    }

    try {
        const url = new URL(value.trim());

        if (!['http:', 'https:'].includes(url.protocol)
            || url.username !== ''
            || url.password !== '') {
            return null;
        }

        url.hash = '';

        return url.href;
    } catch {
        return null;
    }
};

export const normalizeHistory = (history) => {
    if (!Array.isArray(history)) {
        return [];
    }

    const normalized = [];

    history.forEach((item) => {
        if (!item || typeof item !== 'object' || !HISTORY_ROLES.has(item.role)) {
            return;
        }

        const content = trimmedText(item.content, MAX_HISTORY_CONTENT_LENGTH);

        if (content !== '') {
            normalized.push({ role: item.role, content });
        }
    });

    return normalized.slice(-MAX_HISTORY_MESSAGES);
};

export const filtersFromFormData = (data) => {
    const filters = {};

    ['condition', 'location'].forEach((key) => {
        const value = data.get(key);

        if (typeof value === 'string' && value.trim() !== '') {
            filters[key] = value.trim();
        }
    });

    const budget = data.get('budget_max');

    if (typeof budget === 'string' && budget.trim() !== '') {
        filters.budget_max = Number(budget);
    }

    return filters;
};

export const normalizeSources = (payload) => {
    const source = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.sources)
            ? payload.sources
            : [];
    const normalized = [];
    const seen = new Set();

    for (const item of source) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const kind = trimmedText(item.kind ?? item.type, 24).toLowerCase();

        if (!SOURCE_KINDS.has(kind)) {
            continue;
        }

        const url = normalizeHttpUrl(item.url ?? item.source_url);
        const title = trimmedText(
            item.title ?? item.name ?? item.source_name,
            240,
        );
        const publisher = trimmedText(
            item.publisher ?? item.source_name,
            120,
        );

        if (title === '' || (kind !== 'knowledge' && url === null)) {
            continue;
        }

        const key = url
            ? `${kind}|${url}`
            : `${kind}|${title.toLocaleLowerCase('id-ID')}`;

        if (seen.has(key)) {
            continue;
        }

        seen.add(key);
        normalized.push({ kind, title, publisher, url });

        if (normalized.length === MAX_SOURCES) {
            break;
        }
    }

    return normalized;
};

export const normalizeSearchEntryPointHtml = (value) => {
    if (typeof value !== 'string') {
        return null;
    }

    const html = value.trim();

    if (html === '' || html.length > MAX_SEARCH_ENTRY_POINT_HTML_LENGTH) {
        return null;
    }

    return html;
};

export const scrollTranscriptToLatest = (transcript, reducedMotion = false) => {
    if (!transcript || typeof transcript.scrollTo !== 'function') {
        return false;
    }

    transcript.scrollTo({
        top: transcript.scrollHeight,
        behavior: reducedMotion ? 'auto' : 'smooth',
    });

    return true;
};

export const createSearchPipelineLifecycle = ({
    onStage = () => {},
    onScan = () => {},
    stageDelays = DEFAULT_PIPELINE_STAGE_DELAYS,
    scanInterval = DEFAULT_MARKETPLACE_SCAN_INTERVAL,
    scanStageIndex = 3,
    sourceCount = 5,
    animate = true,
    setTimeoutImpl = globalThis.setTimeout.bind(globalThis),
    clearTimeoutImpl = globalThis.clearTimeout.bind(globalThis),
    setIntervalImpl = globalThis.setInterval.bind(globalThis),
    clearIntervalImpl = globalThis.clearInterval.bind(globalThis),
} = {}) => {
    let active = false;
    let generation = 0;
    let stageTimers = [];
    let scannerTimer = null;

    const stop = () => {
        const wasActive = active;

        active = false;
        generation += 1;
        stageTimers.forEach((timer) => clearTimeoutImpl(timer));
        stageTimers = [];

        if (scannerTimer !== null) {
            clearIntervalImpl(scannerTimer);
            scannerTimer = null;
        }

        return wasActive;
    };

    const start = () => {
        stop();
        active = true;

        const runGeneration = generation;
        const delays = Array.isArray(stageDelays) && stageDelays.length > 0
            ? stageDelays
            : DEFAULT_PIPELINE_STAGE_DELAYS;
        const lastStageIndex = delays.length - 1;

        if (!animate) {
            onStage(lastStageIndex);

            return;
        }

        const activateStage = (stageIndex) => {
            if (!active || runGeneration !== generation) return;

            onStage(stageIndex);

            if (stageIndex !== scanStageIndex
                || scannerTimer !== null
                || sourceCount < 1) {
                return;
            }

            let sourceIndex = 0;

            onScan(sourceIndex);
            scannerTimer = setIntervalImpl(() => {
                if (!active || runGeneration !== generation) return;

                sourceIndex = (sourceIndex + 1) % sourceCount;
                onScan(sourceIndex);
            }, scanInterval);
        };

        activateStage(0);

        delays.slice(1).forEach((delay, index) => {
            stageTimers.push(setTimeoutImpl(
                () => activateStage(index + 1),
                Math.max(0, Number(delay) || 0),
            ));
        });
    };

    return {
        isActive: () => active,
        start,
        stop,
    };
};

export const requestChat = async ({
    fetchImpl = fetch,
    endpoint,
    message,
    filters = {},
    sessionKey = null,
    history = [],
    locale = 'id',
}) => {
    const selectedLocale = ['id', 'en'].includes(locale) ? locale : 'id';
    const body = {
        message,
        ...filters,
        locale: selectedLocale,
    };

    if (typeof sessionKey === 'string' && sessionKey !== '') {
        body.session_key = sessionKey;
    }

    const normalizedHistory = normalizeHistory(history);

    if (normalizedHistory.length > 0) {
        body.history = normalizedHistory;
    }

    const response = await fetchImpl(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Neomatra-Locale': selectedLocale,
        },
        body: JSON.stringify(body),
    });
    const payload = await readJson(response);

    if (!response.ok) {
        throw new ChatbotRequestError('Chat request failed.', {
            status: response.status,
            payload,
            code: typeof payload?.error?.code === 'string'
                ? payload.error.code
                : null,
        });
    }

    if (!payload || typeof payload.answer !== 'string' || payload.answer.trim() === '') {
        throw new ChatbotRequestError('Chat response was invalid.', {
            status: response.status,
            code: 'invalid_response',
        });
    }

    return {
        ...payload,
        answer: payload.answer.trim(),
        sources: normalizeSources(payload.sources),
        search_entry_point_html: normalizeSearchEntryPointHtml(
            payload.search_entry_point_html,
        ),
    };
};

export const normalizeSuggestions = (payload) => {
    const source = Array.isArray(payload)
        ? payload
        : Array.isArray(payload?.suggestions)
            ? payload.suggestions
            : Array.isArray(payload?.data)
                ? payload.data
                : [];
    const seen = new Set();
    const suggestions = [];

    for (const item of source) {
        if (!item || typeof item !== 'object') {
            continue;
        }

        const question = typeof item.question === 'string'
            ? item.question.trim()
            : typeof item.title === 'string'
                ? item.title.trim()
                : '';

        if (question === '' || seen.has(question)) {
            continue;
        }

        seen.add(question);
        suggestions.push({
            id: item.id ?? null,
            question,
        });

        if (suggestions.length === 4) {
            break;
        }
    }

    return suggestions;
};

export const requestSuggestions = async ({
    fetchImpl = fetch,
    endpoint,
    locale = 'id',
}) => {
    const selectedLocale = ['id', 'en'].includes(locale) ? locale : 'id';
    const response = await fetchImpl(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Neomatra-Locale': selectedLocale,
        },
    });
    const payload = await readJson(response);

    if (!response.ok) {
        throw new ChatbotRequestError('Suggestions request failed.', {
            status: response.status,
        });
    }

    return normalizeSuggestions(payload);
};

export const createRequestCoordinator = ({
    perform,
    onStart = () => {},
    onSuccess = () => {},
    onError = () => {},
    onFinish = () => {},
}) => {
    let active = false;

    const send = async (...args) => {
        if (active) {
            return {
                accepted: false,
                ok: false,
            };
        }

        active = true;

        try {
            await onStart(...args);
            const result = await perform(...args);

            await onSuccess(result, ...args);

            return {
                accepted: true,
                ok: true,
                result,
            };
        } catch (error) {
            await onError(error, ...args);

            return {
                accepted: true,
                ok: false,
                error,
            };
        } finally {
            active = false;
            await onFinish(...args);
        }
    };

    return {
        isActive: () => active,
        send,
    };
};
