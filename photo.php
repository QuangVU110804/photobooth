<?php
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/photo-receipt.php';

header('Cache-Control: private, no-store');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow, noimageindex');
header('X-Content-Type-Options: nosniff');

$filename = photoReceiptFilename($_GET['image'] ?? null);
$photo = $filename !== null ? findPhotoReceiptImage($config['foldersAbs']['images'], $filename) : null;
$problem = null;
if ($filename === null) {
    http_response_code(400);
    $problem = 'Liên kết chưa có thông tin ảnh hợp lệ. Hãy quét lại mã QR trên màn hình Photobooth.';
} elseif ($photo === null) {
    http_response_code(404);
    $problem = 'Ảnh này không còn khả dụng hoặc chưa được lưu xong. Hãy thử quét lại mã QR của bạn.';
}

if ($photo !== null && ($_GET['download'] ?? null) === '1') {
    if (!$config['download']['enabled']) {
        http_response_code(403);
        $problem = 'Tính năng tải ảnh hiện đang được tắt. Bạn vẫn có thể xem ảnh ở liên kết nhận ảnh.';
    } else {
        $asciiName = preg_replace('/[^a-zA-Z0-9._-]/', '-', $filename);
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="photobooth-' . $asciiName . '"; filename*=UTF-8\'\'' . rawurlencode('photobooth-' . $filename));
        header('Content-Length: ' . $photo['bytes']);
        readfile($photo['path']);
        exit;
    }
}

function receiptEscape($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function receiptIcon(string $name): string {
    $paths = [
        'camera' => '<path d="M14 4h-4L8 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-4z"/><circle cx="12" cy="13" r="4"/>',
        'arrow' => '<path d="m12 5-7 7 7 7M5 12h14"/>',
        'download' => '<path d="M12 3v12m-5-5 5 5 5-5M5 15v5h14v-5"/>',
        'cloud' => '<path d="M7 18H6a4 4 0 0 1-1-7.9A7 7 0 0 1 18.7 9a4.5 4.5 0 0 1-.7 9h-1M12 9v11m-4-4 4 4 4-4"/>',
        'share' => '<circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.5 6.8-4m-6.8 7 6.8 4"/>',
        'zoom' => '<circle cx="10" cy="10" r="7"/><path d="m15 15 6 6M7 10h6m-3-3v6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'info' => '<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>',
        'close' => '<path d="m6 6 12 12M6 18 18 6"/>',
        'chevron' => '<path d="m9 5 7 7-7 7"/>',
        'image' => '<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m21 15-6-6L3 21"/>',
        'phone' => '<rect x="6" y="2" width="12" height="20" rx="3"/><path d="M11 18h2"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['image']) . '</svg>';
}

if ($photo !== null) {
    $imageUrl = str_replace('\\', '/', $config['foldersRoot']['images']) . '/' . rawurlencode($filename);
    $downloadUrl = 'photo.php?image=' . rawurlencode($filename) . '&download=1';
    $fileSize = $photo['bytes'] >= 1048576 ? number_format($photo['bytes'] / 1048576, 1, ',', '.') . ' MB' : number_format($photo['bytes'] / 1024, 0, ',', '.') . ' KB';
    $savedAt = (new DateTimeImmutable('@' . $photo['modified']))->setTimezone(new DateTimeZone('Asia/Bangkok'));
    $format = ['image/jpeg' => 'JPG', 'image/png' => 'PNG', 'image/webp' => 'WebP'][$photo['mime']];
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#074780">
    <meta name="robots" content="noindex, nofollow, noimageindex">
    <title><?= $problem === null ? 'Ảnh của bạn' : 'Chưa nhận được ảnh' ?> · HMU Photobooth</title>
    <link rel="icon" href="resources/img/favicon-32x32.png" type="image/png">
    <link rel="stylesheet" href="resources/css/photo-receipt.css?v=2">
    <script src="resources/js/photo-receipt.js?v=1" defer></script>
</head>
<body class="receipt-page">
    <a class="receipt-skip" href="#receipt-main">Đến nội dung chính</a>
    <header class="receipt-header">
        <div class="receipt-header-inner">
            <a class="receipt-brand" href="index.php" aria-label="HMU Photobooth — trang chủ">
                <img class="receipt-brand-logo" src="resources/img/background/logohome.png" width="38" height="38" alt="" />
                <span><strong>HMU <span class="receipt-brand-long">Photobooth</span></strong><small>Trải nghiệm bắt trọn khoảnh khắc</small></span>
            </a>
            <nav aria-label="Điều hướng trang nhận ảnh"><a href="<?= $problem === null ? '#save-help' : 'index.php' ?>"><?= $problem === null ? 'Hướng dẫn lưu ảnh' : 'Trang chủ' ?> <?= receiptIcon('chevron') ?></a></nav>
        </div>
    </header>
    <main class="receipt-main" id="receipt-main">
        <?php if ($problem !== null): ?>
        <section class="receipt-card receipt-error" aria-labelledby="error-title">
            <span class="receipt-circle"><?= receiptIcon('image') ?></span>
            <p class="receipt-eyebrow">HMU PHOTOBOOTH</p>
            <h1 id="error-title">Chưa nhận được ảnh</h1>
            <p><?= receiptEscape($problem) ?></p>
            <a class="receipt-button receipt-button-primary" href="index.php"><?= receiptIcon('arrow') ?> Về HMU Photobooth</a>
        </section>
        <?php else: ?>
        <div class="receipt-context">
            <a class="receipt-back" href="index.php"><?= receiptIcon('arrow') ?> <span>HMU Photobooth</span></a>
            <span class="receipt-ready"><?= receiptIcon('check') ?> Ảnh đã sẵn sàng</span>
            <span class="receipt-saved">Đã lưu lúc <?= receiptEscape($savedAt->format('H:i · d/m/Y')) ?> (GMT+7)</span>
        </div>
        <div class="receipt-layout">
            <section class="receipt-preview" aria-label="Ảnh của bạn">
                <div class="receipt-photo">
                    <span class="receipt-photo-badge"><?= receiptIcon('image') ?> BẢN ẢNH GỐC TỪ PHOTOBOOTH</span>
                    <div class="receipt-photo-label"><span>HANOI MEDICAL UNIVERSITY</span><?= receiptIcon('camera') ?></div>
                    <a id="photo-preview-link" class="receipt-photo-link" href="<?= receiptEscape($imageUrl) ?>" target="_blank" rel="noopener" aria-label="Xem ảnh đầy đủ">
                        <img id="receipt-image" src="<?= receiptEscape($imageUrl) ?>" width="<?= $photo['width'] ?>" height="<?= $photo['height'] ?>" alt="Ảnh của bạn từ HMU Photobooth" fetchpriority="high">
                    </a>
                    <div class="receipt-photo-caption"><span><strong>HMU Photobooth</strong><small>Kỷ niệm thời hoa niên</small></span><span class="receipt-photo-date"><?= receiptEscape($savedAt->format('d.m.Y')) ?></span></div>
                </div>
                <div class="receipt-preview-actions"><a id="view-photo" class="receipt-pill" href="<?= receiptEscape($imageUrl) ?>" target="_blank" rel="noopener"><?= receiptIcon('zoom') ?> Xem ảnh phóng to</a><span class="receipt-original"><?= receiptIcon('check') ?> Giữ nguyên chất lượng</span></div>
            </section>
            <div class="receipt-panels">
                <section class="receipt-card receipt-download" aria-labelledby="receipt-title">
                    <span class="receipt-circle"><?= receiptIcon('cloud') ?></span>
                    <p class="receipt-eyebrow">MỘT KHOẢNH KHẮC. MỘT KỶ NIỆM.</p>
                    <h1 id="receipt-title">Khoảnh khắc của bạn<br>đã sẵn sàng!</h1>
                    <p class="receipt-intro">Mang kỷ niệm này về cùng bạn. Lưu ảnh đầy đủ từ Photobooth và chia sẻ với những người bạn yêu quý.</p>
                    <?php if ($config['download']['enabled']): ?>
                    <a id="download-photo" class="receipt-button receipt-button-primary" href="<?= receiptEscape($downloadUrl) ?>" download="<?= receiptEscape('photobooth-' . $filename) ?>"><?= receiptIcon('download') ?> Tải ảnh về máy <span>(<?= receiptEscape($format) ?> gốc)</span></a>
                    <p class="receipt-download-meta"><?= receiptEscape($fileSize) ?> <span aria-hidden="true">·</span> <?= $photo['width'] ?> × <?= $photo['height'] ?> px</p>
                    <?php else: ?>
                    <p class="receipt-notice">Tính năng tải ảnh đang được tắt trên Photobooth này.</p>
                    <?php endif; ?>
                    <button class="receipt-button receipt-button-secondary" id="share-photo" type="button" hidden><?= receiptIcon('share') ?> Chia sẻ khoảnh khắc</button>
                    <p id="receipt-status" class="receipt-status" role="status" aria-live="polite"></p>
                    <div class="receipt-note"><?= receiptIcon('info') ?><div><strong>Lưu giữ kỷ niệm của bạn</strong><p>Hãy tải ảnh về thiết bị để có thể xem lại bất cứ lúc nào. Giữ liên kết riêng nếu bạn chưa muốn chia sẻ ảnh.</p></div></div>
                </section>
                <section class="receipt-card receipt-details" aria-labelledby="details-title">
                    <div class="receipt-section-title"><h2 id="details-title"><?= receiptIcon('image') ?> Thông tin ảnh</h2><span class="receipt-format"><?= receiptEscape($format) ?> GỐC</span></div>
                    <dl class="receipt-specs">
                        <div><dt>Độ phân giải</dt><dd><?= $photo['width'] ?> × <?= $photo['height'] ?> px</dd></div>
                        <div><dt>Dung lượng</dt><dd><?= receiptEscape($fileSize) ?></dd></div>
                        <div><dt>Định dạng</dt><dd><?= receiptEscape($format) ?></dd></div>
                        <div><dt>Tên tệp</dt><dd class="receipt-filename"><?= receiptEscape($filename) ?></dd></div>
                    </dl>
                    <p class="receipt-detail-note"><?= receiptIcon('check') ?> Ảnh tải xuống giữ nguyên kích thước và nội dung tệp đã lưu.</p>
                </section>
                <section class="receipt-card receipt-help" id="save-help" aria-labelledby="help-title">
                    <h2 id="help-title"><?= receiptIcon('phone') ?> Lưu ảnh trên điện thoại</h2>
                    <p>Chỉ vài thao tác để mang kỷ niệm về máy.</p>
                    <details><summary>Trên iPhone / iPad <?= receiptIcon('chevron') ?></summary><p>Chọn “Tải ảnh về máy”. Mở ảnh trong ứng dụng Tệp / Files, nhấn Chia sẻ rồi chọn “Lưu hình ảnh” để đưa ảnh vào thư viện Ảnh.</p></details>
                    <details><summary>Trên Android <?= receiptIcon('chevron') ?></summary><p>Chọn “Tải ảnh về máy”. Ảnh sẽ nằm trong thư mục Tải xuống / Downloads; bạn có thể mở từ thông báo tải xuống hoặc ứng dụng quản lý tệp.</p></details>
                </section>
            </div>
        </div>
        <dialog id="photo-dialog" class="receipt-lightbox" aria-label="Ảnh phóng to">
            <form method="dialog"><button class="receipt-lightbox-close" aria-label="Đóng ảnh phóng to" autofocus><?= receiptIcon('close') ?></button></form>
            <img src="<?= receiptEscape($imageUrl) ?>" alt="Ảnh đầy đủ từ HMU Photobooth" loading="lazy">
        </dialog>
        <?php endif; ?>
    </main>
    <footer class="receipt-footer"><div><strong>HMU</strong><span>Photobooth · Kỷ niệm thời hoa niên</span></div><p>Một lần chụp, nhiều lần nhớ.</p></footer>
</body>
</html>
