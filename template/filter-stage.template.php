<section id="hmu-filter-stage" class="hmu-filter-stage rotarygroup" role="dialog" aria-modal="true" aria-labelledby="hmu-filter-title" hidden>
    <header class="hmu-filter-topbar">
        <div class="hmu-filter-topbar-inner">
            <div class="hmu-filter-brand">
                <img src="resources/img/background/logohome.png" width="52" height="52" alt="" />
                <div><strong>HMU PHOTOBOOTH</strong><span>Trải nghiệm bắt trọn từng khoảnh khắc</span></div>
            </div>
            <button type="button" id="hmu-filter-back" class="hmu-filter-back"><i class="fa fa-arrow-left" aria-hidden="true"></i><span>Chọn lại khung</span></button>
            <ol class="hmu-filter-progress" aria-label="Tiến trình chụp ảnh">
                <li class="is-complete"><span class="hmu-filter-step-number"><i class="fa fa-check" aria-hidden="true"></i></span><span>Chọn khung</span></li>
                <li class="is-current" aria-current="step"><span class="hmu-filter-step-number">2</span><span>Xem trước &amp; Hiệu ứng</span></li>
                <li><span class="hmu-filter-step-number">3</span><span>Chụp ảnh</span></li>
                <li><span class="hmu-filter-step-number">4</span><span>Nhận ảnh</span></li>
            </ol>
            <span class="hmu-filter-topbar-note"><i class="fa fa-camera" aria-hidden="true"></i> Góc tạo kỷ niệm</span>
        </div>
    </header>
    <div class="hmu-filter-inner">
        <div class="hmu-filter-heading">
            <div class="hmu-filter-heading-copy">
                <p class="hmu-filter-step"><i class="fa fa-magic" aria-hidden="true"></i> BƯỚC 2 · HIỆU ỨNG &amp; PREVIEW CAMERA</p>
                <h1 id="hmu-filter-title" tabindex="-1">Chọn hiệu ứng của bạn</h1>
                <p class="hmu-filter-intro">Thử trực tiếp trên camera. Tạo dáng thật tự tin và sẵn sàng cho khoảnh khắc của bạn!</p>
            </div>
        </div>
        <div class="hmu-filter-workspace">
            <div class="hmu-filter-preview">
                <div class="hmu-filter-viewfinder">
                    <div class="hmu-filter-camera" id="hmu-filter-camera">
                        <div id="hmu-filter-surface" class="hmu-filter-surface">
                            <video id="hmu-filter-video" autoplay muted playsinline></video>
                            <img id="hmu-filter-ipcam" alt="Camera trực tiếp" hidden>
                            <canvas id="hmu-beauty-surface" aria-hidden="true" hidden></canvas>
                            <canvas id="hmu-filter-overlay" aria-hidden="true"></canvas>
                        </div>
                        <img id="hmu-preview-frame" class="hmu-preview-frame" alt="Khung ảnh đã chọn" hidden>
                    </div>
                    <span class="hmu-filter-live"><span aria-hidden="true"></span> CAMERA PREVIEW</span>
                    <span class="hmu-filter-camera-caption"><i class="fa fa-camera" aria-hidden="true"></i> Khoảnh khắc của bạn, phong cách của bạn</span>
                </div>
                <div class="hmu-filter-camera-info">
                    <i class="fa fa-info-circle" aria-hidden="true"></i>
                    <p id="hmu-filter-status" class="hmu-filter-status" role="status" aria-live="polite">Đang chuẩn bị camera…</p>
                </div>
                <div class="hmu-filter-preview-tip"><i class="fa fa-lightbulb-o" aria-hidden="true"></i><span>Một chút ánh sáng, một nụ cười — sẵn sàng cho bức ảnh thật đẹp.</span></div>
            </div>
            <div class="hmu-filter-settings">
                <section class="hmu-filter-group hmu-camera-settings" aria-labelledby="hmu-camera-title">
                    <details class="hmu-filter-section" open>
                        <summary class="rotaryfocus"><h2 id="hmu-camera-title">Nguồn camera</h2></summary>
                        <label for="hmu-camera-source">Chụp ảnh bằng</label>
                        <select id="hmu-camera-source">
                            <option value="browser">Camera trên thiết bị / Webcam USB</option>
                            <option value="external">Máy ảnh ngoài (DSLR / Mirrorless)</option>
                        </select>
                        <label for="hmu-camera-device">Camera xem trước</label>
                        <select id="hmu-camera-device"><option value="">Camera mặc định</option></select>
                        <p id="hmu-camera-help">Cho phép truy cập camera để xem trước và chụp ảnh.</p>
                    </details>
                </section>
                <section class="hmu-filter-group" aria-labelledby="hmu-color-title">
                    <details class="hmu-filter-section">
                        <summary class="rotaryfocus"><h2 id="hmu-color-title">Màu ảnh</h2></summary>
                        <p class="hmu-filter-section-description">Chọn tông màu hợp với bạn</p>
                        <div id="hmu-color-filters" class="hmu-filter-rail hmu-filter-options-list" role="group" aria-label="Chọn màu ảnh"></div>
                    </details>
                </section>
                <section class="hmu-filter-group hmu-beauty-group" aria-labelledby="hmu-beauty-title">
                    <div class="hmu-filter-card-heading">
                        <span class="hmu-filter-card-icon"><i class="fa fa-magic" aria-hidden="true"></i></span>
                        <div><h2 id="hmu-beauty-title">Làm đẹp &amp; Chỉnh ảnh</h2><p>21 tinh chỉnh · 6 phong cách</p></div>
                    </div>
                    <div id="hmu-beauty-controls"></div>
                    <div class="hmu-beauty-actions">
                        <button id="hmu-beauty-compare" type="button" aria-pressed="false" disabled>Giữ để xem ảnh trước</button>
                        <button id="hmu-beauty-split" type="button" aria-pressed="false" disabled>Chia đôi</button>
                        <button id="hmu-beauty-mask" type="button" aria-pressed="false" disabled>Vùng tác động</button>
                        <button id="hmu-beauty-mirror" type="button" aria-pressed="false">Lật gương</button>
                        <button id="hmu-beauty-reset" type="button" disabled>Đặt lại</button>
                    </div>
                    <label class="hmu-beauty-split-label" for="hmu-beauty-split-range" hidden>Vị trí so sánh<input id="hmu-beauty-split-range" type="range" min="0" max="100" value="50"></label>
                    <p id="hmu-beauty-help" class="hmu-beauty-help" role="status">Chọn một phong cách hoặc mở nhóm để tự điều chỉnh.</p>
                </section>
                <section class="hmu-filter-group hmu-filter-group-face" aria-labelledby="hmu-face-title">
                    <details class="hmu-filter-section">
                        <summary class="rotaryfocus"><h2 id="hmu-face-title">Hiệu ứng khuôn mặt</h2></summary>
                        <p class="hmu-filter-section-description">Thêm chút vui nhộn cho bức ảnh</p>
                        <div id="hmu-face-filters" class="hmu-filter-rail hmu-filter-options-list" role="group" aria-label="Chọn hiệu ứng khuôn mặt"></div>
                    </details>
                </section>
                <section class="hmu-filter-timer" aria-labelledby="hmu-timer-title">
                    <span class="hmu-filter-card-icon"><i class="fa fa-clock-o" aria-hidden="true"></i></span>
                    <div class="hmu-filter-timer-copy"><h2 id="hmu-timer-title">Hẹn giờ đếm ngược</h2><p>Thời gian chuẩn bị tạo dáng</p></div>
                    <div class="hmu-filter-timer-values">
                        <?php if ($config['picture']['enabled']): ?><span class="hmu-filter-timer-photo"><span>Ảnh đơn</span><strong><?= (int) $config['picture']['cntdwn_time'] ?>s</strong></span><?php endif; ?>
                    </div>
                </section>
            </div>
        </div>
        <footer class="hmu-filter-footer"><span>HMU Photobooth · Lưu giữ những khoảnh khắc đẹp</span><span><i class="fa fa-heart-o" aria-hidden="true"></i> Tự tin là hiệu ứng đẹp nhất</span></footer>
    </div>
    <div class="hmu-filter-confirm">
        <div class="hmu-filter-confirm-inner">
            <div class="hmu-filter-selection"><span class="hmu-filter-selection-icon"><i class="fa fa-check" aria-hidden="true"></i></span><div><span class="hmu-filter-selection-label">LỰA CHỌN CỦA BẠN</span><p id="hmu-filter-choice">Ảnh gốc · Không hiệu ứng</p></div></div>
            <button id="hmu-filter-shoot" class="hmu-filter-shoot" type="button" disabled><span class="hmu-filter-shoot-icon"><i class="fa fa-camera" aria-hidden="true"></i></span><span>Bắt đầu chụp ảnh</span><i class="fa fa-angle-right" aria-hidden="true"></i></button>
        </div>
    </div>
</section>
