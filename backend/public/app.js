document.querySelectorAll('[data-print]').forEach(button => button.addEventListener('click', () => window.print()));
document.querySelectorAll('[data-camera-scan]').forEach(button => button.addEventListener('click', async () => {
  const area = document.querySelector('[data-scanner]');
  const status = area.querySelector('[data-scanner-status]');
  const video = area.querySelector('video');
  area.hidden = false;
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) {
    status.textContent = 'Camera chưa hỗ trợ quét QR trên trình duyệt này. Vui lòng dùng máy quét tại cổng.';
    return;
  }
  let stream;
  const stop = () => { stream?.getTracks().forEach(track => track.stop()); video.srcObject = null; button.disabled = false; };
  window.addEventListener('pagehide', stop, {once: true});
  try {
    button.disabled = true;
    stream = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'environment'}});
    video.srcObject = stream;
    await video.play();
    status.textContent = 'Đưa mã QR vào khung hình.';
    const detector = new BarcodeDetector({formats: ['qr_code']});
    const deadline = Date.now() + 60000;
    while (video.srcObject && Date.now() < deadline) {
      const codes = await detector.detect(video);
      const code = codes.find(item => /^[a-f0-9]{64}$/i.test(item.rawValue));
      if (code) { document.getElementById('token').value = code.rawValue; status.textContent = 'Đã đọc thẻ. Kiểm tra chuyến bay và xác nhận lên tàu.'; stop(); return; }
      await new Promise(resolve => setTimeout(resolve, 250));
    }
    status.textContent = 'Đã dừng quét. Bạn có thể bật camera để thử lại.';
  } catch { status.textContent = 'Không mở được camera. Kiểm tra quyền truy cập hoặc dùng máy quét tại cổng.'; }
  finally { stop(); }
}));
