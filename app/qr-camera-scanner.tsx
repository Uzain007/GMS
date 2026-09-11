"use client";

import { Camera, CameraOff, RefreshCw, ShieldCheck, SwitchCamera, X } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

type DetectedBarcode = { rawValue: string };
type BarcodeDetectorInstance = { detect(source: CanvasImageSource): Promise<DetectedBarcode[]> };
type BarcodeDetectorConstructor = new (options: { formats: string[] }) => BarcodeDetectorInstance;
type JsQrDecoder = typeof import("jsqr").default;

type ScannerProps = {
  branchName: string;
  onScan: (credential: string) => Promise<void>;
  onClose: () => void;
  onManualFallback: () => void;
};

export function cameraErrorMessage(error: unknown): string {
  const name = error instanceof DOMException
    ? error.name
    : typeof error === "object" && error !== null && "name" in error
      ? String(error.name)
      : "";
  if (name === "NotAllowedError" || name === "SecurityError") {
    return "Camera permission is required to scan member QR codes.";
  }
  if (name === "NotFoundError" || name === "DevicesNotFoundError") {
    return "No camera was found. Connect a webcam or use Member Code instead.";
  }
  if (name === "NotReadableError" || name === "TrackStartError") {
    return "The camera is already in use or unavailable. Close other camera apps and try again.";
  }
  if (name === "OverconstrainedError") {
    return "That camera is unavailable. Choose another camera or use Member Code instead.";
  }
  return "IronCore could not start the camera. Check browser permissions or use Member Code instead.";
}

function detectorConstructor(): BarcodeDetectorConstructor | null {
  return (globalThis as typeof globalThis & { BarcodeDetector?: BarcodeDetectorConstructor }).BarcodeDetector ?? null;
}

function decodeCanvasFrame(
  video: HTMLVideoElement,
  canvas: HTMLCanvasElement,
  context: CanvasRenderingContext2D,
  decoder: JsQrDecoder,
): string | null {
  if (!video.videoWidth || !video.videoHeight) return null;

  // Cap fallback decoding work on slower phones while keeping enough source
  // detail for dense access credentials and poorly lit reception cameras.
  const scale = Math.min(1, 960 / Math.max(video.videoWidth, video.videoHeight));
  const width = Math.max(1, Math.round(video.videoWidth * scale));
  const height = Math.max(1, Math.round(video.videoHeight * scale));
  if (canvas.width !== width) canvas.width = width;
  if (canvas.height !== height) canvas.height = height;
  context.drawImage(video, 0, 0, width, height);
  const pixels = context.getImageData(0, 0, width, height);
  return decoder(pixels.data, width, height, { inversionAttempts: "attemptBoth" })?.data?.trim() || null;
}

export function QrCameraScanner({ branchName, onScan, onClose, onManualFallback }: ScannerProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const frameRef = useRef<number | null>(null);
  const sessionRef = useRef(0);
  const processingRef = useRef(false);
  const lastFrameAtRef = useRef(0);
  const lastScanRef = useRef<{ value: string; at: number } | null>(null);
  const scanHandlerRef = useRef(onScan);
  const closeHandlerRef = useRef(onClose);
  const [devices, setDevices] = useState<MediaDeviceInfo[]>([]);
  const [deviceId, setDeviceId] = useState("");
  const [status, setStatus] = useState("Requesting camera access…");
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { scanHandlerRef.current = onScan; }, [onScan]);
  useEffect(() => { closeHandlerRef.current = onClose; }, [onClose]);

  const stopCamera = useCallback(() => {
    sessionRef.current += 1;
    if (frameRef.current !== null) cancelAnimationFrame(frameRef.current);
    frameRef.current = null;
    streamRef.current?.getTracks().forEach((track) => track.stop());
    streamRef.current = null;
    if (videoRef.current) videoRef.current.srcObject = null;
  }, []);

  const startCamera = useCallback(async (preferredDeviceId = "") => {
    stopCamera();
    const session = sessionRef.current;
    setError(null);
    setStatus("Requesting camera access…");
    lastScanRef.current = null;

    if (!window.isSecureContext) {
      setError("Camera scanning requires a secure HTTPS connection. Please enter Member Code manually.");
      setStatus("Secure camera access unavailable");
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      setError("Your browser does not support QR scanning. Please enter Member Code manually.");
      setStatus("Camera scanner unavailable");
      return;
    }

    try {
      const stream = await navigator.mediaDevices.getUserMedia({
        audio: false,
        video: preferredDeviceId
          ? { deviceId: { exact: preferredDeviceId } }
          : { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 } },
      });
      if (session !== sessionRef.current) {
        stream.getTracks().forEach((track) => track.stop());
        return;
      }

      streamRef.current = stream;
      const selected = stream.getVideoTracks()[0]?.getSettings().deviceId ?? preferredDeviceId;
      setDeviceId(selected);
      try {
        const available = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === "videoinput");
        setDevices(available);
      } catch {
        // Some mobile browsers can stream a permitted camera but do not expose
        // the device list. Scanning remains available with the active camera.
        setDevices([]);
      }

      const video = videoRef.current;
      if (!video) {
        stopCamera();
        return;
      }
      video.srcObject = stream;
      await video.play();
      setStatus("Hold the member QR code inside the frame");

      let detector: BarcodeDetectorInstance | null = null;
      const Detector = detectorConstructor();
      if (Detector) {
        try {
          detector = new Detector({ formats: ["qr_code"] });
        } catch {
          detector = null;
        }
      }
      const fallbackDecoder = detector ? null : (await import("jsqr")).default;
      const canvas = detector ? null : document.createElement("canvas");
      const context = canvas?.getContext("2d", { willReadFrequently: true }) ?? null;
      if (!detector && (!fallbackDecoder || !canvas || !context)) {
        stopCamera();
        setError("Your browser does not support QR scanning. Please enter Member Code manually.");
        setStatus("Camera scanner unavailable");
        return;
      }

      const scan = async (frameTime: number) => {
        if (session !== sessionRef.current) return;
        // Native detection is inexpensive; the JavaScript fallback is throttled
        // so older Android and iOS devices stay responsive while scanning.
        const interval = detector ? 90 : 160;
        if (!processingRef.current && frameTime - lastFrameAtRef.current >= interval && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
          lastFrameAtRef.current = frameTime;
          processingRef.current = true;
          try {
            const value = detector
              ? (await detector.detect(video))[0]?.rawValue?.trim()
              : decodeCanvasFrame(video, canvas!, context!, fallbackDecoder!);
            const previous = lastScanRef.current;
            if (value && (!previous || previous.value !== value || Date.now() - previous.at > 3000)) {
              lastScanRef.current = { value, at: Date.now() };
              setStatus("QR found — checking membership and branch access…");
              try {
                await scanHandlerRef.current(value);
                stopCamera();
                closeHandlerRef.current();
                return;
              } catch (scanError) {
                setError(scanError instanceof Error ? scanError.message : "This QR code could not be checked in.");
                setStatus("Scan another QR or use Member Code");
              }
            }
          } catch {
            // Individual frames can be unreadable while a QR is moving. Keep
            // scanning without exposing camera internals to reception staff.
          } finally {
            processingRef.current = false;
          }
        }
        frameRef.current = requestAnimationFrame((nextFrameTime) => void scan(nextFrameTime));
      };
      frameRef.current = requestAnimationFrame((frameTime) => void scan(frameTime));
    } catch (cameraError) {
      if (session === sessionRef.current) {
        stopCamera();
        setError(cameraErrorMessage(cameraError));
        setStatus("Camera could not start");
      }
    }
  }, [stopCamera]);

  useEffect(() => {
    let cancelled = false;
    queueMicrotask(() => {
      if (!cancelled) void startCamera();
    });
    return () => {
      cancelled = true;
      stopCamera();
    };
  }, [startCamera, stopCamera]);

  function close() {
    stopCamera();
    onClose();
  }

  return <div className="modal-layer qr-scanner-layer" role="dialog" aria-modal="true" aria-label="Scan QR with Camera">
    <button className="modal-scrim" onClick={close} aria-label="Close camera scanner" />
    <section className="qr-scanner-card">
      <header><span><Camera size={21} /></span><div><p className="eyebrow">Secure reception check-in</p><h2>Scan QR with Camera</h2><small>{branchName || "Select a branch"}</small></div><button className="icon-button" onClick={close} aria-label="Close scanner"><X size={18} /></button></header>
      <div className="qr-video-shell">
        <video ref={videoRef} muted playsInline autoPlay aria-label="Live camera view" />
        <div className="qr-scan-frame" aria-hidden="true"><i /><i /><i /><i /></div>
        <div className="qr-camera-status" aria-live="polite">{status}</div>
      </div>
      {devices.length > 1 && <label className="qr-camera-select"><SwitchCamera size={16} /><span>Camera</span><select value={deviceId} onChange={(event) => void startCamera(event.target.value)}>{devices.map((device, index) => <option key={device.deviceId} value={device.deviceId}>{device.label || `Camera ${index + 1}`}</option>)}</select></label>}
      {error && <div className="qr-camera-error" role="alert"><CameraOff size={18} /><span>{error}</span><button type="button" onClick={() => void startCamera(deviceId)}><RefreshCw size={14} /> Try again</button></div>}
      <div className="qr-scanner-safety"><ShieldCheck size={17} /><span><strong>Verified by IronCore</strong><small>The backend checks the secure QR token, selected gym, membership, branch and duplicate status.</small></span></div>
      <footer><button className="secondary-button" type="button" onClick={() => { stopCamera(); onManualFallback(); }}>Use Member Code instead</button><button className="secondary-button" type="button" onClick={close}>Cancel</button></footer>
    </section>
  </div>;
}
