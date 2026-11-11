import 'vite/modulepreload-polyfill';
import { Camera } from './camera.js';
import { getLocation } from './location.js';
import { VerificationApi } from './verification-api.js';
import { takePhoto, drawPreview } from './photo.js';
import { ResultLink } from './result-link.js';
import { StatusLabel } from './status-label.js';

const { messages, endpoint } = JSON.parse(document.querySelector('#capture-config').textContent);
function t(key, parameters = {}) {
    return (messages[key] ?? key).replace(/\{(\w+)\}/g,
        (placeholder, name) => Object.hasOwn(parameters, name) ? String(parameters[name]) : placeholder);
}

const video = document.querySelector('#video');
const canvas = document.querySelector('#canvas');
const status = new StatusLabel(document.querySelector('#status'));
const start = document.querySelector('#start');
const captureGuide = document.querySelector('#capture-guide-section');
const capture = document.querySelector('#capture');
const switchCamera = document.querySelector('#switch-camera');
const resultLink = new ResultLink(
    document.querySelector('#share-result'),
    document.querySelector('#result-url'),
    document.querySelector('#view-result'),
    document.querySelector('#copy-status'),
    messages,
);
document.querySelector('#copy-link').addEventListener('click', () => resultLink.copy());
const camera = new Camera(video);
const api = new VerificationApi(endpoint, t('save_failed'));

function setBusy(busy) {
    start.disabled = busy;
    capture.disabled = busy || !camera.stream;
    switchCamera.disabled = busy || !camera.stream;
}

async function openCamera(mode = camera.facingMode, exact = false) {
    setBusy(true);
    try {
        await camera.open(mode, exact);
        canvas.hidden = true;
        resultLink.hide();
        switchCamera.textContent = t(camera.facingMode === 'user' ? 'rear_camera' : 'front_camera');
        status.show(t('camera_ready'), 'success');
    } catch (error) {
        status.show(t('camera_unavailable', { message: error.message }), 'error');
        throw error;
    } finally {
        setBusy(false);
    }
}

start.addEventListener('click', async () => {
    captureGuide.hidden = true;
    try {
        await openCamera();
    } catch {
        captureGuide.hidden = false;
    }
});
switchCamera.addEventListener('click', async () => {
    const previousMode = camera.facingMode;
    try {
        await openCamera(previousMode === 'user' ? 'environment' : 'user', true);
    } catch {
        try {
            await openCamera(previousMode);
            status.show(t('camera_restored'), 'warning');
        } catch { /* openCamera displays the error. */ }
    }
});

capture.addEventListener('click', async () => {
    setBusy(true);
    status.show(t('requesting_location'));
    try {
        const session = await api.issueSession();
        const position = await getLocation();
        const photo = takePhoto(video, t('camera_not_ready'));
        status.show(t('saving_photo'));
        const saved = await api.save(session, photo, position);
        resultLink.show(saved.url, window.location.href);
        drawPreview(canvas, photo);
        video.hidden = true;
        canvas.hidden = false;
        camera.stop();
        status.show(t('photo_saved'), 'success');
    } catch (error) {
        status.show(t('error', { message: error.message }), 'error');
    } finally {
        setBusy(false);
    }
});
window.addEventListener('pagehide', () => camera.stop());
