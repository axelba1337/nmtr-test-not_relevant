export const createDiscoveryProgressPoller = ({
    fetchProgress,
    onUpdate = () => {},
    onComplete = () => {},
    onError = () => {},
    schedule = (callback, delay) => window.setTimeout(callback, delay),
    cancel = (timer) => window.clearTimeout(timer),
    interval = 3000,
}) => {
    let stopped = false;
    let timer = null;
    let running = false;

    const stop = () => {
        stopped = true;

        if (timer !== null) {
            cancel(timer);
            timer = null;
        }
    };

    const poll = async () => {
        if (stopped || running) {
            return;
        }

        running = true;

        try {
            const progress = await fetchProgress();
            onUpdate(progress);

            if (progress?.terminal === true) {
                stop();
                onComplete(progress);

                return;
            }
        } catch (error) {
            onError(error);
        } finally {
            running = false;
        }

        if (!stopped) {
            timer = schedule(poll, interval);
        }
    };

    return {
        start: poll,
        stop,
        isStopped: () => stopped,
    };
};

const replaceTokens = (template, values) => Object.entries(values).reduce(
    (text, [key, value]) => text.replaceAll(`:${key}`, String(value)),
    template,
);

export const initializeListingDiscoveryProgress = (root = document) => {
    const panel = root.querySelector('[data-discovery-progress]');

    if (!(panel instanceof HTMLElement)) {
        return null;
    }

    const progressUrl = panel.dataset.progressUrl;

    if (!progressUrl) {
        return null;
    }

    const elements = {
        status: panel.querySelector('[data-progress-status]'),
        summary: panel.querySelector('[data-progress-summary]'),
        percent: panel.querySelector('[data-progress-percent]'),
        bar: panel.querySelector('[data-progress-bar]'),
        product: panel.querySelector('[data-progress-product]'),
        remaining: panel.querySelector('[data-progress-remaining]'),
        candidates: panel.querySelector('[data-progress-candidates]'),
        valid: panel.querySelector('[data-progress-valid]'),
        rejected: panel.querySelector('[data-progress-rejected]'),
        failures: panel.querySelector('[data-progress-failures]'),
        message: panel.querySelector('[data-progress-message]'),
    };
    let reloadScheduled = false;

    const render = (progress) => {
        const percent = Math.min(100, Math.max(0, Number(progress.percent) || 0));
        const setText = (element, value) => {
            if (element instanceof HTMLElement) {
                element.textContent = String(value);
            }
        };

        setText(elements.status, String(progress.status || '').toUpperCase());
        setText(elements.summary, replaceTokens(
            panel.dataset.checkedTemplate || ':checked / :target',
            {
                checked: progress.products_checked,
                target: progress.products_targeted,
            },
        ));
        setText(elements.percent, `${percent}%`);

        if (elements.bar instanceof HTMLProgressElement) {
            elements.bar.value = percent;
            elements.bar.textContent = `${percent}%`;
        }

        setText(
            elements.product,
            progress.current_product?.name || panel.dataset.waitingText || '—',
        );
        setText(elements.remaining, progress.products_remaining);
        setText(elements.candidates, progress.candidates_found);
        setText(elements.valid, progress.valid_candidates);
        setText(elements.rejected, progress.rejected_candidates);
        setText(elements.failures, progress.provider_failures);

        if (progress.completion_message) {
            setText(elements.message, progress.completion_message);
        }
    };

    const poller = createDiscoveryProgressPoller({
        interval: Math.max(2000, Number(panel.dataset.pollInterval) || 3000),
        fetchProgress: async () => {
            const response = await fetch(progressUrl, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store',
            });

            if (!response.ok) {
                throw new Error(`Progress request failed with ${response.status}`);
            }

            return response.json();
        },
        onUpdate: render,
        onError: () => setTextSafe(
            elements.message,
            panel.dataset.pollingError || '',
        ),
        onComplete: (progress) => {
            render(progress);

            if (!reloadScheduled) {
                reloadScheduled = true;
                window.setTimeout(() => window.location.reload(), 900);
            }
        },
    });

    poller.start();

    return poller;
};

const setTextSafe = (element, value) => {
    if (element instanceof HTMLElement) {
        element.textContent = String(value);
    }
};
