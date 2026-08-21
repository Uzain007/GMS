"use client";

import { Camera, CameraOff, RefreshCw, ShieldCheck, SwitchCamera, X } from "lucide-react";
import { useCallback, useEffect, useRef, useState } from "react";

type DetectedBarcode = { rawValue: string };
type BarcodeDetectorInstance = { detect(source: CanvasImageSource): Promise<DetectedBarcode[]> };
type BarcodeDetectorConstructor = new (options: { formats: string[] }) => BarcodeDetectorInstance;

type ScannerProps = {
  branchName: string;
  onScan: (credential: string) => Promise<void>;
  onClose: () => void;
  onManualFallback: () => void;
};

export function cameraErrorMessage(error: unknown): string {
  const name = error instanceof DOMException ? error.name : "";
  if (name === "NotAllowedError" || name === "SecurityError") {
    return "Camera permission was denied. Allow camera access in your browser, or use Member Code instead.";
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

export function QrCameraScanner({ branchName, onScan, onClose, onManualFallback }: ScannerProps) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const streamRef = useRef<MediaStream | null>(null);
  const frameRef = useRef<number | null>(null);
  const sessionRef = useRef(0);
  const processingRef = useRef(false);
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

    const Detector = detectorConstructor();
    if (!Detector) {
      setError("This browser cannot read QR codes from a live camera. Use a current Chrome or Edge browser, or enter the Member Code.");
      setStatus("Camera scanner unavailable");
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      setError("Camera access is not available in this browser. Use Member Code instead.");
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
      const available = (await navigator.mediaDevices.enumerateDevices()).filter((device) => device.kind === "videoinput");
      setDevices(available);

      const video = videoRef.current;
      if (!video) return;
      video.srcObject = stream;
      await video.play();
      setStatus("Hold the member QR code inside the frame");

      const detector = new Detector({ formats: ["qr_code"] });
      const scan = async () => {
        if (session !== sessionRef.current) return;
        if (!processingRef.current && video.readyState >= HTMLMediaElement.HAVE_CURRENT_DATA) {
          processingRef.current = true;
          try {
            const result = (await detector.detect(video))[0];
            const value = result?.rawValue?.trim();
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
        frameRef.current = requestAnimationFrame(() => void scan());
      };
      frameRef.current = requestAnimationFrame(() => void scan());
    } catch (cameraError) {
      if (session === sessionRef.current) {
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
        <video ref={videoRef} muted playsInline aria-label="Live camera view" />
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
