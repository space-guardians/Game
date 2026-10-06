import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

/*
 * Compte à rebours d'un élément de file (charte §8) : temps restant au format « 2 h 14 min », barre de progression,
 * calés sur l'heure du serveur (l'horloge du navigateur peut dériver). À l'échéance, après un court délai (le temps que
 * le worker termine la construction), la page est rafraîchie par Turbo : niveaux, ressources et énergie sont à jour.
 */
export default class extends Controller {
    static targets = ['remaining', 'bar'];
    static values = { start: Number, end: Number, serverNow: Number, settleDelay: { type: Number, default: 3000 } };

    connect() {
        this.offset = this.serverNowValue ? this.serverNowValue - Date.now() : 0;
        // Page ouverte après l'échéance : le worker n'a pas encore terminé ; pas de rafraîchissements en boucle
        this.alreadyDue = this.endValue <= Date.now() + this.offset;
        this.render();
        this.timer = setInterval(() => this.render(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
        clearTimeout(this.finishTimer);
    }

    render() {
        const now = Date.now() + this.offset;
        const total = Math.max(1, this.endValue - this.startValue);
        const remaining = Math.max(0, this.endValue - now);

        if (this.hasRemainingTarget) {
            this.remainingTarget.textContent = this.format(Math.ceil(remaining / 1000));
        }
        if (this.hasBarTarget) {
            this.barTarget.style.width = `${Math.min(100, ((total - remaining) / total) * 100)}%`;
        }
        this.element
            .querySelector('[role="progressbar"]')
            ?.setAttribute('aria-valuenow', String(Math.round(((total - remaining) / total) * 100)));

        if (remaining === 0 && this.alreadyDue) {
            clearInterval(this.timer);
            if (this.hasRemainingTarget) {
                this.remainingTarget.textContent = 'Finalisation en cours…';
            }
        } else if (remaining === 0 && !this.finishTimer) {
            clearInterval(this.timer);
            this.finishTimer = setTimeout(() => this.finish(), this.settleDelayValue);
        }
    }

    finish() {
        const event = this.dispatch('finished', { cancelable: true });
        if (!event.defaultPrevented) {
            visit(window.location.href, { action: 'replace' });
        }
    }

    // Même format que le filtre Twig « duration »
    format(seconds) {
        const days = Math.floor(seconds / 86_400);
        const hours = Math.floor((seconds % 86_400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const rest = seconds % 60;
        const pad = (value) => String(value).padStart(2, '0');

        if (days > 0) {
            return `${days} j ${hours} h`;
        }
        if (hours > 0) {
            return `${hours} h ${pad(minutes)} min`;
        }
        if (minutes > 0) {
            return `${minutes} min ${pad(rest)} s`;
        }

        return `${rest} s`;
    }
}
