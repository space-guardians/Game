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
    // Discussions privées ouvertes (§4.12.1) : canal → interlocuteur, gardées pour la session de l'onglet du navigateur
    privates: new Map(),
    // Groupes (§4.12.1) : canal → composition, tenue à jour par le serveur
    groups: new Map(),
    groupsLoaded: false,
    // Alliance (§4.10) : canal, tag et membres avec leur présence, transmis à chaque battement
    alliance: null,
};

const PRIVATES_KEY = 'sg-chat-privates';

function savePrivates() {
    try {
        sessionStorage.setItem(PRIVATES_KEY, JSON.stringify([...state.privates]));
    } catch {
        // Stockage indisponible (navigation privée) : les onglets ne survivront pas à un rechargement
    }
}

function restorePrivates() {
    try {
        for (const [channel, peer] of JSON.parse(sessionStorage.getItem(PRIVATES_KEY) ?? '[]')) {
            state.privates.set(channel, peer);
        }
    } catch {
        // Rien à restaurer
    }
}

export default class extends Controller {
    static targets = [
        'toggle',
        'panel',
        'tabs',
        'tab',
        'logs',
        'log',
        'input',
        'error',
        'total',
        'presence',
        'peer',
        'group',
        'groupMembers',
        'groupAddForm',
        'groupAdd',
        'groupName',
    ];
    static values = {
        hub: String,
        sendUrl: String,
        historyUrl: String,
        presenceUrl: String,
        privateUrl: String,
        groupsUrl: String,
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
                    } else if (data.type === 'group' || data.type === 'group_left') {
                        state.groupListener?.(data);
                    }
                } catch {
                    // Donnée qui n'est pas un message de chat (flux Turbo du jeu) : ignorée
                }
            };
        }
        if (state.privates.size === 0) {
            restorePrivates();
        }
        if (state.alliance) {
            this.openTab(state.alliance.channel, `Alliance [${state.alliance.tag}]`, null, null, null);
        }
        for (const [channel, peer] of state.privates) {
            this.ensureTab(channel, peer);
        }
        state.groupListener = (event) =>
            event.type === 'group' ? this.upsertGroup(event.group) : this.dropGroup(event.channel);
        for (const group of state.groups.values()) {
            this.upsertGroup(group);
        }
        this.loadGroups();
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
            this.syncAlliance(state.presence.alliance);
            this.renderPresence();
            this.renderPeers();
            for (const log of this.logTargets) {
                this.renderLog(log.dataset.channel);
            }
        } catch {
            // Hors connexion : la présence sera rafraîchie au prochain battement
        }
    }

    /** L'onglet « Alliance » suit l'appartenance du joueur : ouvert s'il est membre, retiré s'il ne l'est plus */
    syncAlliance(alliance) {
        const previous = state.alliance;
        state.alliance = alliance ?? null;
        if (previous && previous.channel !== state.alliance?.channel) {
            state.unread.delete(previous.channel);
            state.messages.delete(previous.channel);
            this.exactTab(previous.channel)?.remove();
            this.logFor(previous.channel)?.remove();
            if (state.active === previous.channel) {
                this.select({ currentTarget: this.tabFor('public') });
            }
            this.renderBadges();
        }
        if (state.alliance) {
            this.openTab(state.alliance.channel, `Alliance [${state.alliance.tag}]`, null, null, null);
        }
    }

    renderPresence() {
        if (!this.hasPresenceTarget || !state.presence) {
            return;
        }
        if (state.alliance && state.active === state.alliance.channel) {
            // Onglet de l'alliance : ses membres en ligne
            const online = state.alliance.members.filter((member) => member.online);
            const names = online.map((member) => member.name).join(', ');
            this.presenceTarget.textContent = `Membres en ligne : ${online.length} / ${state.alliance.members.length}${names ? ` — ${names}` : ''}`;
            return;
        }
        const { count, players } = state.presence;
        const names = players.map((player) => player.name).join(', ');
        const more = count > players.length ? ` et ${count - players.length} autre(s)` : '';
        this.presenceTarget.textContent = `${count} joueur${count > 1 ? 's' : ''} en ligne${names ? ` : ${names}${more}` : ''}`;
    }

    disconnect() {
        state.listener = null;
        state.groupListener = null;
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
        this.renderGroupPanel();
        this.renderPresence();
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
            this.showError(await response.json().catch(() => ({})), 'Message non envoyé.');
        } catch {
            this.errorTarget.textContent = 'Connexion perdue : message non envoyé.';
        }
    }

    async openPrivate(event) {
        event.preventDefault();
        const name = this.peerTarget.value.trim();
        if (name === '') {
            return;
        }
        this.errorTarget.textContent = '';
        try {
            const response = await fetch(this.privateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify({ name }),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.showError(data, 'Discussion impossible.');
                return;
            }
            this.peerTarget.value = '';
            this.ensureTab(data.channel, { id: data.peerId, name: data.peer });
            this.select({ currentTarget: this.tabFor(data.channel) });
        } catch {
            this.errorTarget.textContent = 'Connexion perdue : réessayez.';
        }
    }

    closePrivate(event) {
        event.stopPropagation();
        const channel = event.currentTarget.dataset.channel;
        state.privates.delete(channel);
        state.unread.delete(channel);
        savePrivates();
        this.exactTab(channel)?.remove();
        this.logFor(channel)?.remove();
        if (state.active === channel) {
            this.select({ currentTarget: this.tabFor('public') });
        }
        this.renderBadges();
    }

    /** Onglet d'une discussion privée, créé à la première ouverture ou au premier message reçu */
    ensureTab(channel, peer) {
        if (!state.privates.has(channel)) {
            state.privates.set(channel, peer);
            savePrivates();
        }
        this.openTab(channel, peer.name, peer.id, 'closePrivate', 'Fermer la discussion');
    }

    /** Onglet d'un canal (discussion privée ou groupe), avec son fil */
    openTab(channel, label, peerId, closeAction, closeTitle) {
        const existing = this.tabTargets.find((tab) => tab.dataset.channel === channel);
        if (existing) {
            existing.querySelector('.sg-chat__tab-label').textContent = label;
            return;
        }
        const id = channel.replace(/[^a-z0-9-]/gi, '-');
        const tab = document.createElement('button');
        tab.type = 'button';
        tab.className = 'sg-chat__tab';
        tab.id = `sg-chat-tab-${id}`;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-selected', 'false');
        tab.setAttribute('aria-controls', `sg-chat-log-${id}`);
        tab.dataset.chatTarget = 'tab';
        tab.dataset.channel = channel;
        tab.dataset.action = 'chat#select';
        const name = document.createElement('span');
        name.className = 'sg-chat__tab-label';
        name.textContent = label;
        const badge = document.createElement('span');
        badge.className = 'sg-chat__badge';
        badge.dataset.chatBadge = channel;
        badge.hidden = true;
        const close = document.createElement('span');
        close.className = 'sg-chat__close';
        close.textContent = '×';
        close.title = closeTitle;
        close.dataset.channel = channel;
        close.dataset.action = `click->chat#${closeAction}`;
        if (peerId !== null) {
            // Discussion privée : présence de l'interlocuteur
            const status = document.createElement('span');
            status.className = 'sg-chat__status';
            status.dataset.peer = String(peerId);
            tab.append(status);
        }
        tab.append(name, ' ', badge);
        if (closeAction) {
            tab.append(close);
        }
        if (channel.startsWith('alliance:')) {
            // L'alliance juste après le canal public
            this.tabFor('public').after(tab);
        } else {
            this.tabsTarget.append(tab);
        }

        const log = document.createElement('ol');
        log.className = 'sg-chat__log';
        log.id = `sg-chat-log-${id}`;
        log.hidden = true;
        log.setAttribute('role', 'tabpanel');
        log.setAttribute('aria-labelledby', tab.id);
        log.setAttribute('aria-live', 'polite');
        log.dataset.chatTarget = 'log';
        log.dataset.channel = channel;
        this.logsTarget.append(log);
        this.renderPeers();
    }

    async loadGroups() {
        if (state.groupsLoaded) {
            return;
        }
        state.groupsLoaded = true;
        try {
            const response = await fetch(this.groupsUrlValue, { headers: { Accept: 'application/json' } });
            if (response.ok) {
                for (const group of await response.json()) {
                    this.upsertGroup(group);
                }
            }
        } catch {
            state.groupsLoaded = false;
        }
    }

    upsertGroup(group) {
        state.groups.set(group.channel, group);
        this.openTab(group.channel, group.name, null, 'leaveGroup', 'Quitter le groupe');
        this.renderGroupPanel();
    }

    dropGroup(channel) {
        state.groups.delete(channel);
        state.unread.delete(channel);
        state.messages.delete(channel);
        this.exactTab(channel)?.remove();
        this.logFor(channel)?.remove();
        if (state.active === channel) {
            this.select({ currentTarget: this.tabFor('public') });
        }
        this.renderBadges();
        this.renderGroupPanel();
    }

    async createGroup(event) {
        event.preventDefault();
        const name = this.groupNameTarget.value.trim();
        if (name === '') {
            return;
        }
        const data = await this.manage(this.groupsUrlValue, { name });
        if (data?.group) {
            this.groupNameTarget.value = '';
            this.upsertGroup(data.group);
            this.select({ currentTarget: this.tabFor(data.group.channel) });
        }
    }

    async addToGroup(event) {
        event.preventDefault();
        const group = state.groups.get(state.active);
        const name = this.groupAddTarget.value.trim();
        if (!group || name === '') {
            return;
        }
        const data = await this.manage(`${this.groupsUrlValue}/${group.id}/ajouter`, { name });
        if (data?.group) {
            this.groupAddTarget.value = '';
            this.upsertGroup(data.group);
        }
    }

    async removeFromGroup(event) {
        const group = state.groups.get(state.active);
        if (group) {
            const data = await this.manage(`${this.groupsUrlValue}/${group.id}/retirer`, {
                empire: Number(event.currentTarget.dataset.empire),
            });
            if (data?.group) {
                this.upsertGroup(data.group);
            }
        }
    }

    async leaveGroup(event) {
        event.stopPropagation();
        const channel = event.currentTarget.dataset.channel ?? state.active;
        const group = state.groups.get(channel);
        if (group && (await this.manage(`${this.groupsUrlValue}/${group.id}/quitter`, {})) !== null) {
            this.dropGroup(channel);
        }
    }

    /** Requête de gestion d'un groupe ; renvoie la réponse, ou null en cas de refus (affiché) */
    async manage(url, payload) {
        this.errorTarget.textContent = '';
        try {
            const response = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.tokenValue },
                body: JSON.stringify(payload),
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) {
                this.showError(data, 'Action impossible.');
                return null;
            }
            return data;
        } catch {
            this.errorTarget.textContent = 'Connexion perdue : réessayez.';
            return null;
        }
    }

    /** Participants du groupe affiché ; ajout et retrait pour son créateur */
    renderGroupPanel() {
        if (!this.hasGroupTarget) {
            return;
        }
        const group = state.groups.get(state.active);
        this.groupTarget.hidden = !group;
        if (!group) {
            return;
        }
        const owner = group.ownerId === this.meValue;
        this.groupAddFormTarget.hidden = !owner;
        this.groupMembersTarget.replaceChildren('Participants : ');
        group.members.forEach((member, index) => {
            const name = document.createElement('span');
            name.textContent = member.id === group.ownerId ? `${member.name} (créateur)` : member.name;
            this.groupMembersTarget.append(index > 0 ? ', ' : '', name);
            if (owner && member.id !== this.meValue) {
                const remove = document.createElement('button');
                remove.type = 'button';
                remove.className = 'sg-chat__close';
                remove.textContent = '×';
                remove.title = `Retirer ${member.name}`;
                remove.dataset.empire = String(member.id);
                remove.dataset.action = 'chat#removeFromGroup';
                this.groupMembersTarget.append(remove);
            }
        });
    }

    showError(data, fallback) {
        this.errorTarget.textContent = data.error ?? fallback;
        if (data.messaging) {
            // Interlocuteur hors ligne : renvoi vers la messagerie
            const link = document.createElement('a');
            link.href = data.messaging;
            link.textContent = 'Écrire dans la messagerie';
            this.errorTarget.append(' ', link);
        }
    }

    renderPeers() {
        for (const status of this.element.querySelectorAll('.sg-chat__tab [data-peer]')) {
            const online = state.online.has(Number(status.dataset.peer));
            status.classList.toggle('is-online', online);
            status.title = online ? 'En ligne' : 'Hors ligne';
        }
    }

    receive(message) {
        if (message.channel.startsWith('private:')) {
            const mine = message.authorId === this.meValue;
            this.ensureTab(
                message.channel,
                mine
                    ? { id: message.recipientId, name: message.recipient }
                    : { id: message.authorId, name: message.author },
            );
        }
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

    exactTab(channel) {
        return this.tabTargets.find((tab) => tab.dataset.channel === channel);
    }

    tabFor(channel) {
        return this.tabTargets.find((tab) => tab.dataset.channel === channel) ?? this.tabTargets[0];
    }

    logFor(channel) {
        return this.logTargets.find((log) => log.dataset.channel === channel);
    }
}
