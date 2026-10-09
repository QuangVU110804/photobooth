<?php
session_start();

require_once 'lib/config.php';
if (!$config['ui']['skip_welcome']) {
    if (!is_file('.skip_welcome')) {
        header('location: welcome');
        exit();
    }
}

if ($config['live_keying']['enabled']) {
    header('location: livechroma.php');
    exit();
}

// Login / Authentication check
if (
    !$config['login']['enabled'] ||
    (!$config['protect']['localhost_index'] && $_SERVER['REMOTE_ADDR'] === $_SERVER['SERVER_ADDR']) ||
    ((isset($_SESSION['auth']) && $_SESSION['auth'] === true) || !$config['protect']['index'])
) {
    require_once 'lib/db.php';
    require_once 'lib/filter.php';

    if ($config['database']['enabled']) {
        $images = getImagesFromDB();
    } else {
        $images = getImagesFromDirectory($config['foldersAbs']['images']);
    }

    $imagelist = $config['gallery']['newest_first'] === true && !empty($images) ? array_reverse($images) : $images;

    $btnClass = 'btn btn--' . $config['ui']['button'];
    $btnShape = 'shape--' . $config['ui']['button'];
    $uiShape = 'shape--' . $config['ui']['style'];
    $GALLERY_FOOTER = true;
} else {
    header('location: ' . $config['protect']['index_redirect']);
    exit();
}
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0 user-scalable=no">
    <meta name="msapplication-TileColor" content="<?= $config['colors']['primary'] ?>">
    <meta name="theme-color" content="<?= $config['colors']['primary'] ?>">

    <title><?= $config['ui']['branding'] ?></title>

    <!-- Favicon + Android/iPhone Icons -->
    <link rel="apple-touch-icon" sizes="180x180" href="resources/img/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="resources/img/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="resources/img/favicon-16x16.png">
    <link rel="manifest" href="resources/img/site.webmanifest">
    <link rel="mask-icon" href="resources/img/safari-pinned-tab.svg" color="#5bbad5">

    <!-- Fullscreen Mode on old iOS-Devices when starting photobooth from homescreen -->
    <meta name="apple-mobile-web-app-capable" content="yes"/>
    <meta name="apple-mobile-web-app-status-bar-style" content="black"/>

    <link rel="stylesheet" href="node_modules/normalize.css/normalize.css"/>
    <link rel="stylesheet" href="node_modules/font-awesome/css/font-awesome.css"/>
    <link rel="stylesheet" href="node_modules/material-icons/iconfont/material-icons.css">
    <link rel="stylesheet" href="node_modules/material-icons/css/material-icons.css">
    <link rel="stylesheet" href="vendor/PhotoSwipe/dist/photoswipe.css"/>
    <link rel="stylesheet" href="vendor/PhotoSwipe/dist/default-skin/default-skin.css"/>
    <link rel="stylesheet" href="resources/css/<?php echo $config['ui']['style']; ?>_style.css?v=<?php echo $config['photobooth']['version']; ?>"/>
    <?php if ($config['gallery']['bottom_bar']): ?>
        <link rel="stylesheet" href="resources/css/photoswipe-bottom.css?v=<?php echo $config['photobooth']['version']; ?>"/>
    <?php endif; ?>
    <?php if (is_file("private/overrides.css")): ?>
        <link rel="stylesheet" href="private/overrides.css?v=<?php echo $config['photobooth']['version']; ?>"/>
    <?php endif; ?>










<!-- KIOSK CLEAN STYLING & NATIVE FRAME CONTROLLER -->
<style>
  /* 1. CHỈ ÁP DỤNG HÌNH NỀN & LOGO CHO DUY NHẤT MÀN HÌNH CHỜ (START SCREEN) */
  #wrapper.start, body.start-screen, .stages.start-screen {
    background-color: #fbf9f5 !important;
    background-image: 
      url('resources/img/background/logohome.png'),
      radial-gradient(circle at 10% 88%, rgba(0, 85, 165, 0.22) 0%, transparent 45%),
      radial-gradient(circle at 12% 12%, rgba(184, 15, 72, 0.20) 0%, transparent 40%),
      radial-gradient(circle at 50% 35%, #ffffff 0%, #f6f3eb 65%, #eee9de 100%) !important;
    background-position: center 24%, center, center, center !important;
    background-size: min(420px, 38vw) auto, cover, cover, cover !important;
    background-repeat: no-repeat !important;
  }

  /* 2. TRẢ LẠI NỀN TRONG SUỐT CHO TOÀN BỘ CÁC LỚP LIVE PREVIEW & COUNTDOWN */
  #video--view, .video--view, #video, video, .stages, #stage, #preview, .countdown {
    background-color: transparent !important;
  }

  /* 3. THANH CHỌN FRAME GỌN GÀNG Ở CẠNH PHẢI */
  .kiosk-frame-bar {
    position: fixed;
    right: 20px;
    top: 50%;
    transform: translateY(-50%);
    z-index: 999999;
    display: flex;
    flex-direction: column;
    gap: 14px;
    background: rgba(0, 0, 0, 0.6);
    padding: 10px;
    border-radius: 14px;
    backdrop-filter: blur(6px);
    border: 2px solid rgba(255, 255, 255, 0.3);
  }
  .kiosk-frame-btn {
    width: 80px;
    height: 50px;
    border-radius: 8px;
    border: 2px solid #ffffff;
    cursor: pointer;
    overflow: hidden;
    background: #111;
    transition: transform 0.2s, border-color 0.2s;
  }
  .kiosk-frame-btn.active {
    border: 3px solid #ffcc00 !important;
    box-shadow: 0 0 14px #ffcc00;
    transform: scale(1.1);
  }
  .kiosk-frame-btn img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    pointer-events: none;
    display: block;
  }
</style>

<div id="kioskFrameSelector" class="kiosk-frame-bar"></div>

<script>
  function switchKioskFrame(frameFileName) {
    const bust = Date.now();
    const frameUrl = "resources/img/frames/" + frameFileName + "?v=" + bust;
    const absUrl = "/photobooth/resources/img/frames/" + frameFileName + "?v=" + bust;

    // Cập nhật biến CSS gốc của Photobooth
    document.documentElement.style.setProperty("--picture-frame", 'url("' + absUrl + '")');
    document.documentElement.style.setProperty("--collage-frame", 'url("' + absUrl + '")');

    // Cập nhật các thẻ khung mặc định của hệ thống Photobooth
    document.querySelectorAll("#frame, .frameImage, #preview-frame, .live-preview-frame, img[src*='frames/']").forEach(el => {
      if (el.tagName.toLowerCase() === "img") {
        el.src = frameUrl;
      } else {
        el.style.backgroundImage = 'url("' + frameUrl + '")';
      }
    });

    // Gửi backend swap file phục vụ việc lưu ảnh chụp
    const form = new FormData();
    form.append("frame", frameFileName);
    fetch("api/setFrame.php", { method: "POST", body: form });
  }

  document.addEventListener("DOMContentLoaded", function() {
    fetch("api/getFrames.php")
      .then(r => r.json())
      .then(frames => {
        const bar = document.getElementById("kioskFrameSelector");
        if (!bar || !frames.length) return;
        bar.innerHTML = "";

        frames.forEach((f, idx) => {
          const btn = document.createElement("div");
          btn.className = "kiosk-frame-btn" + (idx === 0 ? " active" : "");
          btn.title = f;
          btn.innerHTML = `<img src="resources/img/frames/${f}" alt="${f}">`;

          btn.onclick = function(e) {
            e.stopPropagation();
            document.querySelectorAll(".kiosk-frame-btn").forEach(b => b.classList.remove("active"));
            btn.classList.add("active");
            switchKioskFrame(f);
          };
          bar.appendChild(btn);
        });

        if (frames.length > 0) {
          switchKioskFrame(frames[0]);
        }
      });
  });
</script>
<!-- END KIOSK CLEAN STYLING & NATIVE FRAME CONTROLLER -->
</head>

<body class="deselect">

<?php if ($config['preview']['showFrame'] && !empty($config['picture']['htmlframe'])): ?>
<img id="picture--frame" class="<?php echo $config['preview']['flip']; ?> <?php echo $config['preview']['style']; ?>" src="<?php echo $config['picture']['htmlframe']; ?>" alt="pictureFrame" />
<?php endif; ?>
<?php if ($config['preview']['showFrame'] && !empty($config['collage']['htmlframe'])): ?>
<img id="collage--frame" class="<?php echo $config['preview']['flip']; ?> <?php echo $config['preview']['style']; ?>" src="<?php echo $config['collage']['htmlframe']; ?>" alt="collageFrame" />
<?php endif; ?>

<video id="video--view" class="<?php echo $config['preview']['flip']; ?> <?php echo $config['preview']['style']; ?>"
       autoplay playsinline></video>
<div id="blocker"></div>
<div id="aperture"></div>
<div id="wrapper">
    <?php include('template/' . $config['ui']['style'] . '.template.php'); ?>

    <!-- image Filter Pane -->
    <?php if ($config['filters']['enabled']): ?>
        <div id="mySidenav" class="dragscroll sidenav rotarygroup">
            <a href="#" class="<?php echo $btnClass; ?> closebtn rotaryfocus"><i class="<?php echo $config['icons']['close']; ?>"></i></a>

            <?php foreach (AVAILABLE_FILTERS as $filter => $name): ?>
                <?php if (!in_array($filter, $config['filters']['disabled'])): ?>
                    <div id="<?= $filter ?>"
                         class="filter <?php if ($config['filters']['defaults'] === $filter) echo 'activeSidenavBtn'; ?>">
                        <a class="btn btn--small rotaryfocus" href="#"><?= $name ?></a>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Loader -->
    <div class="stages" id="loader">
        <div class="loaderInner">
            <div class="spinner">
                <i class="<?php echo $config['icons']['spinner']; ?>"></i>
            </div>

            <div id="ipcam--view" class="<?php echo $config['preview']['style']; ?>"></div>

            <div id="counter">
                <canvas id="video--sensor"></canvas>
            </div>
            <div class="cheese"></div>
            <div class="loaderImage"></div>
            <div class="loading rotarygroup"></div>
        </div>
    </div>

    <!-- Result Page -->
    <div class="stages rotarygroup" id="result">

        <div class="resultInner hidden">
            <?php if ($config['button']['homescreen']): ?>
                <a href="#" class="<?php echo $btnClass; ?> homebtn rotaryfocus"><i class="<?php echo $config['icons']['home']; ?>"></i> <span
                            data-i18n="home"></span></a>
            <?php endif; ?>

            <?php if ($config['ui']['result_buttons']): ?>
                <?php if ($config['gallery']['enabled']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> gallerybtn rotaryfocus"><i class="<?php echo $config['icons']['gallery']; ?>"></i> <span data-i18n="gallery"></span></a>
                <?php endif; ?>

                <?php if ($config['qr']['enabled']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> qrbtn rotaryfocus"><i class="<?php echo $config['icons']['qr']; ?>"></i> <span
                                data-i18n="qr"></span></a>
                <?php endif; ?>

                <?php if ($config['mail']['enabled']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> mailbtn rotaryfocus"><i class="<?php echo $config['icons']['mail']; ?>"></i> <span
                                data-i18n="mail"></span></a>
                <?php endif; ?>

                <?php if ($config['print']['from_result']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> printbtn rotaryfocus"><i class="<?php echo $config['icons']['print']; ?>"></i> <span
                                data-i18n="print"></span></a>
                <?php endif; ?>

                <?php if (!$config['button']['force_buzzer']): ?>
                    <?php if ($config['picture']['enabled']): ?>
                        <a href="#" class="<?php echo $btnClass; ?> newpic rotaryfocus"><i class="<?php echo $config['icons']['take_picture']; ?>"></i> <span
                                    data-i18n="newPhoto"></span></a>
                    <?php endif; ?>

                    <?php if ($config['collage']['enabled']): ?>
                        <a href="#" class="<?php echo $btnClass; ?> newcollage rotaryfocus"><i class="<?php echo $config['icons']['take_collage']; ?>"></i>
                            <span
                                    data-i18n="newCollage"></span></a>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($config['filters']['enabled']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> imageFilter rotaryfocus"><i class="<?php echo $config['icons']['filter']; ?>"></i> <span
                                data-i18n="selectFilter"></span></a>
                <?php endif; ?>

                <?php if ($config['picture']['allow_delete']): ?>
                    <a href="#" class="<?php echo $btnClass; ?> deletebtn <?php if ($config['delete']['no_request']) {
                        echo 'rotaryfocus';
                    } ?> "><i class="<?php echo $config['icons']['delete']; ?>"></i> <span data-i18n="delete"></span></a>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php if ($config['qr']['enabled']): ?>
            <div id="qrCode" class="modal">
                <div class="modal__body <?php echo $uiShape; ?>"></div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($config['gallery']['enabled']): ?>
        <?php include('template/gallery.template.php'); ?>
    <?php endif; ?>
</div>

<?php include('template/pswp.template.php'); ?>

<?php include('template/send-mail.template.php'); ?>

<div class="modal" id="print_mesg">
    <div class="modal__body"><span data-i18n="printing"></span></div>
</div>

<div class="modal" id="modal_mesg"></div>

<div id="adminsettings">
    <div style="position:absolute; bottom:0; right:0;">
        <img src="resources/img/spacer.png" alt="adminsettings" ondblclick="adminsettings()"/>
    </div>
</div>

<script src="node_modules/whatwg-fetch/dist/fetch.umd.js"></script>
<script type="text/javascript" src="api/config.php?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/adminshortcut.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="node_modules/jquery/dist/jquery.min.js"></script>
<script type="text/javascript" src="vendor/PhotoSwipe/dist/photoswipe.min.js"></script>
<script type="text/javascript" src="vendor/PhotoSwipe/dist/photoswipe-ui-default.min.js"></script>
<script type="text/javascript" src="resources/js/tools.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/remotebuzzer_client.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/photoinit.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/theme.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/preview.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script type="text/javascript" src="resources/js/core.js?v=<?php echo $config['photobooth']['version']; ?>"></script>
<script src="node_modules/@andreasremdt/simple-translator/dist/umd/translator.min.js"></script>
<script type="text/javascript" src="resources/js/i18n.js?v=<?php echo $config['photobooth']['version']; ?>"></script>

<?php require_once('lib/services_start.php'); ?>
</body>
</html>
