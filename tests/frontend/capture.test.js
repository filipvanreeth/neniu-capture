import test from 'node:test';
import assert from 'node:assert/strict';
import { Camera } from '../../assets/js/camera.js';
import { VerificationApi } from '../../assets/js/verification-api.js';
import { getLocation } from '../../assets/js/location.js';

function mockNavigator(t, value) {
    const descriptor = Object.getOwnPropertyDescriptor(globalThis, 'navigator');
    Object.defineProperty(globalThis, 'navigator', { configurable: true, value });
    t.after(() => {
        if (descriptor) Object.defineProperty(globalThis, 'navigator', descriptor);
        else delete globalThis.navigator;
    });
}

test('camera switches facing mode and stops the previous stream', async t => {
    let stopped = 0;
    const constraints = [];
    const track = { stop: () => stopped++, getSettings: () => ({}) };
    mockNavigator(t, { mediaDevices: { getUserMedia: async options => {
        constraints.push(options);
        return { getTracks: () => [track], getVideoTracks: () => [track] };
    } } });
    const video = { play: async () => {}, style: { removeProperty() {} } };
    const camera = new Camera(video);
    await camera.open('user');
    await camera.open('environment', true);
    assert.equal(stopped, 1);
    assert.equal(camera.facingMode, 'environment');
    assert.deepEqual(constraints[1].video.facingMode, { exact: 'environment' });
    camera.stop();
    assert.equal(stopped, 2);
    assert.equal(video.srcObject, null);
});

test('denied camera access leaves the camera restartable', async t => {
    let denied = true;
    const track = { stop() {}, getSettings: () => ({ facingMode: 'user' }) };
    mockNavigator(t, { mediaDevices: { getUserMedia: async () => {
        if (denied) throw new Error('Permission denied');
        return { getTracks: () => [track], getVideoTracks: () => [track] };
    } } });
    const camera = new Camera({ play: async () => {}, style: { removeProperty() {} } });
    await assert.rejects(camera.open(), /Permission denied/);
    assert.equal(camera.stream, null);
    denied = false;
    await camera.open();
    assert.ok(camera.stream);
});

test('location permission errors reach the caller', async t => {
    mockNavigator(t, { geolocation: { getCurrentPosition: (resolve, reject, options) => {
        assert.equal(options.maximumAge, 0);
        reject(new Error('Location denied'));
    } } });
    await assert.rejects(getLocation(), /Location denied/);
});

test('upload includes session token and photo/location timestamps', async t => {
    t.mock.method(globalThis, 'fetch', async (url, options) => {
        assert.equal(url, '/app/?lang=en');
        assert.equal(options.method, 'POST');
        assert.equal(options.body.get('session_token'), 'one-time-token');
        assert.equal(options.body.get('captured_at'), '1234');
        assert.equal(options.body.get('location_timestamp'), '1200');
        assert.equal(options.body.get('lat'), '50');
        return { ok: true, json: async () => ({ code: 'saved' }) };
    });
    const api = new VerificationApi('/app/?lang=en', 'Save failed');
    assert.deepEqual(await api.save({ token: 'one-time-token' }, { image: 'jpeg', capturedAt: 1234 },
        { timestamp: 1200.8, coords: { latitude: 50, longitude: 4, accuracy: 12 } }), { code: 'saved' });
});

test('API and network errors are propagated', async t => {
    const fetch = t.mock.method(globalThis, 'fetch', async () => ({
        ok: false, json: async () => ({ error: 'Session expired' }),
    }));
    const api = new VerificationApi('/', 'Save failed');
    await assert.rejects(api.issueSession(), /Session expired/);
    fetch.mock.mockImplementation(async () => { throw new Error('Offline'); });
    await assert.rejects(api.issueSession(), /Offline/);
});
