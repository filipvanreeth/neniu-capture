import { StatusLabel } from './status-label.js';

export class ResultLink {
    constructor(section, input, link, status, messages) {
        this.section = section;
        this.input = input;
        this.link = link;
        this.status = new StatusLabel(status);
        this.messages = messages;
    }

    show(path, baseUrl) {
        const url = new URL(path, baseUrl).href;
        this.input.value = url;
        this.link.href = url;
        this.status.clear();
        this.section.hidden = false;
    }

    hide() {
        this.section.hidden = true;
    }

    async copy() {
        try {
            await navigator.clipboard.writeText(this.input.value);
            this.status.show(this.messages.link_copied, 'success');
        } catch {
            this.input.focus();
            this.input.select();
            this.status.show(this.messages.copy_link_manually, 'warning');
        }
    }
}
