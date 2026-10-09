		<!-- Welcome and frame selection -->
		<section class="stages hmu-start" id="start" aria-labelledby="hmu-hero-title">
			<header class="hmu-header">
				<a class="hmu-brand" href="#start" aria-label="HMU Photobooth - Trang chủ">
					<img src="resources/img/favicon-96x96.png" width="40" height="40" alt="" />
					<span>HMU <strong>Photobooth</strong></span>
				</a>
				<nav class="hmu-nav" aria-label="Điều hướng chính">
					<a class="hmu-nav-link is-current" href="#start" aria-current="page">Trang chủ</a>
					<?php if ($config['picture']['enabled']): ?>
					<a class="hmu-nav-link takePic" href="#capture"><i class="<?php echo $config['icons']['take_picture']; ?>" aria-hidden="true"></i> Chụp ảnh</a>
					<?php endif; ?>
					<?php if ($config['gallery']['enabled']): ?>
					<a class="hmu-nav-link gallery-button" href="#gallery"><i class="<?php echo $config['icons']['gallery']; ?>" aria-hidden="true"></i> Thư viện</a>
					<?php endif; ?>
				</nav>
				<div class="hmu-header-meta"><span class="hmu-status-dot" aria-hidden="true"></span><span>Chọn khung và bắt đầu</span></div>
			</header>

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
								<a href="#" class="hmu-button hmu-button-primary <?php echo $btnClass; ?> takePic rotaryfocus"><i class="<?php echo $config['icons']['take_picture']; ?>" aria-hidden="true"></i><span>Bắt đầu chụp ảnh</span></a>
								<?php endif; ?>
								<?php if ($config['collage']['enabled']): ?>
								<a href="#" class="hmu-button hmu-button-secondary <?php echo $btnClass; ?> takeCollage rotaryfocus"><i class="<?php echo $config['icons']['take_collage']; ?>" aria-hidden="true"></i><span>Chụp bộ ảnh</span></a>
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
							<span class="hmu-flow-lead"><i class="fa fa-bolt" aria-hidden="true"></i> 3 bước tức thì</span>
							<span class="hmu-flow-step"><b>1</b> Chọn khung ảnh</span><span class="hmu-flow-arrow" aria-hidden="true">→</span>
							<span class="hmu-flow-step"><b>2</b> Tạo dáng chụp ảnh</span><span class="hmu-flow-arrow" aria-hidden="true">→</span>
							<span class="hmu-flow-step"><b>3</b> Lưu kỷ niệm</span>
						</div>
					</div>
					<aside class="hmu-hero-art" aria-label="Khung ảnh HMU đang được chọn">
						<div class="hmu-art-orbit hmu-art-orbit-one" aria-hidden="true"></div>
						<div class="hmu-art-orbit hmu-art-orbit-two" aria-hidden="true"></div>
						<div class="hmu-preview-badge"><span class="hmu-preview-check" aria-hidden="true"><i class="fa fa-check"></i></span><span>Khung đang chọn</span></div>
						<div class="hmu-hero-frame-card">
							<div class="hmu-frame-card-top"><span>HMU STUDIO</span><span class="hmu-frame-id">MEMORIES</span></div>
							<div class="hmu-hero-frame-window"><img id="hmuHeroFrame" src="resources/img/frames/a.png" alt="Bản xem trước khung ảnh đang chọn" /></div>
							<div class="hmu-frame-card-bottom"><span>HMU MEMORIES</span><span>Hà Nội, Việt Nam</span></div>
						</div>
						<div class="hmu-preview-caption"><i class="fa fa-picture-o" aria-hidden="true"></i> Xem trước khung ảnh</div>
					</aside>
				</section>

				<section class="hmu-templates" aria-labelledby="hmu-templates-title">
					<div class="hmu-section-heading">
						<div><p class="hmu-section-kicker"><i class="fa fa-clone" aria-hidden="true"></i> BƯỚC 1 / 3: CHỌN MẪU KHUNG</p>
						<h2 id="hmu-templates-title">Chọn khung ảnh bạn yêu thích</h2>
						<p class="hmu-section-description">Xem trước từng thiết kế và chọn khung phù hợp với khoảnh khắc của bạn.</p></div>
						<div class="hmu-selected-summary" aria-live="polite"><span>Khung đã chọn:</span><strong id="hmuSelectedName">Đang tải khung…</strong></div>
					</div>
					<div class="hmu-template-tools"><div class="hmu-filter-chips" aria-label="Bộ lọc khung ảnh"><span class="hmu-filter-chip is-active">Tất cả khung <b id="hmuFrameCount">0</b></span><span class="hmu-filter-chip">HMU Collection</span></div><span class="hmu-template-hint">Chạm vào khung để chọn</span></div>
					<div id="kioskFrameSelector" class="hmu-frame-grid" aria-live="polite" aria-busy="true"></div>
					<p id="hmuFrameError" class="hmu-frame-error" role="status" hidden>Không tải được danh sách khung ảnh. Bạn vẫn có thể bắt đầu chụp.</p>
				</section>

				<aside class="hmu-tip"><span class="hmu-tip-icon" aria-hidden="true"><i class="fa fa-lightbulb-o"></i></span><div><strong>Mẹo để có bức ảnh đẹp</strong><p>Đứng thẳng trước ống kính và chọn dáng thật tự nhiên. Photobooth sẽ đếm ngược trước khi chụp.</p></div><span class="hmu-tip-mark" aria-hidden="true"><i class="fa fa-camera"></i></span></aside>
			</main>
			<footer class="hmu-footer"><span>© 2025 HMU Photobooth. Nền tảng ghi lại kỷ niệm sự kiện.</span><span>Thiết kế cho trải nghiệm chụp ảnh cảm ứng</span></footer>
			<?php if ($config['button']['show_cups']): ?>
			<a id="cups-button" class="<?php echo $btnClass; ?> cups-button rotaryfocus" href="#" target="newwin"><i class="<?php echo $config['icons']['cups']; ?>" aria-hidden="true"></i> <span>CUPS</span></a>
			<?php endif; ?>
			<?php if ($config['ui']['show_fork']): ?><a href="https://github.com/<?=$config['ui']['github']?>/photobooth" class="github-fork-ribbon" data-ribbon="Fork me on GitHub">Fork me on GitHub</a><?php endif; ?>
		</section>
