import 'vite/modulepreload-polyfill';
import { Camera } from './camera.js';
import { getLocation } from './location.js';
import { VerificationApi } from './verification-api.js';
import { takePhoto, drawPreview } from './photo.js';

const { messages, endpoint } = JSON.parse(document.querySelector('#capture-config').textContent);
function t(key, parameters = {}) {
    return (messages[key] ?? key).replace(/\{(\w+)\}/g,
        (placeholder, name) => Object.hasOwn(parameters, name) ? String(parameters[name]) : placeholder);
}

const video = document.querySelector('#video');
const canvas = document.querySelector('#canvas');
const status = document.querySelector('#status');
const start = document.querySelector('#start');
const capture = document.querySelector('#capture');
const switchCamera = document.querySelector('#switch-camera');
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
        switchCamera.textContent = t(camera.facingMode === 'user' ? 'rear_camera' : 'front_camera');
        status.textContent = t('camera_ready');
    } catch (error) {
        status.textContent = t('camera_unavailable', { message: error.message });
        throw error;
    } finally {
        setBusy(false);
    }
}

start.addEventListener('click', () => openCamera().catch(() => {}));
switchCamera.addEventListener('click', async () => {
    const previousMode = camera.facingMode;
    try {
        await openCamera(previousMode === 'user' ? 'environment' : 'user', true);
    } catch {
        try {
            await openCamera(previousMode);
            status.textContent = t('camera_restored');
        } catch { /* openCamera displays the error. */ }
    }
});

capture.addEventListener('click', async () => {
    setBusy(true);
    status.textContent = t('requesting_location');
    try {
        const session = await api.issueSession();
        const position = await getLocation();
        const photo = takePhoto(video, t('camera_not_ready'));
        status.textContent = t('saving_photo');
        const data = await api.save(session, photo, position);
        drawPreview(canvas, photo, data.code, position);
        video.hidden = true;
        canvas.hidden = false;
        camera.stop();
        status.textContent = t('photo_saved');
    } catch (error) {
        status.textContent = t('error', { message: error.message });
    } finally {
        setBusy(false);
    }
});
window.addEventListener('pagehide', () => camera.stop());
