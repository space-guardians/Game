import { Controller } from '@hotwired/stimulus';

/*
 * Notification poussée (Mercure) : disparaît d'elle-même après quelques secondes, ou au clic.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 8000 } };

    connect() {
        this.timer = setTimeout(() => this.dismiss(), this.delayValue);
        this.element.addEventListener('click', () => this.dismiss());
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    dismiss() {
        this.element.remove();
    }
}
