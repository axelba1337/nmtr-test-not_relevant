import {
    ChatbotRequestError,
    createRequestCoordinator,
    createSearchPipelineLifecycle,
    normalizeSearchEntryPointHtml,
    normalizeSources,
    parseChatTranslations,
    requestChat,
    requestSuggestions,
    scrollTranscriptToLatest,
} from './chatbot-client';
import { initializeBrandEntry } from './brand-entry';

initializeBrandEntry({ area: 'user' });

const chatbot = document.querySelector('[data-chatbot]');

if (chatbot) {
    const endpoint = chatbot.dataset.endpoint;
    const suggestionsEndpoint = chatbot.dataset.suggestionsEndpoint;
    const locale = ['id', 'en'].includes(chatbot.dataset.locale)
        ? chatbot.dataset.locale
        : 'id';
    const translations = parseChatTranslations(chatbot.dataset.translations);

    const t = (key, fallback, replacements = {}) => {
        let value = typeof translations[key] === 'string'
            ? translations[key]
            : fallback;

        Object.entries(replacements).forEach(([name, replacement]) => {
            value = value.replaceAll(`:${name}`, String(replacement));
        });

        return value;
    };
    const form = chatbot.querySelector('[data-form]');
    const input = chatbot.querySelector('[data-input]');
    const submit = chatbot.querySelector('[data-submit]');
    const composerControls = chatbot.querySelector('.composer__controls');
    const transcript = chatbot.querySelector('[data-transcript]');
    const suggestions = chatbot.querySelector('[data-suggestions]');
    const suggestionsList = chatbot.querySelector('[data-suggestions-list]');
    const filterPanel = chatbot.querySelector('[data-filters]');
    const filterControls = [...chatbot.querySelectorAll('[data-filter]')];
    const reducedMotionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    const sessionKey = getSessionKey();
    let conversationStarted = false;
    let loadingMessage = null;
    let searchPipeline = null;
    let conversationHistory = [];

    function getSessionKey() {
        try {
            const stored = window.sessionStorage.getItem('neomatra-chat-session');

            if (stored) {
                return stored;
            }

            if (typeof window.crypto?.randomUUID === 'function') {
                const generated = window.crypto.randomUUID();

                window.sessionStorage.setItem('neomatra-chat-session', generated);

                return generated;
            }
        } catch {
            // Session identifiers are optional; never block chat for storage errors.
        }

        return null;
    }

    const scrollTranscript = () => {
        scrollTranscriptToLatest(transcript, reducedMotionQuery.matches);
    };

    const appendPlainText = (container, text) => {
        const paragraphs = String(text)
            .split(/\r?\n\s*\r?\n/)
            .map((paragraph) => paragraph.trim())
            .filter((paragraph) => paragraph !== '');

        (paragraphs.length > 0 ? paragraphs : ['']).forEach((paragraph) => {
            const element = document.createElement('p');

            element.textContent = paragraph;
            container.append(element);
        });
    };

    const appendSearchEntryPoint = (article, value) => {
        const html = normalizeSearchEntryPointHtml(value);

        if (html === null) return;

        const container = document.createElement('section');
        const iframe = document.createElement('iframe');

        container.className = 'search-entry-point';
        iframe.className = 'search-entry-point__frame';
        iframe.title = t('google_title', 'Google search suggestions');
        iframe.loading = 'lazy';
        iframe.referrerPolicy = 'no-referrer';
        iframe.setAttribute(
            'sandbox',
            'allow-popups allow-popups-to-escape-sandbox',
        );
        iframe.srcdoc = html;
        container.append(iframe);
        article.append(container);
    };

    const appendSources = (article, sources) => {
        const validSources = normalizeSources(sources);

        if (validSources.length === 0) return validSources;

        const details = document.createElement('details');
        const summary = document.createElement('summary');
        const list = document.createElement('ol');
        const kindLabels = {
            web: t('source_web', 'Web'),
            knowledge: t('source_knowledge', 'Neomatra Knowledge'),
            marketplace: t('source_marketplace', 'Marketplace'),
        };

        details.className = 'answer-sources';
        summary.textContent = t(
            'answer_sources',
            'Answer sources (:count)',
            { count: validSources.length },
        );
        details.append(summary);

        validSources.forEach((source) => {
            const item = document.createElement('li');
            const heading = document.createElement('div');
            const badge = document.createElement('span');
            const title = source.url
                ? document.createElement('a')
                : document.createElement('span');

            badge.className = `source-badge source-badge--${source.kind}`;
            badge.textContent = kindLabels[source.kind];
            title.className = 'source-title';
            title.textContent = source.title;

            if (source.url) {
                title.href = source.url;
                title.target = '_blank';
                title.rel = 'noopener noreferrer';
            }

            heading.className = 'source-heading';
            heading.append(badge, title);
            item.append(heading);

            if (source.publisher !== '') {
                const publisher = document.createElement('small');

                publisher.textContent = source.publisher;
                item.append(publisher);
            }

            list.append(item);
        });

        details.append(list);
        article.append(details);

        return validSources;
    };

    const appendMessage = (
        role,
        text,
        {
            matches = [],
            sources = [],
            searchEntryPointHtml = null,
            motionClass = null,
        } = {},
    ) => {
        const article = document.createElement('article');
        const label = document.createElement('p');
        const body = document.createElement('div');

        article.classList.add('message', `message--${role}`);

        if (typeof motionClass === 'string' && motionClass !== '') {
            article.classList.add(motionClass);
        }
        label.className = 'message__label';
        label.textContent = role === 'user'
            ? t('label_user', 'USER / QUERY')
            : role === 'error'
                ? t('label_error', 'SYSTEM / ERROR')
                : t('label_assistant', 'NEOMATRA / RESPONSE');
        body.className = 'message__body';
        appendPlainText(body, text);
        article.append(label, body);

        if (role === 'assistant') {
            appendSearchEntryPoint(article, searchEntryPointHtml);
        }

        const validSources = role === 'assistant'
            ? appendSources(article, sources)
            : [];
        const citedKnowledgeTitles = new Set(
            validSources
                .filter((source) => source.kind === 'knowledge')
                .map((source) => source.title.toLocaleLowerCase(locale)),
        );

        const validMatches = Array.isArray(matches)
            ? matches.filter((match) => (
                match
                && typeof match.title === 'string'
                && match.title.trim() !== ''
                && !citedKnowledgeTitles.has(
                    match.title.trim().toLocaleLowerCase(locale),
                )
            ))
            : [];

        if (role === 'assistant' && validMatches.length > 0) {
            const details = document.createElement('details');
            const summary = document.createElement('summary');
            const list = document.createElement('ul');

            details.className = 'matches';
            summary.textContent = t(
                'knowledge',
                'Neomatra Knowledge (:count)',
                { count: validMatches.length },
            );
            details.append(summary);

            validMatches.forEach((match) => {
                const item = document.createElement('li');
                const title = document.createElement('span');

                title.className = 'match__title';
                title.textContent = match.title.trim();
                item.append(title);
                list.append(item);
            });

            details.append(list);
            article.append(details);
        }

        transcript.append(article);
        scrollTranscript();

        return article;
    };

    const pipelineStages = [
        t('pipeline_query_received', 'QUERY RECEIVED'),
        t('pipeline_interpreting', 'INTERPRETING REQUEST'),
        t('pipeline_semantic', 'SEMANTIC RETRIEVAL'),
        t('pipeline_marketplace', 'SCANNING MARKETPLACE SOURCES'),
        t('pipeline_ranking', 'RANKING RESULTS'),
    ];
    const marketplaceSources = [
        'TOKOPEDIA',
        'SHOPEE',
        'OLX',
        'FACEBOOK MARKETPLACE',
        'CAROUSELL',
    ];

    const updatePipelineStage = (article, activeStage) => {
        const rows = [...article.querySelectorAll('[data-pipeline-stage]')];
        const sourcesPanel = article.querySelector('[data-pipeline-sources]');

        rows.forEach((row, stageIndex) => {
            const status = row.querySelector('[data-pipeline-status]');
            let state = 'queued';

            if (stageIndex < activeStage
                || (stageIndex === 0 && activeStage === 0)) {
                state = 'ready';
            } else if (stageIndex === activeStage) {
                state = 'active';
            }

            row.dataset.state = state;

            if (status) {
                status.textContent = state === 'ready'
                    ? t('pipeline_ready', 'READY')
                    : state === 'active'
                        ? t('pipeline_active', 'ACTIVE')
                        : t('pipeline_queued', 'QUEUED');
            }
        });

        article.dataset.pipelineStage = String(activeStage);
        sourcesPanel?.classList.toggle('is-scanning', activeStage >= 3);
    };

    const updateMarketplaceScanner = (article, activeSource) => {
        article.querySelectorAll('[data-pipeline-source]').forEach((source, index) => {
            source.classList.toggle('is-active', index === activeSource);
        });
    };

    const stopSearchPipeline = () => {
        searchPipeline?.stop();
        searchPipeline = null;
    };

    const appendSearchPipelineLoading = () => {
        const article = document.createElement('article');
        const accessibleStatus = document.createElement('p');
        const visual = document.createElement('div');
        const header = document.createElement('div');
        const label = document.createElement('span');
        const processing = document.createElement('span');
        const stages = document.createElement('div');
        const sourcePanel = document.createElement('div');
        const sourceLabel = document.createElement('p');
        const sourceGrid = document.createElement('div');
        const scanner = document.createElement('span');

        article.className = 'message message--assistant message--loading search-pipeline';
        article.dataset.searchPipeline = '';
        accessibleStatus.className = 'sr-only';
        accessibleStatus.textContent = t(
            'pipeline_accessible',
            'Neomatra is searching available sources.',
        );
        visual.className = 'search-pipeline__visual';
        visual.setAttribute('aria-hidden', 'true');
        header.className = 'search-pipeline__header';
        label.textContent = t('pipeline_label', 'NEOMATRA / SEARCH PIPELINE');
        processing.textContent = t('pipeline_processing', 'PROCESSING');
        header.append(label, processing);
        stages.className = 'search-pipeline__stages';

        pipelineStages.forEach((stageLabel, index) => {
            const row = document.createElement('div');
            const number = document.createElement('span');
            const name = document.createElement('span');
            const status = document.createElement('span');

            row.className = 'search-pipeline__stage';
            row.dataset.pipelineStage = String(index);
            row.dataset.state = 'queued';
            number.className = 'search-pipeline__number';
            number.textContent = String(index + 1).padStart(2, '0');
            name.className = 'search-pipeline__name';
            name.textContent = stageLabel;
            status.className = 'search-pipeline__status';
            status.dataset.pipelineStatus = '';
            status.textContent = t('pipeline_queued', 'QUEUED');
            row.append(number, name, status);
            stages.append(row);
        });

        sourcePanel.className = 'search-pipeline__sources';
        sourcePanel.dataset.pipelineSources = '';
        sourceLabel.className = 'search-pipeline__sources-label';
        sourceLabel.textContent = t('marketplace_scan', 'MARKETPLACE SCAN');
        sourceGrid.className = 'search-pipeline__source-grid';

        marketplaceSources.forEach((sourceName) => {
            const source = document.createElement('span');

            source.dataset.pipelineSource = '';
            source.textContent = sourceName;
            sourceGrid.append(source);
        });

        sourcePanel.append(sourceLabel, sourceGrid);
        scanner.className = 'search-pipeline__scanner';
        visual.append(header, stages, sourcePanel, scanner);
        article.append(accessibleStatus, visual);
        transcript.append(article);

        searchPipeline = createSearchPipelineLifecycle({
            animate: !reducedMotionQuery.matches,
            sourceCount: marketplaceSources.length,
            onStage: (stageIndex) => updatePipelineStage(article, stageIndex),
            onScan: (sourceIndex) => updateMarketplaceScanner(article, sourceIndex),
        });
        searchPipeline.start();
        scrollTranscript();

        return article;
    };

    const safeHref = (value) => {
        if (typeof value !== 'string') return null;

        try {
            const url = new URL(value, window.location.origin);

            return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
        } catch {
            return null;
        }
    };

    const formatMoney = (value, currency = 'IDR') => {
        if (!Number.isSafeInteger(Number(value))) {
            return t('unavailable', 'Unavailable');
        }

        return new Intl.NumberFormat(locale === 'en' ? 'en-US' : 'id-ID', {
            style: 'currency',
            currency,
            maximumFractionDigits: 0,
        }).format(Number(value));
    };

    const conditionLabels = {
        new: t('condition_new', 'New'),
        open_box: t('condition_open_box', 'Open box'),
        used: t('condition_used', 'Used'),
        refurbished: t('condition_refurbished', 'Refurbished'),
        ex_display: t('condition_ex_display', 'Ex-display'),
        unknown: t('condition_unknown', 'Unknown'),
    };

    const appendListingResults = (listings) => {
        if (!Array.isArray(listings) || listings.length === 0) return;

        const section = document.createElement('section');
        const heading = document.createElement('div');
        const title = document.createElement('h2');
        const note = document.createElement('p');
        const grid = document.createElement('div');

        section.className = 'listing-results';
        heading.className = 'listing-results__heading';
        title.textContent = t(
            'top_listings',
            ':count top listings',
            { count: listings.length },
        );
        note.textContent = t('ranking_note', 'Sorted by backend ranking.');
        heading.append(title, note);
        grid.className = 'listing-grid';

        listings.forEach((listing) => {
            if (!listing || typeof listing !== 'object') return;

            const card = document.createElement('article');
            const top = document.createElement('div');
            const platform = document.createElement('span');
            const name = document.createElement('h3');
            const facts = document.createElement('dl');
            const seller = document.createElement('p');
            const actions = document.createElement('div');

            card.className = 'listing-card listing-card--entering';
            card.style.setProperty(
                '--listing-index',
                String(Math.min(grid.childElementCount, 3)),
            );
            top.className = 'listing-card__top';
            platform.className = 'listing-card__platform';
            platform.textContent = typeof listing.platform === 'string'
                ? listing.platform
                : t('unknown_platform', 'Unknown platform');
            top.append(platform);
            name.textContent = typeof listing.title === 'string'
                ? listing.title
                : t('untitled_listing', 'Untitled listing');

            const factValues = [
                [t('price', 'Price'), formatMoney(listing.item_price, listing.currency)],
                [t('freshness', 'Freshness'), listing.source_published_at
                    || listing.last_synced_at
                    || t('freshness_unknown', 'Unavailable')],
            ];

            factValues.forEach(([label, value]) => {
                const wrapper = document.createElement('div');
                const term = document.createElement('dt');
                const description = document.createElement('dd');

                term.textContent = label;
                description.textContent = value;
                wrapper.append(term, description);
                facts.append(wrapper);
            });

            seller.className = 'listing-card__seller';
            seller.textContent = [
                listing.seller_name || t('unknown_seller', 'Unknown seller'),
                listing.location || t('unknown_location', 'Location unavailable'),
                conditionLabels[listing.condition]
                    || listing.condition
                    || t('unknown_condition', 'Unknown condition'),
            ].join(' · ');
            actions.className = 'listing-card__actions';

            const sourceHref = safeHref(listing.source_url);

            if (sourceHref) {
                const source = document.createElement('a');

                source.href = sourceHref;
                source.target = '_blank';
                source.rel = 'noopener noreferrer';
                source.textContent = t('open_source', 'Open source');
                actions.append(source);
            }

            card.append(top, name, facts, seller, actions);
            grid.append(card);
        });

        section.append(heading, grid);
        transcript.append(section);
        scrollTranscript();
    };

    const removeLoadingMessage = () => {
        stopSearchPipeline();
        loadingMessage?.remove();
        loadingMessage = null;
    };

    const hideSuggestions = () => {
        suggestions.hidden = true;
    };

    const setBusy = (busy) => {
        form.setAttribute('aria-busy', String(busy));
        form.classList.toggle('is-busy', busy);
        composerControls?.classList.toggle('is-busy', busy);
        input.disabled = busy;
        submit.disabled = busy;
        submit.textContent = busy
            ? t('searching', 'Searching…')
            : t('send', 'Send / Search');
        filterControls.forEach((control) => { control.disabled = busy; });
        suggestionsList.querySelectorAll('button').forEach((button) => {
            button.disabled = busy;
        });
    };

    const errorMessageFor = (error) => {
        if (!(error instanceof ChatbotRequestError)) {
            return t('network_error', 'Unable to connect to the service.');
        }

        if (error.code === 'embedding_unavailable') {
            return error.payload?.error?.message
                || t('embedding_error', 'The Embedding API is unavailable.');
        }

        if (error.status === 422) {
            const errors = error.payload?.errors;
            const first = errors && typeof errors === 'object'
                ? Object.values(errors).flat().find((value) => typeof value === 'string')
                : null;

            return first || t('validation_error', 'The filters or question are invalid.');
        }

        if (error.status === 429) {
            return t('rate_limit', 'Too many requests. Please try again shortly.');
        }

        if (error.status >= 500) {
            return error.payload?.error?.message
                || t('service_error', 'The service is currently unavailable.');
        }

        return t('request_error', 'The request could not be processed.');
    };

    const filtersFromForm = () => {
        const data = new FormData(form);
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

    const coordinator = createRequestCoordinator({
        perform: ({ message, filters, history }) => requestChat({
            endpoint,
            message,
            filters,
            sessionKey,
            history,
            locale,
        }),
        onStart: ({ message }) => {
            removeLoadingMessage();
            conversationStarted = true;
            hideSuggestions();
            appendMessage('user', message, {
                motionClass: 'message--query-entry',
            });
            input.value = '';
            setBusy(true);
            loadingMessage = appendSearchPipelineLoading();
        },
        onSuccess: (payload, { message }) => {
            removeLoadingMessage();
            appendMessage('assistant', payload.answer, {
                matches: payload.matches,
                sources: payload.sources,
                searchEntryPointHtml: payload.search_entry_point_html,
                motionClass: 'message--result-entry',
            });
            appendListingResults(payload.listings);
            conversationHistory = [
                ...conversationHistory,
                { role: 'user', content: message },
                { role: 'assistant', content: payload.answer },
            ].slice(-6);
        },
        onError: (error) => {
            removeLoadingMessage();
            appendMessage('error', errorMessageFor(error), {
                motionClass: 'message--error-entry',
            });
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

            return Promise.resolve({ accepted: false, ok: false });
        }

        return coordinator.send({
            message,
            filters: filtersFromForm(),
            history: [...conversationHistory],
        });
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
            button.addEventListener('click', () => void sendMessage(item.question));
            suggestionsList.append(button);
        });

        suggestions.hidden = suggestionsList.childElementCount === 0;
        scrollTranscript();
    };

    form.addEventListener('submit', (event) => {
        event.preventDefault();
        void sendMessage(input.value);
    });
    window.addEventListener('pagehide', stopSearchPipeline);

    const loadSuggestions = async () => {
        if (!suggestionsEndpoint || conversationStarted) return;

        try {
            renderSuggestions(await requestSuggestions({
                endpoint: suggestionsEndpoint,
                locale,
            }));
        } catch {
            hideSuggestions();
        }
    };

    if (window.matchMedia('(max-width: 48rem)').matches) {
        filterPanel?.removeAttribute('open');
    }

    void loadSuggestions();
    input.focus({ preventScroll: true });
}
