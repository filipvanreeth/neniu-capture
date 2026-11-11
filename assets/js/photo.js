export function takePhoto(video, notReadyMessage) {
    if (!video.videoWidth || !video.videoHeight) throw new Error(notReadyMessage);
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const capturedAt = Date.now();
    return { canvas, capturedAt, image: canvas.toDataURL('image/jpeg', 0.85) };
}

export function drawPreview(canvas, photo) {
    canvas.width = photo.canvas.width;
    canvas.height = photo.canvas.height;
    canvas.getContext('2d').drawImage(photo.canvas, 0, 0);
}
