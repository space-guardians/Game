import { Controller } from '@hotwired/stimulus';

/*
 * Suivi en direct d'une génération de galaxie : tant qu'elle n'est pas terminée, recharge le bloc de statut
 * toutes les 2 secondes et le remplace (le nouveau bloc reprend le suivi s'il le faut).
 */
export default class extends Controller {
    static values = { url: String, finished: Boolean, interval: { type: Number, default: 2000 } };

    connect() {
        if (!this.finishedValue) {
            this.timer = setTimeout(() => this.refresh(), this.intervalValue);
        }
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    async refresh() {
        try {
            const response = await fetch(this.urlValue, { headers: { Accept: 'text/html' } });
            if (response.ok) {
                this.element.outerHTML = await response.text();
                return;
            }
        } catch {
            // Réseau indisponible : nouvel essai au prochain intervalle
        }
        this.timer = setTimeout(() => this.refresh(), this.intervalValue);
    }
}
