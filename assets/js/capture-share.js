import { StatusLabel } from './status-label.js';
import { messengerLinks } from './messenger-links.js';

export class CaptureShare {
    constructor({ input, message, whatsapp, telegram, status, fallback, messages }) {
        this.input = input;
        this.message = message;
        this.whatsapp = whatsapp;
        this.telegram = telegram;
        this.status = new StatusLabel(status);
        this.fallback = fallback;
        this.messages = messages;
    }

    prepare(baseUrl) {
        this.input.value = new URL(this.input.dataset.capturePath, baseUrl).href;
        this.update();
    }

    text() {
        const message = this.message.value.trim();
        return message ? `${message}\n\n${this.input.value}` : this.input.value;
    }

    update() {
        const links = messengerLinks(this.input.value, this.message.value);
        this.whatsapp.href = links.whatsapp;
        this.telegram.href = links.telegram;
        this.status.clear();
        this.fallback.hidden = true;
    }

    async copy() {
        const text = this.text();
        try {
            await navigator.clipboard.writeText(text);
            this.fallback.hidden = true;
            this.status.show(this.messages.link_copied, 'success');
        } catch {
            this.fallback.value = text;
            this.fallback.hidden = false;
            this.fallback.focus();
            this.fallback.select();
            this.status.show(this.messages.copy_link_manually, 'warning');
        }
    }
}
