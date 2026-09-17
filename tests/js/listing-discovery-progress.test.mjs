import assert from 'node:assert/strict';
import test from 'node:test';

import { createDiscoveryProgressPoller } from '../../resources/js/listing-discovery-progress.js';

test('poller reports active progress then stops once the run is terminal', async () => {
    const responses = [
        { status: 'running', products_checked: 1, terminal: false },
        { status: 'completed', products_checked: 2, terminal: true },
    ];
    const scheduled = [];
    const updates = [];
    const completions = [];
    const poller = createDiscoveryProgressPoller({
        fetchProgress: async () => responses.shift(),
        onUpdate: (progress) => updates.push(progress.status),
        onComplete: (progress) => completions.push(progress.status),
        schedule: (callback) => {
            scheduled.push(callback);

            return scheduled.length;
        },
        cancel: () => {},
    });

    await poller.start();
    assert.deepEqual(updates, ['running']);
    assert.equal(scheduled.length, 1);

    await scheduled.shift()();
    assert.deepEqual(updates, ['running', 'completed']);
    assert.deepEqual(completions, ['completed']);
    assert.equal(poller.isStopped(), true);
    assert.equal(scheduled.length, 0);
});

test('poller retries after a temporary Laravel progress endpoint error', async () => {
    let calls = 0;
    const scheduled = [];
    const errors = [];
    const poller = createDiscoveryProgressPoller({
        fetchProgress: async () => {
            calls += 1;

            if (calls === 1) {
                throw new Error('temporary');
            }

            return { status: 'completed', terminal: true };
        },
        onError: (error) => errors.push(error.message),
        schedule: (callback) => {
            scheduled.push(callback);

            return scheduled.length;
        },
        cancel: () => {},
    });

    await poller.start();
    assert.deepEqual(errors, ['temporary']);
    assert.equal(scheduled.length, 1);

    await scheduled.shift()();
    assert.equal(calls, 2);
    assert.equal(poller.isStopped(), true);
});
