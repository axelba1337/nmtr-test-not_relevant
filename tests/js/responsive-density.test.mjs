import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const publicCss = readFileSync(
    new URL('../../resources/css/app.css', import.meta.url),
    'utf8',
);
const adminCss = readFileSync(
    new URL('../../resources/css/admin.css', import.meta.url),
    'utf8',
);

test('public mobile density uses responsive sizing without page scaling', () => {
    assert.match(publicCss, /\/\* Public mobile density \*\/[\s\S]*@media \(max-width: 767px\)/);
    assert.match(publicCss, /@media \(max-width: 767px\)[\s\S]*\.workspace-grid\s*\{[^}]*minmax\(0,\s*1fr\)/);
    assert.match(publicCss, /@media \(max-width: 767px\)[\s\S]*\.transcript\s*\{[^}]*padding:\s*0\.45rem/);
    assert.match(publicCss, /@media \(max-width: 767px\)[\s\S]*\.composer__controls\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\) auto/);
    assert.doesNotMatch(publicCss, /(?:html|body|\.page-shell|\.chat-card)\s*\{[^}]*\bzoom\s*:/s);
    assert.doesNotMatch(publicCss, /(?:html|body|\.page-shell|\.chat-card)\s*\{[^}]*transform:\s*scale\(/s);
});

test('admin mobile density keeps page width bounded and table overflow local', () => {
    assert.match(adminCss, /\/\* Admin mobile density \*\/[\s\S]*@media \(max-width: 767px\)/);
    assert.match(adminCss, /@media \(max-width: 767px\)[\s\S]*\.admin-content\s*\{[^}]*max-width:\s*100%/);
    assert.match(adminCss, /@media \(max-width: 767px\)[\s\S]*\.table-scroll\s*\{[^}]*max-width:\s*100%/);
    assert.match(adminCss, /@media \(max-width: 767px\)[\s\S]*\.summary-grid--dashboard[\s\S]*repeat\(2,\s*minmax\(0,\s*1fr\)\)/);
    assert.doesNotMatch(adminCss, /(?:html|body|\.admin-body|\.admin-shell)\s*\{[^}]*\bzoom\s*:/s);
    assert.doesNotMatch(adminCss, /(?:html|body|\.admin-body|\.admin-shell)\s*\{[^}]*transform:\s*scale\(/s);
});
