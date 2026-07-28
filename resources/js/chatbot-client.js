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

export const requestChat = async ({ fetchImpl = fetch, endpoint, message }) => {
    const response = await fetchImpl(endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({ message }),
    });
    const payload = await readJson(response);

    if (!response.ok) {
        throw new ChatbotRequestError('Chat request failed.', {
            status: response.status,
            payload,
        });
    }

    if (!payload || typeof payload.answer !== 'string' || payload.answer.trim() === '') {
        throw new ChatbotRequestError('Chat response was invalid.', {
            status: response.status,
            code: 'invalid_response',
        });
    }

    return payload;
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

export const requestSuggestions = async ({ fetchImpl = fetch, endpoint }) => {
    const response = await fetchImpl(endpoint, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
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
