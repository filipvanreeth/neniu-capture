import test from 'node:test';
import assert from 'node:assert/strict';
import { ResultLink } from '../../assets/js/result-link.js';

function createResult() {
    const section = { hidden: true };
    const input = { value: '', focus() { this.focused = true; }, select() { this.selected = true; } };
    const link = {};
    const status = { textContent: '', dataset: {}, hidden: true };
    const result = new ResultLink(section, input, link, status, {
        link_copied: 'Copied', copy_link_manually: 'Copy manually',
    });
    return { result, section, input, link, status };
}

function mockClipboard(t, clipboard) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard } });
    t.after(() => {
        if (descriptor) Object.defineProperty(globalThis, 'navigator', descriptor);
        else delete globalThis.navigator;
    });
}

test('saved link is absolute and retains the verification path, language and subdirectory', () => {
    const { result, section, input, link } = createResult();
    result.show('/app/verify/abc123?lang=en', 'https://capture.neniu.help/app/');
    assert.equal(input.value, 'https://capture.neniu.help/app/verify/abc123?lang=en');
    assert.equal(link.href, input.value);
    assert.equal(section.hidden, false);
    result.hide();
    assert.equal(section.hidden, true);
});

test('copying the result uses the clipboard and confirms success', async t => {
    const state = createResult();
    state.result.show('/verify/abc123?lang=en', 'https://capture.neniu.help/');
    mockClipboard(t, { writeText: async text => {
        assert.equal(text, state.input.value);
    } });
    await state.result.copy();
    assert.equal(state.status.textContent, 'Copied');
    assert.equal(state.status.dataset.status, 'success');
    assert.equal(state.status.hidden, false);
});

test('clipboard failure selects the link for manual copying', async t => {
    const state = createResult();
    mockClipboard(t, { writeText: async () => {
        throw new Error('Clipboard denied');
    } });
    await state.result.copy();
    assert.equal(state.input.focused, true);
    assert.equal(state.input.selected, true);
    assert.equal(state.status.textContent, 'Copy manually');
    assert.equal(state.status.dataset.status, 'warning');
});
