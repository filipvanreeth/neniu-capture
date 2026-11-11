import test from 'node:test';
import assert from 'node:assert/strict';
import { CaptureShare } from '../../assets/js/capture-share.js';

function fixture() {
    const input = {
        dataset: { capturePath: '/app/?lang=en' },
        focus() { this.focused = true; }, select() { this.selected = true; },
    };
    const whatsapp = {};
    const telegram = {};
    const status = { dataset: {}, textContent: '', hidden: true };
    const message = { value: 'Please send a photo & reply.' };
    const fallback = {
        value: '', hidden: true,
        focus() { this.focused = true; }, select() { this.selected = true; },
    };
    const share = new CaptureShare({ input, message, whatsapp, telegram, status, fallback, messages: {
        link_copied: 'Copied', copy_link_manually: 'Copy manually',
    } });
    return { share, input, message, whatsapp, telegram, status, fallback };
}

function mockClipboard(t, clipboard) {
    const original = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value: { clipboard } });
    t.after(() => {
        if (original) Object.defineProperty(globalThis, 'navigator', original);
        else delete globalThis.navigator;
    });
}

test('WhatsApp shares the capture invitation and preserves its origin, subdirectory and language', () => {
    const { share, input, whatsapp } = fixture();
    share.prepare('https://capture.neniu.help/app/share?lang=en');
    assert.equal(input.value, 'https://capture.neniu.help/app/?lang=en');
    const link = new URL(whatsapp.href);
    assert.equal(link.origin, 'https://wa.me');
    assert.equal(link.pathname, '/');
    assert.equal(link.searchParams.get('text'), 'Please send a photo & reply.\n\n' + input.value);
    assert.equal(link.searchParams.has('phone'), false);
    assert.equal(link.searchParams.get('text').includes('/verify/'), false);
});

test('copying the capture invitation confirms success', async t => {
    const { share, input, status } = fixture();
    share.prepare('https://capture.neniu.help/app/share');
    mockClipboard(t, { writeText: async value => { assert.equal(value, 'Please send a photo & reply.\n\n' + input.value); } });
    await share.copy();
    assert.equal(status.dataset.status, 'success');
    assert.equal(status.textContent, 'Copied');
});

test('missing clipboard support selects the invitation for manual copying', async t => {
    const { share, input, message, status, fallback } = fixture();
    share.prepare('https://capture.neniu.help/app/share');
    mockClipboard(t, undefined);
    await share.copy();
    assert.equal(fallback.focused, true);
    assert.equal(fallback.selected, true);
    assert.equal(fallback.hidden, false);
    assert.equal(fallback.value, message.value + '\n\n' + input.value);
    assert.equal(message.value, 'Please send a photo & reply.');
    assert.equal(status.dataset.status, 'warning');
});


test('edits are used by both WhatsApp and copy without modifying the capture link', async t => {
    const { share, input, message, whatsapp } = fixture();
    share.prepare('https://capture.neniu.help/app/share');
    message.value = '  Hello & welcome!\nPlease photograph the document.  ';
    share.update();
    const expected = 'Hello & welcome!\nPlease photograph the document.\n\n' + input.value;
    assert.equal(new URL(whatsapp.href).searchParams.get('text'), expected);
    mockClipboard(t, { writeText: async value => { assert.equal(value, expected); } });
    await share.copy();
    assert.equal(input.value, 'https://capture.neniu.help/app/?lang=en');
});

test('an empty message shares only the capture link and editing clears stale copy confirmation', () => {
    const { share, input, message, whatsapp, status, fallback } = fixture();
    share.prepare('https://capture.neniu.help/app/share');
    message.value = '   ';
    status.textContent = 'Copied';
    fallback.hidden = false;
    share.update();
    assert.equal(new URL(whatsapp.href).searchParams.get('text'), input.value);
    assert.equal(share.text(), input.value);
    assert.equal(status.textContent, '');
    assert.equal(status.hidden, true);
    assert.equal(fallback.hidden, true);
});


test('Telegram encodes the current message separately from the capture URL', () => {
    const { share, input, message, telegram } = fixture();
    share.prepare('https://capture.neniu.help/app/share');
    message.value = 'Document & photo?\nPlease send a reply.';
    share.update();
    const link = new URL(telegram.href);
    assert.equal(link.origin, 'https://t.me');
    assert.equal(link.pathname, '/share/url');
    assert.equal(link.searchParams.get('url'), input.value);
    assert.equal(link.searchParams.get('text'), message.value);
    message.value = '   ';
    share.update();
    assert.equal(new URL(telegram.href).searchParams.get('url'), input.value);
    assert.equal(new URL(telegram.href).searchParams.has('text'), false);
});
