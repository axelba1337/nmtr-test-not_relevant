const NORMAL_TIMELINE = {
    stages: [
        { at: 260, index: 1, progress: 38 },
        { at: 510, index: 2, progress: 68 },
        { at: 780, index: 3, progress: 100 },
    ],
    dismissAt: 950,
    hideAt: 1120,
};

const REDUCED_TIMELINE = {
    stages: [],
    dismissAt: 160,
    hideAt: 280,
};

const defaultReducedMotion = () => {
    try {
        return globalThis.window?.matchMedia?.(
            '(prefers-reduced-motion: reduce)',
        ).matches === true;
    } catch {
        return false;
    }
};

export const initializeStartupSplash = ({
    root = globalThis.document?.querySelector?.('[data-startup-splash]'),
    reducedMotion = defaultReducedMotion(),
    setTimeoutImpl = globalThis.setTimeout,
} = {}) => {
    if (!root) return null;

    try {
        const steps = [...root.querySelectorAll('[data-startup-step]')];
        const progress = root.querySelector('[data-startup-progress]');
        const timeline = reducedMotion ? REDUCED_TIMELINE : NORMAL_TIMELINE;

        const showStage = (index, percentage) => {
            steps.forEach((step, stepIndex) => {
                step.classList.toggle('is-active', stepIndex === index);
            });

            if (progress) {
                progress.style.width = `${percentage}%`;
            }
        };

        root.classList.add('is-running');
        showStage(reducedMotion ? Math.max(0, steps.length - 1) : 0,
            reducedMotion ? 100 : 12);

        timeline.stages.forEach(({ at, index, progress: percentage }) => {
            setTimeoutImpl(() => showStage(index, percentage), at);
        });
        setTimeoutImpl(() => root.classList.add('is-dismissing'),
            timeline.dismissAt);
        setTimeoutImpl(() => {
            root.hidden = true;
        }, timeline.hideAt);

        return {
            duration: timeline.hideAt,
            reducedMotion,
        };
    } catch {
        root.hidden = true;

        return null;
    }
};
