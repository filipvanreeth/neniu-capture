export class Camera {
    constructor(video) {
        this.video = video;
        this.stream = null;
        this.facingMode = 'user';
    }

    async open(requestedMode = this.facingMode, exact = false) {
        this.stop();
        try {
            this.stream = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: exact ? { exact: requestedMode } : { ideal: requestedMode } },
                audio: false,
            });
            this.video.srcObject = this.stream;
            await this.video.play();
            this.video.hidden = false;
            this.facingMode = this.stream.getVideoTracks()[0].getSettings().facingMode || requestedMode;
        } catch (error) {
            this.stop();
            throw error;
        }
    }

    stop() {
        this.stream?.getTracks().forEach(track => track.stop());
        this.stream = null;
        this.video.srcObject = null;
        this.video.hidden = true;
    }
}
