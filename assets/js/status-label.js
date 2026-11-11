export class StatusLabel {
    constructor(element) {
        this.element = element;
    }

    show(message, tone = 'info') {
        this.element.textContent = message;
        this.element.dataset.status = tone;
        this.element.hidden = !message;
    }

    clear() {
        this.element.textContent = '';
        delete this.element.dataset.status;
        this.element.hidden = true;
    }
}
