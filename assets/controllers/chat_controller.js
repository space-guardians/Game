import { Controller } from '@hotwired/stimulus';

/*
 * Chat en ligne (§4.12.1, §5.7) : onglets par canal, flux Mercure (EventSource), envoi par POST au serveur (jamais
 * directement au hub), compteur de messages non lus par onglet. Le widget est permanent (Turbo Drive) ; la connexion
 * et les messages reçus vivent au niveau du module, pour survivre à la déconnexion / reconnexion du contrôleur à
 * chaque navigation.
 */
const state = {
    source: null,
    hub: null,
    listener: null,
    messages: new Map(),
    seen: new Set(),
    unread: new Map(),
    open: false,
    active: 'public',
    loaded: new Set(),
    // Présence (§5.7) : battement de cœur périodique, joueurs en ligne
    heartbeat: null,
    beat: null,
    online: new Set(),
    presence: null,
};

export default class extends Controller {
    static targets = ['toggle', 'panel', 'tab', 'log', 'input', 'error', 'total', 'presence'];
    static values = {
        hub: String,
        sendUrl: String,
        historyUrl: String,
        presenceUrl: String,
        token: String,
        me: Number,
    };

    connect() {
        state.listener = (message) => this.receive(message);
        if (!state.source || state.hub !== this.hubValue) {
            state.source?.close();
            state.hub = this.hubValue;
            state.source = new EventSource(this.hubValue, { withCredentials: true });
            state.source.onmessage = (event) => {
                try {
                    const data = JSON.parse(event.data);
                    if (data.type === 'message' && this.store(data)) {
                        state.listener?.(data);
                    }
                } catch {
                    // Donnée qui n'est pas un message de chat (flux Turbo du jeu) : ignorée
                }
            };
        }
        this.panelTarget.hidden = !state.open;
        this.toggleTarget.setAttribute('aria-expanded', String(state.open));
        for (const log of this.logTargets) {
            this.renderLog(log.dataset.channel);
        }
        this.select({ currentTarget: this.tabFor(state.active) });
        this.loadHistory(state.active);
        // Le battement passe par l'instance connectée du moment (le widget survit aux navigations)
        state.beat = () => this.beat();
        if (!state.heartbeat) {
            this.beat();
            state.heartbeat = setInterval(() => state.beat?.(), 30_000);
        }
        this.renderPresence();
    }

    async beat() {
        try {
            const response = await fetch(this.presenceUrlValue, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-Token': this.tokenValue },
            });
            if (!response.ok) {
                return;
            }
            state.presence = await response.json();
            state.online = new Set(state.presence.players.map((player) => player.id));
            this.renderPresence();
            for (const log of this.logTargets) {
                this.renderLog(log.dataset.channel);
            }
        } catch {
            // Hors connexion : la présence sera rafraîchie au prochain battement
        }
    }

    renderPresence() {
        if (!this.hasPresenceTarget || !state.presence) {
            return;
        }
        const { count, players } = state.presence;
        const names = players.map((player) => player.name).join(', ');
        const more = count > players.length ? ` et ${count - players.length} autre(s)` : '';
        this.presenceTarget.textContent = `${count} joueur${count > 1 ? 's' : ''} en ligne${names ? ` : ${names}${more}` : ''}`;
    }

    disconnect() {
        state.listener = null;
        state.beat = null;
    }

    toggle() {
        state.open = !state.open;
        this.panelTarget.hidden = !state.open;
        this.toggleTarget.setAttribute('aria-expanded', String(state.open));
        if (state.open) {
            this.markRead(state.active);
            this.inputTarget.focus();
        }
    }

    select(event) {
        const tab = event.currentTarget;
        if (!tab) {
            return;
        }
        state.active = tab.dataset.channel;
        for (const other of this.tabTargets) {
            other.setAttribute('aria-selected', String(other === tab));
        }
        for (const log of this.logTargets) {
            log.hidden = log.dataset.channel !== state.active;
        }
        if (state.open) {
            this.markRead(state.active);
        }
        this.loadHistory(state.active);
    }

    async send(event) {
        event.preventDefault();
        const body = this.inputTarget.value.trim();
        if (body === '') {
            return;
        }
        this.errorTarget.textContent = '';
        try {
            const response = await fetch(this.sendUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ channel: state.active, body }),
            });
            if (response.ok) {
                this.inputTarget.value = '';
                return;
            }
            const data = await response.json().catch(() => ({}));
            this.errorTarget.textContent = data.error ?? 'Message non envoyé.';
        } catch {
            this.errorTarget.textContent = 'Connexion perdue : message non envoyé.';
        }
    }

    receive(message) {
        this.append(message);
        if (!state.open || state.active !== message.channel) {
            if (message.authorId !== this.meValue) {
                state.unread.set(message.channel, (state.unread.get(message.channel) ?? 0) + 1);
            }
        }
        this.renderBadges();
    }

    store(message) {
        if (state.seen.has(message.id)) {
            return false;
        }
        state.seen.add(message.id);
        const list = state.messages.get(message.channel) ?? [];
        list.push(message);
        // Même borne que le tampon du serveur
        state.messages.set(message.channel, list.slice(-50));

        return true;
    }

    async loadHistory(channel) {
        if (state.loaded.has(channel)) {
            return;
        }
        state.loaded.add(channel);
        try {
            const url = new URL(this.historyUrlValue, window.location.origin);
            url.searchParams.set('canal', channel);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) {
                return;
            }
            const history = await response.json();
            const known = state.messages.get(channel) ?? [];
            state.messages.set(channel, []);
            for (const message of [...history, ...known]) {
                state.seen.delete(message.id);
                this.store(message);
            }
            this.renderLog(channel);
        } catch {
            state.loaded.delete(channel);
        }
    }

    append(message) {
        const log = this.logFor(message.channel);
        if (log) {
            log.append(this.line(message));
            log.scrollTop = log.scrollHeight;
        }
    }

    renderLog(channel) {
        const log = this.logFor(channel);
        if (!log) {
            return;
        }
        log.replaceChildren(...(state.messages.get(channel) ?? []).map((message) => this.line(message)));
        log.scrollTop = log.scrollHeight;
        this.renderBadges();
    }

    line(message) {
        const item = document.createElement('li');
        item.className = message.authorId === this.meValue ? 'sg-chat__line sg-chat__line--mine' : 'sg-chat__line';
        const time = document.createElement('span');
        time.className = 'sg-mono sg-chat__time';
        time.textContent = new Date(message.sentAt).toLocaleTimeString('fr-FR', { hour: '2-digit', minute: '2-digit' });
        const author = document.createElement('strong');
        author.textContent = message.author;
        // Indicateur de présence de l'auteur
        const status = document.createElement('span');
        const online = state.online.has(message.authorId);
        status.className = online ? 'sg-chat__status is-online' : 'sg-chat__status';
        status.title = online ? 'En ligne' : 'Hors ligne';
        status.setAttribute('aria-label', status.title);
        const body = document.createElement('span');
        body.textContent = ` ${message.body}`;
        item.append(time, ' ', status, author, body);

        return item;
    }

    markRead(channel) {
        state.unread.set(channel, 0);
        this.renderBadges();
    }

    renderBadges() {
        let total = 0;
        for (const tab of this.tabTargets) {
            const count = state.unread.get(tab.dataset.channel) ?? 0;
            total += count;
            const badge = tab.querySelector('[data-chat-badge]');
            if (badge) {
                badge.textContent = String(count);
                badge.hidden = count === 0;
            }
        }
        this.totalTarget.textContent = String(total);
        this.totalTarget.hidden = total === 0;
    }

    tabFor(channel) {
        return this.tabTargets.find((tab) => tab.dataset.channel === channel) ?? this.tabTargets[0];
    }

    logFor(channel) {
        return this.logTargets.find((log) => log.dataset.channel === channel);
    }
}
