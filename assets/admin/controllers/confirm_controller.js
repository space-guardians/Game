import { Controller } from '@hotwired/stimulus';

/**
 * Confirmation explicite avant une action sensible (§5.6.2) : sur un formulaire,
 * data-controller="confirm" data-confirm-message-value="…" data-action="confirm#ask".
 */
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
