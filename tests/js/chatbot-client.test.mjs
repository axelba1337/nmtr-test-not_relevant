import assert from 'node:assert/strict';
import test from 'node:test';

import {
    createRequestCoordinator,
    normalizeSuggestions,
    requestChat,
} from '../../resources/js/chatbot-client.js';

test('request coordinator allows only one fetch for concurrent sends', async () => {
    let releaseRequest;
    let fetchCalls = 0;
    const pendingRequest = new Promise((resolve) => {
        releaseRequest = resolve;
    });
    const coordinator = createRequestCoordinator({
        perform: (message) => requestChat({
            endpoint: '/api/chatbot/query',
            fetchImpl: async () => {
                fetchCalls += 1;

                return pendingRequest;
            },
            message,
        }),
    });

    const first = coordinator.send('Pertanyaan pertama');
    const second = await coordinator.send('Pertanyaan kedua');

    assert.equal(fetchCalls, 1);
    assert.equal(second.accepted, false);
    assert.equal(coordinator.isActive(), true);

    releaseRequest({
        ok: true,
        status: 200,
        json: async () => ({ answer: 'Jawaban' }),
    });

    const firstResult = await first;

    assert.equal(firstResult.accepted, true);
    assert.equal(firstResult.ok, true);
    assert.equal(coordinator.isActive(), false);
});

test('request coordinator restores availability after failure', async () => {
    let performCalls = 0;
    const coordinator = createRequestCoordinator({
        perform: async () => {
            performCalls += 1;

            if (performCalls === 1) {
                throw new Error('Network unavailable');
            }

            return { answer: 'Pulih' };
        },
    });

    const failed = await coordinator.send('Pertanyaan pertama');
    const retried = await coordinator.send('Pertanyaan kedua');

    assert.equal(failed.accepted, true);
    assert.equal(failed.ok, false);
    assert.equal(retried.accepted, true);
    assert.equal(retried.ok, true);
    assert.equal(performCalls, 2);
});

test('suggestions are normalized, deduplicated, and limited to four', () => {
    const suggestions = normalizeSuggestions({
        suggestions: [
            { id: 1, question: 'Pertanyaan satu' },
            { id: 2, question: 'Pertanyaan satu' },
            { id: 3, title: 'Pertanyaan dua' },
            { id: 4, question: 'Pertanyaan tiga' },
            { id: 5, question: 'Pertanyaan empat' },
            { id: 6, question: 'Pertanyaan lima' },
            { id: 7, question: '   ' },
        ],
    });

    assert.deepEqual(suggestions, [
        { id: 1, question: 'Pertanyaan satu' },
        { id: 3, question: 'Pertanyaan dua' },
        { id: 4, question: 'Pertanyaan tiga' },
        { id: 5, question: 'Pertanyaan empat' },
    ]);
});
