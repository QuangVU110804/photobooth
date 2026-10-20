<div id="qrPswp" class="modal">
	<div class="modal__body <?php echo $uiShape; ?>"></div>
</div>

<div id="gallery" class="gallery rotarygroup">
	<div class="gallery__inner">
		<div class="gallery__header">
			<div class="hmu-gallery-heading"><h1>Thư viện ảnh</h1><p>Những khoảnh khắc đáng nhớ của bạn.</p></div>
			<a href="#" aria-label="Đóng thư viện" title="Đóng thư viện" class="<?php echo $btnClass; ?> gallery__close close_gal rotaryfocus"><i class="<?php echo $config['icons']['close']; ?>" aria-hidden="true"></i></a>
		</div>
        <?php if ($config['gallery']['allow_delete']): ?>
        <div class="hmu-gallery-actions" role="group" aria-label="Chọn và xóa ảnh">
            <button type="button" id="hmu-gallery-select" class="rotaryfocus">Chọn ảnh</button>
            <button type="button" id="hmu-gallery-all" class="rotaryfocus" hidden>Chọn tất cả</button>
            <button type="button" id="hmu-gallery-clear" class="rotaryfocus" hidden>Bỏ chọn</button>
            <button type="button" id="hmu-gallery-delete" class="rotaryfocus" hidden disabled>Xóa ảnh đã chọn (0)</button>
            <button type="button" id="hmu-gallery-cancel" class="rotaryfocus" hidden>Hủy</button>
            <p id="hmu-gallery-status" role="status" aria-live="polite"></p>
        </div>
        <?php endif; ?>
		<div class="gallery__body" id="galimages">
			<?php if (empty($imagelist)): ?>
			<div class="hmu-gallery-empty"><span class="hmu-gallery-empty-icon" aria-hidden="true"><i class="fa fa-camera"></i></span><h2>Chưa có khoảnh khắc nào được lưu</h2><p>Hãy bắt đầu buổi chụp đầu tiên của bạn!</p><?php if ($config['picture']['enabled'] && !$config['button']['force_buzzer']): ?><a href="#" class="hmu-button hmu-button-primary takePic"><i class="<?php echo $config['icons']['take_picture']; ?>" aria-hidden="true"></i> Chụp ảnh ngay</a><?php endif; ?></div>
			<?php else: ?>
			<?php foreach ($imagelist as $image): ?>
			<?php
            $date = $config['ui']['branding'] . ' Gallery';
            if ($config['picture']['naming'] === 'dateformatted' && $config['gallery']['show_date']) {
                if ($config['database']['file'] != 'db') {
                    $db = strlen($config['database']['file']);
                    $name = substr($image, ++$db);
                } else {
                    $name = $image;
                }
                $dateObject = DateTime::createFromFormat('Ymd_His', substr($name, 0, 15));
                if ($dateObject) {
                    $date = '<i class="' . $config['icons']['date'] . '"></i> ' . $dateObject->format($config['gallery']['date_format']);
                }
            }

            if (!is_string($image) || basename($image) !== $image || strpos($image, '\\') !== false) continue;
            $filename_photo = $config['foldersAbs']['images'] . DIRECTORY_SEPARATOR . $image;
            if (is_readable($filename_photo)) {
                $filename_thumb = $config['foldersAbs']['thumbs'] . DIRECTORY_SEPARATOR . $image;

                if (!is_readable($filename_thumb)) {
                    $filename_thumb = $filename_photo;
                }
                $imageinfo = @getimagesize($filename_photo);
                $imageinfoThumb = @getimagesize($filename_thumb);
                if (!$imageinfo || !$imageinfoThumb) continue;
                $photoUrl = htmlspecialchars($config['foldersJS']['images'] . '/' . rawurlencode($image), ENT_QUOTES, 'UTF-8');
                $thumbUrl = htmlspecialchars($config['foldersJS'][$filename_thumb === $filename_photo ? 'images' : 'thumbs'] . '/' . rawurlencode($image), ENT_QUOTES, 'UTF-8');
            ?>

			<a href="<?=$photoUrl?>" class="gallery__img rotaryfocus" data-size="<?=$imageinfo[0]?>x<?=$imageinfo[1]?>"
				data-med="<?=$thumbUrl?>" data-med-size="<?=$imageinfoThumb[0]?>x<?=$imageinfoThumb[1]?>">
				<figure class="<?php echo $uiShape; ?>">
					<img class="<?php echo $uiShape; ?>" src="<?=$thumbUrl?>" alt="<?=htmlspecialchars($image, ENT_QUOTES, 'UTF-8')?>" loading="lazy" />
                    <?php if ($config['gallery']['figcaption']): ?>
                    <figcaption class="<?php echo $uiShape; ?>"><?=$date?></figcaption>
                    <?php endif; ?>
				</figure>
			</a>
	<?php } ?>
				<?php endforeach; ?>
				<?php endif; ?>
		</div>
        <?php if($GALLERY_FOOTER === true && $config['gallery']['action_footer'] === true): ?>
        <div class="gallery__footer">
            <div class="buttongroup">
                <?php if ($config['button']['force_buzzer']): ?>
                    <div id="useBuzzer">
                        <span data-i18n="use_button"></span>
                    </div>
                <?php else: ?>
                    <?php if ($config['picture']['enabled']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> gal-action-btn takePic rotaryfocus"><i class="<?php echo $config['icons']['take_picture']; ?>"></i> <span data-i18n="takePhoto"></span></a>
                    <?php endif; ?>

                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
	</div>
</div>
