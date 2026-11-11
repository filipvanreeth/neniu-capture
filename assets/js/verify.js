import 'vite/modulepreload-polyfill';
import L from 'leaflet';
import { LocationMap } from './location-map.js';

const element = document.querySelector('#location-map');
const button = document.querySelector('#show-map');
if (element && button) {
    const map = new LocationMap(L, element, document.querySelector('#map-status'), {
        latitude: Number(element.dataset.latitude),
        longitude: Number(element.dataset.longitude),
        accuracy: Number(element.dataset.accuracy),
        tileUrl: element.dataset.tileUrl,
        tileError: element.dataset.tileError,
    });
    button.addEventListener('click', () => {
        map.show();
        button.hidden = true;
    });
}
