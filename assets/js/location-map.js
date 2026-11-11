import { StatusLabel } from './status-label.js';

export class LocationMap {
    constructor(leaflet, element, status, config) {
        this.leaflet = leaflet;
        this.element = element;
        this.status = new StatusLabel(status);
        this.config = config;
        this.map = null;
    }

    show() {
        if (this.map) return;
        const { latitude, longitude, accuracy, tileUrl, tileError } = this.config;
        const position = [latitude, longitude];
        this.element.hidden = false;
        this.map = this.leaflet.map(this.element, { scrollWheelZoom: false }).setView(position, 16);
        const tiles = this.leaflet.tileLayer(tileUrl, {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        });
        tiles.on('tileerror', () => { this.status.show(tileError, 'warning'); });
        tiles.addTo(this.map);
        if (accuracy > 0) {
            const circle = this.leaflet.circle(position, {
                radius: accuracy, color: '#2563eb', fillOpacity: 0.12, weight: 1,
            }).addTo(this.map);
            this.map.fitBounds(circle.getBounds(), { padding: [24, 24], maxZoom: 16 });
        }
        this.leaflet.circleMarker(position, {
            radius: 6, color: '#ffffff', weight: 2, fillColor: '#2563eb', fillOpacity: 1,
        }).addTo(this.map);
    }
}
