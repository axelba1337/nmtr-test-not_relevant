import {
    ChatbotRequestError,
    createRequestCoordinator,
    requestChat,
    requestSuggestions,
} from './chatbot-client';

const chatbot = document.querySelector('[data-chatbot]');

if (chatbot) {
    const endpoint = chatbot.dataset.endpoint;
    const suggestionsEndpoint = chatbot.dataset.suggestionsEndpoint;
    const form = chatbot.querySelector('[data-form]');
    const input = chatbot.querySelector('[data-input]');
    const submit = chatbot.querySelector('[data-submit]');
    const transcript = chatbot.querySelector('[data-transcript]');
    const suggestions = chatbot.querySelector('[data-suggestions]');
    const suggestionsList = chatbot.querySelector('[data-suggestions-list]');
    let conversationStarted = false;
    let loadingMessage = null;

    const scrollTranscript = () => {
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        transcript.scrollTo({
            top: transcript.scrollHeight,
            behavior: reducedMotion ? 'auto' : 'smooth',
        });
    };

    const appendMessage = (role, text, matches = []) => {
        const article = document.createElement('article');
        const label = document.createElement('p');
        const body = document.createElement('p');

        article.classList.add('message', `message--${role}`);
        label.className = 'message__label';
        label.textContent = role === 'user' ? 'Anda' : role === 'error' ? 'Kesalahan' : 'Asisten';
        body.className = 'message__body';
        body.textContent = text;
        article.append(label, body);

        const validMatches = Array.isArray(matches)
            ? matches.filter((match) => (
                match
                && typeof match.title === 'string'
                && Number.isFinite(Number(match.similarity))
            ))
            : [];

        if (role === 'assistant' && validMatches.length > 0) {
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            const list = document.createElement('ul');

            details.className = 'matches';
            summary.textContent = `Knowledge yang digunakan (${validMatches.length})`;
            details.append(summary);

            validMatches.forEach((match) => {
                const item = document.createElement('li');
                const title = document.createElement('span');
                const score = document.createElement('span');
                const similarity = Number(match.similarity);
                const sign = similarity >= 0 ? '+' : '';

                title.className = 'match__title';
                title.textContent = match.title;
                score.className = 'match__score';
                score.textContent = `${sign}${similarity.toFixed(6)}`;
                item.append(title, score);
                list.append(item);
            });

            details.append(list);
            article.append(details);
        }

        transcript.append(article);
        scrollTranscript();

        return article;
    };

    const removeLoadingMessage = () => {
        loadingMessage?.remove();
        loadingMessage = null;
    };

    const hideSuggestions = () => {
        suggestions.hidden = true;
    };

    const setBusy = (busy) => {
        form.setAttribute('aria-busy', String(busy));
        input.disabled = busy;
        submit.disabled = busy;
        submit.textContent = busy ? 'Mengirim…' : 'Kirim';

        suggestionsList.querySelectorAll('button').forEach((button) => {
            button.disabled = busy;
        });
    };

    const errorMessageFor = (error) => {
        if (!(error instanceof ChatbotRequestError)) {
            return 'Tidak dapat terhubung ke layanan. Periksa jaringan Anda lalu coba kembali.';
        }

        if (error.code === 'invalid_response') {
            return 'Respons layanan tidak valid. Silakan coba kembali.';
        }

        if (error.status === 422) {
            const messageError = error.payload?.errors?.message;

            if (Array.isArray(messageError) && typeof messageError[0] === 'string') {
                return messageError[0];
            }

            return 'Pesan tidak valid. Periksa kembali isi pertanyaan Anda.';
        }

        if (error.status === 429) {
            return 'Terlalu banyak permintaan. Silakan coba kembali beberapa saat lagi.';
        }

        if (error.status >= 500) {
            return 'Layanan sedang tidak tersedia. Silakan coba kembali nanti.';
        }

        return 'Permintaan tidak dapat diproses.';
    };

    const coordinator = createRequestCoordinator({
        perform: (message) => requestChat({
            endpoint,
            message,
        }),
        onStart: (message) => {
            conversationStarted = true;
            hideSuggestions();
            appendMessage('user', message);
            input.value = '';
            setBusy(true);
            loadingMessage = appendMessage('assistant', 'Sedang mencari jawaban...');
            loadingMessage.classList.add('message--loading');
        },
        onSuccess: (payload) => {
            removeLoadingMessage();
            appendMessage('assistant', payload.answer, payload.matches);
        },
        onError: (error) => {
            removeLoadingMessage();
            appendMessage('error', errorMessageFor(error));
        },
        onFinish: () => {
            removeLoadingMessage();
            setBusy(false);
            input.focus();
        },
    });

    const sendMessage = (value) => {
        const message = typeof value === 'string' ? value.trim() : '';

        if (message === '') {
            input.focus();

            return Promise.resolve({
                accepted: false,
                ok: false,
            });
        }

        return coordinator.send(message);
    };

    const renderSuggestions = (items) => {
        if (conversationStarted || !Array.isArray(items) || items.length === 0) {
            hideSuggestions();

            return;
        }

        suggestionsList.replaceChildren();

        items.slice(0, 4).forEach((item) => {
            const button = document.createElement('button');

            button.className = 'suggestion-chip';
            button.type = 'button';
            button.textContent = item.question;
            button.addEventListener('click', () => {
                void sendMessage(item.question);
            });
            suggestionsList.append(button);
        });

        suggestions.hidden = suggestionsList.childElementCount === 0;
        scrollTranscript();
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void sendMessage(input.value);
    });

    const loadSuggestions = async () => {
        if (!suggestionsEndpoint || conversationStarted) {
            return;
        }

        try {
            const items = await requestSuggestions({
                endpoint: suggestionsEndpoint,
            });

            renderSuggestions(items);
        } catch {
            hideSuggestions();
        }
    };

    void loadSuggestions();
    input.focus();
}
