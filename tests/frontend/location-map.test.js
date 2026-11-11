import test from 'node:test';
import assert from 'node:assert/strict';
import { LocationMap } from '../../assets/js/location-map.js';

test('map waits for activation, uses recorded coordinates and draws accuracy in meters', () => {
    const calls = [];
    const events = {};
    const map = {
        setView(position, zoom) { calls.push(['view', position, zoom]); return this; },
        fitBounds(bounds, options) { calls.push(['bounds', bounds, options]); },
    };
    const layer = {
        on(event, callback) { events[event] = callback; return this; },
        addTo(target) { assert.equal(target, map); return this; },
    };
    const leaflet = {
        map(element, options) { calls.push(['map', options]); return map; },
        tileLayer(url, options) {
            assert.equal(url, 'https://tile.openstreetmap.org/{z}/{x}/{y}.png');
            assert.match(options.attribution, /OpenStreetMap/);
            return layer;
        },
        circle(position, options) {
            assert.deepEqual(position, [51.5, -0.09]);
            assert.equal(options.radius, 35);
            return { addTo: () => ({ getBounds: () => 'accuracy-bounds' }) };
        },
        circleMarker(position) { calls.push(['marker', position]); return layer; },
    };
    const element = { hidden: true };
    const status = { textContent: '', dataset: {}, hidden: true };
    const location = new LocationMap(leaflet, element, status, {
        latitude: 51.5, longitude: -0.09, accuracy: 35,
        tileUrl: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png', tileError: 'Tiles unavailable',
    });
    assert.equal(calls.length, 0);
    location.show();
    assert.equal(element.hidden, false);
    assert.deepEqual(calls.find(call => call[0] === 'view'), ['view', [51.5, -0.09], 16]);
    assert.equal(calls.find(call => call[0] === 'bounds')[1], 'accuracy-bounds');
    location.show();
    assert.equal(calls.filter(call => call[0] === 'map').length, 1);
    events.tileerror();
    assert.equal(status.textContent, 'Tiles unavailable');
});
