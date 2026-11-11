import 'vite/modulepreload-polyfill';
import { CaptureShare } from './capture-share.js';

const { messages, appUrl } = JSON.parse(document.querySelector('#share-config').textContent);
const message = document.querySelector('#capture-share-message');
const whatsapp = document.querySelector('#share-whatsapp');
const telegram = document.querySelector('#share-telegram');
const share = new CaptureShare({
    input: document.querySelector('#capture-share-url'),
    message,
    whatsapp,
    telegram,
    status: document.querySelector('#share-status'),
    fallback: document.querySelector('#share-copy-fallback'),
    messages,
});
share.prepare(appUrl || window.location.href);
message.addEventListener('input', () => share.update());
whatsapp.addEventListener('click', () => share.update());
telegram.addEventListener('click', () => share.update());
document.querySelector('#share-actions').hidden = false;
document.querySelector('#copy-capture-link').addEventListener('click', () => share.copy());
