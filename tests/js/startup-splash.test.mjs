import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import { initializeStartupSplash } from '../../resources/js/startup-splash.js';

const classList = () => {
    const values = new Set();

    return {
        values,
        add: (...names) => names.forEach((name) => values.add(name)),
        toggle(name, force) {
            if (force) values.add(name);
            else values.delete(name);
        },
    };
};

const splashFixture = () => {
    const steps = Array.from({ length: 4 }, () => ({ classList: classList() }));
    const progress = { style: {} };
    const root = {
        hidden: false,
        classList: classList(),
        querySelectorAll: () => steps,
        querySelector: () => progress,
    };

    return { progress, root, steps };
};

test('public startup splash completes within the short normal timeline', () => {
    const fixture = splashFixture();
    const timers = [];
    const result = initializeStartupSplash({
        root: fixture.root,
        reducedMotion: false,
        setTimeoutImpl: (callback, delay) => timers.push({ callback, delay }),
    });

    assert.deepEqual(result, { duration: 1120, reducedMotion: false });
    assert.equal(fixture.root.classList.values.has('is-running'), true);
    assert.equal(fixture.steps[0].classList.values.has('is-active'), true);
    assert.equal(fixture.progress.style.width, '12%');
    assert.deepEqual(timers.map(({ delay }) => delay), [260, 510, 780, 950, 1120]);

    timers.forEach(({ callback }) => callback());

    assert.equal(fixture.steps[3].classList.values.has('is-active'), true);
    assert.equal(fixture.progress.style.width, '100%');
    assert.equal(fixture.root.classList.values.has('is-dismissing'), true);
    assert.equal(fixture.root.hidden, true);
});

test('reduced motion shows ready state and dismisses in under 300ms', () => {
    const fixture = splashFixture();
    const timers = [];
    const result = initializeStartupSplash({
        root: fixture.root,
        reducedMotion: true,
        setTimeoutImpl: (callback, delay) => timers.push({ callback, delay }),
    });

    assert.deepEqual(result, { duration: 280, reducedMotion: true });
    assert.equal(fixture.steps[3].classList.values.has('is-active'), true);
    assert.equal(fixture.progress.style.width, '100%');
    assert.deepEqual(timers.map(({ delay }) => delay), [160, 280]);
});

test('startup splash failure hides the overlay and contains no network calls', () => {
    const root = {
        hidden: false,
        querySelectorAll: () => {
            throw new Error('DOM unavailable');
        },
    };
    const source = readFileSync(
        new URL('../../resources/js/startup-splash.js', import.meta.url),
        'utf8',
    );

    assert.equal(initializeStartupSplash({ root }), null);
    assert.equal(root.hidden, true);
    assert.doesNotMatch(source, /\bfetch\s*\(/);
    assert.doesNotMatch(source, /Gemini|Tavily|XMLHttpRequest/);
});
