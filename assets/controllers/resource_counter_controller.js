import { Controller } from '@hotwired/stimulus';

/*
 * Décompte en direct d'une ressource (§4.2) : le serveur donne le stock au chargement de la page, le débit horaire
 * et la capacité ; l'affichage avance chaque seconde, sans dépasser la capacité (l'excédent est perdu) ni
 * descendre sous zéro (deutérium consommé par la fusion).
 */
export default class extends Controller {
    static targets = ['amount', 'gauge'];
    static values = { amount: Number, rate: Number, capacity: Number };

    connect() {
        this.startedAt = Date.now();
        this.format = new Intl.NumberFormat('fr-FR', { maximumFractionDigits: 0 });
        this.timer = setInterval(() => this.render(), 1000);
    }

    disconnect() {
        clearInterval(this.timer);
    }

    render() {
        const hours = (Date.now() - this.startedAt) / 3_600_000;
        const ceiling = Math.max(this.capacityValue, this.amountValue);
        // Débit positif plafonné par le stockage ; débit négatif (fusion) jusqu'à 0
        const amount = Math.max(0, Math.min(this.amountValue + this.rateValue * hours, ceiling));

        this.amountTarget.textContent = this.format.format(Math.floor(amount));
        if (this.hasGaugeTarget && this.capacityValue > 0) {
            this.gaugeTarget.style.width = `${Math.min(100, (amount / this.capacityValue) * 100)}%`;
        }
        this.element.classList.toggle('is-full', amount >= this.capacityValue);
    }
}
