/* Classic worker: the MediaPipe WASM loader uses importScripts. */
let task;
let runningMode = 'VIDEO';
let canvas;
let context;
self.onmessage = async ({ data }) => {
  const { id, type, bitmap } = data;
  try {
    if (type === 'init') {
      const { FaceLandmarker, FilesetResolver } = await import('../../vendor/mediapipe/vision_bundle.mjs');
      const root = new URL('../../vendor/mediapipe/', self.location.href);
      if (!await FilesetResolver.isSimdSupported()) throw new Error('Trình duyệt chưa hỗ trợ hiệu ứng khuôn mặt.');
      task = await FaceLandmarker.createFromOptions({
        wasmLoaderPath: new URL('wasm/vision_wasm_internal.js', root).href,
        wasmBinaryPath: new URL('wasm/vision_wasm_internal.wasm', root).href
      }, {
        baseOptions: { modelAssetPath: new URL('face_landmarker.task', root).href, delegate: 'CPU' },
        runningMode: 'VIDEO', numFaces: 4,
        outputFaceBlendshapes: false, outputFacialTransformationMatrixes: false
      });
      self.postMessage({ id, ready: true });
      return;
    }
    if (!task || !bitmap) throw new Error('Face detector is not ready');
    const indices = data.indices === undefined ? [33, 263, 93, 323] : data.indices;
    if (!Array.isArray(indices) || indices.length < 4 || indices.length > (data.skin ? 478 : 32) || new Set(indices).size !== indices.length
      || ![33, 263, 93, 323].every((value, i) => indices[i] === value)
      || !indices.every(i => Number.isInteger(i) && i >= 0 && i < 478)) throw new Error('Invalid face landmark request');
    const desiredMode = type === 'image' ? 'IMAGE' : 'VIDEO';
    if (runningMode !== desiredMode) { await task.setOptions({ runningMode: desiredMode }); runningMode = desiredMode; }
    if (!canvas) { canvas = new OffscreenCanvas(bitmap.width, bitmap.height); context = canvas.getContext('2d'); }
    // Assigning either dimension clears/reallocates the backing surface.
    if (canvas.width !== bitmap.width) canvas.width = bitmap.width;
    if (canvas.height !== bitmap.height) canvas.height = bitmap.height;
    context.drawImage(bitmap, 0, 0);
    const result = desiredMode === 'IMAGE' ? task.detect(canvas) : task.detectForVideo(canvas, data.timestamp);
    // Transfer only the anchors required by the selected effect.
    const faces = result.faceLandmarks.map((face) => indices.map((i) => ({ x: face[i].x, y: face[i].y })));
    self.postMessage({ id, faces });
  } catch (error) {
    self.postMessage({ id, error: error.message || 'Không tải được hiệu ứng khuôn mặt.' });
  } finally { bitmap?.close(); }
};
