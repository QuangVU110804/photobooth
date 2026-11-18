<?php $allowMultiFrames = $config['picture']['enabled'] && !$config['button']['force_buzzer']; ?>
		<!-- Welcome and frame selection -->
		<section class="stages hmu-start rotarygroup" id="start" aria-labelledby="hmu-hero-title">
			<header class="hmu-header">
				<a class="hmu-brand" href="#start" aria-label="HMU Photobooth - Trang chủ">
					<img src="resources/img/background/logohome.png" width="40" height="40" alt="" />
					<span>HMU <strong>Photobooth</strong></span>
				</a>
				<nav class="hmu-nav" aria-label="Điều hướng chính">
					<a class="hmu-nav-link is-current" href="#start" aria-current="page">Trang chủ</a>
					<?php if ($config['picture']['enabled'] && !$config['button']['force_buzzer']): ?>
					<button type="button" id="hmu-guide-start" class="hmu-nav-link hmu-guide-start rotaryfocus"><i class="fa fa-question-circle" aria-hidden="true"></i> Hướng dẫn</button>
					<?php endif; ?>
					<?php if ($config['picture']['enabled']): ?>
					<a class="hmu-nav-link takePic" href="#capture"><i class="<?php echo $config['icons']['take_picture']; ?>" aria-hidden="true"></i> Chụp ảnh</a>
					<?php endif; ?>
					<?php if ($config['gallery']['enabled']): ?>
					<a class="hmu-nav-link gallery-button" href="#gallery"><i class="<?php echo $config['icons']['gallery']; ?>" aria-hidden="true"></i> Thư viện</a>
					<?php endif; ?>
				</nav>
				<?php include __DIR__ . '/color-mode.template.php'; ?>
			</header>
			<?php if ($allowMultiFrames): ?>
			<div class="hmu-capture-mode-bar">
				<div id="hmu-capture-mode" class="hmu-capture-mode" role="group" aria-label="Chế độ chụp">
					<button type="button" class="rotaryfocus" data-capture-mode="single" aria-pressed="true" aria-controls="hmuFramePicker">Ảnh đơn</button>
					<button type="button" class="rotaryfocus" data-capture-mode="multi" aria-pressed="false" aria-controls="hmuMultiFramePicker">Bộ ảnh</button>
				</div>
			</div>
			<?php endif; ?>

			<main class="hmu-main">
				<section class="hmu-hero" aria-labelledby="hmu-hero-title">
					<div class="hmu-hero-copy">
						<p class="hmu-eyebrow"><span class="hmu-eyebrow-mark" aria-hidden="true"><i class="fa fa-camera"></i></span> TRẢI NGHIỆM CHỤP ẢNH CÙNG HMU</p>
						<h1 id="hmu-hero-title">Lưu giữ khoảnh khắc<br /><span>theo cách của bạn.</span></h1>
						<p class="hmu-hero-description">Chọn khung ảnh yêu thích, tạo dáng thật tự tin và lưu lại những kỷ niệm thật đẹp cùng HMU Photobooth.</p>
						<div class="hmu-actions rotarygroup">
							<?php if ($config['button']['force_buzzer']): ?>
								<div id="useBuzzer" class="hmu-buzzer-prompt"><span data-i18n="use_button"></span></div>
							<?php else: ?>
								<?php if ($config['picture']['enabled']): ?>
								<a href="#" class="hmu-button hmu-button-primary <?php echo $btnClass; ?> takePic rotaryfocus"><i class="<?php echo $config['icons']['take_picture']; ?>" aria-hidden="true"></i><span>Chọn hiệu ứng và chụp</span></a>
								<?php endif; ?>
							<?php endif; ?>
							<?php if ($config['gallery']['enabled']): ?>
							<a href="#" class="hmu-button hmu-button-quiet <?php echo $btnClass; ?> gallery-button rotaryfocus"><i class="<?php echo $config['icons']['gallery']; ?>" aria-hidden="true"></i><span>Xem thư viện</span></a>
							<?php endif; ?>
							<?php if ($config['button']['show_fs']): ?>
							<a href="#" id="fs-button" class="hmu-button hmu-button-quiet <?php echo $btnClass; ?> fs-button"><i class="<?php echo $config['icons']['fullscreen']; ?>" aria-hidden="true"></i><span data-i18n="toggleFullscreen"></span></a>
							<?php endif; ?>
						</div>
						<div class="hmu-flow" aria-label="Quy trình chụp ảnh gồm ba bước">
							<span class="hmu-flow-lead"><i class="fa fa-bolt" aria-hidden="true"></i> Từng bước tạo kỷ niệm</span>
							<span class="hmu-flow-step"><b>1</b> Chọn khung ảnh</span><span class="hmu-flow-arrow" aria-hidden="true">→</span>
							<span class="hmu-flow-step"><b>2</b> Chọn hiệu ứng</span><span class="hmu-flow-arrow" aria-hidden="true">→</span>
							<span class="hmu-flow-step"><b>3</b> Chụp và lưu ảnh</span>
						</div>
					</div>
					<?php
					require_once dirname(__DIR__) . '/lib/frames.php';
					$framesDir = dirname(__DIR__) . '/resources/img/frames';
					$firstFrame = listPhotoFrames($framesDir)[0] ?? listPhotoFrames($framesDir, true)[0] ?? null;
					$activeFrameFile = $framesDir . '/current_active_frame.png';
					if ($firstFrame !== null && (!is_file($activeFrameFile) || filesize($activeFrameFile) === 0)) {
						@copy($framesDir . '/' . $firstFrame, $activeFrameFile);
					}
					$heroFrameSrc = 'resources/img/frames/' . implode('/', array_map('rawurlencode', explode('/', $firstFrame ?? 'current_active_frame.png')));
					?>
					<aside class="hmu-hero-art" aria-label="Khung ảnh HMU đang được chọn">
						<div class="hmu-art-orbit hmu-art-orbit-one" aria-hidden="true"></div>
						<div class="hmu-art-orbit hmu-art-orbit-two" aria-hidden="true"></div>
						<div class="hmu-preview-badge"><span class="hmu-preview-check" aria-hidden="true"><i class="fa fa-check"></i></span><span>Khung đang chọn</span></div>
						<div class="hmu-hero-frame-card">
							<div class="hmu-frame-card-top"><span>HMU STUDIO</span><span class="hmu-frame-id">MEMORIES</span></div>
							<div class="hmu-hero-frame-window"><img id="hmuHeroFrame" src="<?php echo htmlspecialchars($heroFrameSrc, ENT_QUOTES, 'UTF-8'); ?>" alt="Bản xem trước khung ảnh đang chọn" /></div>
							<div class="hmu-frame-card-bottom"><span>HMU MEMORIES</span><span>Hà Nội, Việt Nam</span></div>
						</div>
						<div class="hmu-preview-caption" id="hmuPreviewCaption"><a href="#hmu-templates" class="hmu-preview-scroll-btn" role="button" aria-label="Cuộn xuống chọn mẫu khung ảnh"><i class="fa fa-picture-o" aria-hidden="true"></i> <span>Xem trước khung ảnh</span> <i class="fa fa-angle-down" aria-hidden="true"></i></a></div>
					</aside>
				</section>

				<section id="hmu-templates" class="hmu-templates" aria-labelledby="hmu-templates-title">
					<div class="hmu-section-heading">
						<div><p class="hmu-section-kicker"><i class="fa fa-clone" aria-hidden="true"></i> BƯỚC 1 / 3: CHỌN MẪU KHUNG</p>
						<h2 id="hmu-templates-title">Chọn khung ảnh bạn yêu thích</h2>
						<p class="hmu-section-description">Xem trước từng thiết kế và chọn khung phù hợp với khoảnh khắc của bạn.</p></div>
					</div>
					<div id="hmuFramePicker">
						<div id="hmuFrameTopics" class="hmu-filter-chips" role="group" aria-label="Chủ đề khung ảnh" hidden></div>
						<div id="kioskFrameSelector" class="hmu-frame-grid" aria-live="polite" aria-busy="true"></div>
						<p id="hmuFrameError" class="hmu-frame-error" role="status" hidden>Không tải được danh sách khung ảnh. Hãy thử tải lại trang.</p>
					</div>
					<?php if ($allowMultiFrames): ?>
					<div id="hmuMultiFramePicker" hidden>
						<div class="hmu-multishot-layout">
							<label for="hmu-multishot-layout">Bố cục</label>
							<select id="hmu-multishot-layout" class="rotaryfocus">
								<option value="">Tất cả bố cục</option>
								<option value="1x2">1×2 · 2 ảnh</option>
								<option value="1x3">1×3 · 3 ảnh</option>
								<option value="1x4">1×4 · 4 ảnh</option>
								<option value="2x2">2×2 · 4 ảnh</option>
								<option value="2x3">2×3 · 6 ảnh</option>
							</select>
						</div>
						<div id="hmuMultiFrameTopics" class="hmu-filter-chips" role="group" aria-label="Chủ đề khung bộ ảnh" hidden></div>
						<p id="hmuMultiFrameError" class="hmu-frame-error" role="status" hidden></p>
						<div id="hmuMultiFrameSelector" class="hmu-frame-grid" aria-live="polite" tabindex="-1"></div>
					</div>
					<?php endif; ?>
				</section>

			</main>
			<footer class="hmu-footer"><span>© 2025 HMU Photobooth. Nền tảng ghi lại kỷ niệm sự kiện.</span><span>Thiết kế cho trải nghiệm chụp ảnh cảm ứng</span></footer>
			<?php if ($config['button']['show_cups']): ?>
			<a id="cups-button" class="<?php echo $btnClass; ?> cups-button rotaryfocus" href="#" target="newwin"><i class="<?php echo $config['icons']['cups']; ?>" aria-hidden="true"></i> <span>CUPS</span></a>
			<?php endif; ?>
		</section>
		<div id="hmu-guide-status" class="hmu-guide-status" role="status" aria-live="polite"></div>
		<aside id="hmu-guide-wait" class="hmu-guide-wait" aria-label="Hướng dẫn camera" hidden>
			<p>Cho phép truy cập camera khi trình duyệt hỏi. Hướng dẫn sẽ tiếp tục khi camera sẵn sàng; bạn có thể chọn lại nguồn camera trên màn hình.</p>
			<button type="button" id="hmu-guide-stop">Dừng hướng dẫn</button>
		</aside>
