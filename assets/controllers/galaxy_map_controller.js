import { Controller } from '@hotwired/stimulus';
import { select } from 'd3-selection';
import { zoom, zoomIdentity } from 'd3-zoom';

/**
 * Carte de l'univers (§2.3, §5.5) : SVG zoomable (d3-zoom), alimenté par l'endpoint JSON de la zone visible.
 * Vue galaxie : systèmes, avec planètes totales / libres / occupées. En zoomant (échelle ≥ detailScale) : planètes
 * des systèmes visibles, à leur position locale — même repère que les trajectoires de flotte (§4.6).
 */
export default class extends Controller {
    static targets = ['svg', 'world', 'systems', 'planets', 'info', 'status'];

    static values = {
        url: String,
        centerX: Number,
        centerY: Number,
        detailScale: { type: Number, default: 3 },
        systemRadius: { type: Number, default: 96 },
    };

    connect() {
        this.transform = zoomIdentity;
        this.systems = [];
        this.planets = [];
        this.svg = select(this.svgTarget);
        this.zoom = zoom()
            .scaleExtent([0.01, 40])
            .on('zoom', (event) => this.applyTransform(event.transform))
            .on('end', () => this.scheduleLoad());
        this.svg.call(this.zoom);

        const { width, height } = this.svgTarget.getBoundingClientRect();
        // Vue de départ : environ 4 000 unités de large autour du centre choisi
        const scale = Math.max(width, 1) / 4000;
        this.svg.call(
            this.zoom.transform,
            zoomIdentity
                .translate(width / 2, height / 2)
                .scale(scale)
                .translate(-this.centerXValue, -this.centerYValue),
        );
    }

    disconnect() {
        this.svg.on('.zoom', null);
        clearTimeout(this.timer);
        this.request?.abort();
    }

    /** Zoom ou déplacement : le monde suit, les repères gardent une taille constante à l'écran */
    applyTransform(transform) {
        this.transform = transform;
        this.worldTarget.setAttribute('transform', transform.toString());
        this.resize();
    }

    resize() {
        const k = this.transform.k;
        for (const node of this.systemsTarget.querySelectorAll('[data-planets]')) {
            node.setAttribute('r', String(this.systemMarker(Number(node.dataset.planets)) / k));
        }
        for (const node of this.planetsTarget.querySelectorAll('.sg-map__planet')) {
            node.setAttribute('r', String(5 / k));
        }
    }

    systemMarker(planets) {
        return 2 + Math.sqrt(planets) * 1.5;
    }

    scheduleLoad() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.load(), 120);
    }

    /** Zone visible en coordonnées globales */
    viewport() {
        const { width, height } = this.svgTarget.getBoundingClientRect();
        const [x1, y1] = this.transform.invert([0, 0]);
        const [x2, y2] = this.transform.invert([width, height]);

        return { x1, y1, x2, y2 };
    }

    async load() {
        this.request?.abort();
        this.request = new AbortController();
        const detail = this.transform.k >= this.detailScaleValue;
        const params = new URLSearchParams({ ...this.viewport(), detail: detail ? '1' : '0' });
        try {
            const response = await fetch(`${this.urlValue}?${params}`, {
                headers: { Accept: 'application/json' },
                signal: this.request.signal,
            });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const data = await response.json();
            this.renderSystems(data.systems, data.detailed);
            this.renderPlanets(data.planets);
            this.statusTarget.textContent = this.statusText(data, detail);
        } catch (error) {
            if (error.name !== 'AbortError') {
                this.statusTarget.textContent = 'Carte indisponible : nouvelle tentative au prochain déplacement.';
            }
        }
    }

    statusText(data, detail) {
        const systems = `${data.systems.length} système(s) visible(s)`;
        if (data.detailed) {
            return `${systems}, ${data.planets.length} planète(s).`;
        }

        return detail ? `${systems} : zoomez davantage pour voir les planètes.` : `${systems}.`;
    }

    renderSystems(systems, detailed) {
        this.systems = systems;
        const k = this.transform.k;
        select(this.systemsTarget)
            .selectAll('g.sg-map__node')
            .data(systems, (system) => system.id)
            .join((enter) => {
                const node = enter.append('g').attr('class', 'sg-map__node');
                node.append('circle').attr('class', 'sg-map__edge');
                node.append('circle').attr('class', 'sg-map__system');
                node.append('title');

                return node;
            })
            .each((system, index, nodes) => {
                const node = select(nodes[index]);
                node.attr('data-system', system.id);
                node.select('.sg-map__edge')
                    .attr('cx', system.x)
                    .attr('cy', system.y)
                    .attr('r', this.systemRadiusValue)
                    .attr('hidden', detailed ? null : '');
                node.select('.sg-map__system')
                    .attr('cx', system.x)
                    .attr('cy', system.y)
                    .attr('r', this.systemMarker(system.planets) / k)
                    .attr('data-planets', system.planets)
                    .classed('is-mine', system.mine > 0)
                    .classed('is-occupied', system.mine === 0 && system.occupied > 0)
                    .on('click', () => this.focusSystem(system));
                node.select('title').text(
                    `Système ${system.number} : ${system.planets} planète(s), ${system.free} libre(s), ${system.occupied} occupée(s)`,
                );
            });
    }

    renderPlanets(planets) {
        this.planets = planets;
        const k = this.transform.k;
        select(this.planetsTarget)
            .selectAll('circle.sg-map__planet')
            .data(planets, (planet) => planet.id)
            .join((enter) => {
                const node = enter.append('circle').attr('class', 'sg-map__planet');
                node.append('title');

                return node;
            })
            .attr('cx', (planet) => planet.x)
            .attr('cy', (planet) => planet.y)
            .attr('r', 5 / k)
            .attr('data-address', (planet) => planet.address)
            .classed('is-mine', (planet) => planet.mine)
            .classed('is-occupied', (planet) => !planet.mine && planet.empire !== null)
            .on('click', (_event, planet) => this.showPlanet(planet))
            .select('title')
            .text((planet) => `${planet.address} — ${planet.empire ?? 'libre'}`);
    }

    /** Zoom animé sur un système : ses planètes apparaissent à leur position */
    focusSystem(system) {
        const { width, height } = this.svgTarget.getBoundingClientRect();
        // Le système (lisière comprise) occupe environ la moitié de la plus petite dimension
        const scale = Math.min(width, height) / (this.systemRadiusValue * 4);
        this.svg
            .transition()
            .duration(600)
            .call(
                this.zoom.transform,
                zoomIdentity
                    .translate(width / 2, height / 2)
                    .scale(Math.max(scale, this.detailScaleValue))
                    .translate(-system.x, -system.y),
            );
        this.infoTarget.textContent = `Système ${system.number} : ${system.planets} planète(s), ${system.free} libre(s), ${system.occupied} occupée(s)${system.mine > 0 ? `, dont ${system.mine} à vous` : ''}.`;
    }

    showPlanet(planet) {
        this.infoTarget.textContent = `Planète ${planet.address} : ${planet.mine ? 'à vous' : (planet.empire ?? 'libre')}.`;
    }

    /** Bouton « Vue galaxie » : retour à la vue d'ensemble autour du centre de départ */
    reset() {
        const { width, height } = this.svgTarget.getBoundingClientRect();
        this.svg
            .transition()
            .duration(600)
            .call(
                this.zoom.transform,
                zoomIdentity
                    .translate(width / 2, height / 2)
                    .scale(Math.max(width, 1) / 4000)
                    .translate(-this.centerXValue, -this.centerYValue),
            );
    }
}
