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

const trimmedText = (value, maxLength) => (
    typeof value === 'string'
        ? value.trim().slice(0, maxLength)
        : ''
);

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
