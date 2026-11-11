export function takePhoto(video, notReadyMessage) {
    if (!video.videoWidth || !video.videoHeight) throw new Error(notReadyMessage);
    const canvas = document.createElement('canvas');
    canvas.width = video.videoWidth;
    canvas.height = video.videoHeight;
    canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
    const capturedAt = Date.now();
    return { canvas, capturedAt, image: canvas.toDataURL('image/jpeg', 0.85) };
}

export function drawPreview(canvas, photo, code, position) {
    // Display a separate annotated preview
    canvas.width = photo.canvas.width;
    canvas.height = photo.canvas.height;

    const ctx = canvas.getContext('2d');
    ctx.drawImage(photo.canvas, 0, 0);

    const fontSize = Math.max(14, canvas.width / 35);
    ctx.font = `${fontSize}px sans-serif`;

    const lines = [
        `CODE: ${code}`,
        `LOCATION: ${position.coords.latitude.toFixed(5)}, ` +
        position.coords.longitude.toFixed(5),
        `UTC: ${new Date(photo.capturedAt).toISOString()}`
    ];

    const lineHeight = fontSize * 1.4;
    const boxHeight = lineHeight * lines.length + 20;

    ctx.fillStyle = 'rgba(0,0,0,0.75)';
    ctx.fillRect(
        0,
        canvas.height - boxHeight,
        canvas.width,
        boxHeight
    );

    ctx.fillStyle = 'white';
    lines.forEach((line, index) => {
        ctx.fillText(
            line,
            10,
            canvas.height - boxHeight + 15 +
            lineHeight * (index + 0.7)
        );
    });

}
