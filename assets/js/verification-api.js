export class VerificationApi {
    constructor(endpoint, fallbackMessage) {
        this.endpoint = endpoint;
        this.fallbackMessage = fallbackMessage;
    }

    async post(values) {
        const form = new FormData();
        for (const [key, value] of Object.entries(values)) form.append(key, String(value));
        const response = await fetch(this.endpoint, { method: 'POST', body: form });
        const data = await response.json();
        if (!response.ok) throw new Error(data.error || this.fallbackMessage);
        return data;
    }

    issueSession() {
        return this.post({ action: 'session' });
    }

    save(session, photo, position, cameraLabel = '') {
        return this.post({
            action: 'save',
            image: photo.image,
            camera_label: cameraLabel,
            session_token: session.token,
            captured_at: photo.capturedAt,
            location_timestamp: Math.trunc(position.timestamp),
            lat: position.coords.latitude,
            lon: position.coords.longitude,
            accuracy: position.coords.accuracy,
        });
    }
}
