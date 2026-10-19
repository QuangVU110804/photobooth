# Đặc tả và checklist: sticker PNG bám khuôn mặt

Trạng thái: đã triển khai và kiểm chứng; npm test và browser suites đều exit 0.
Ngày: 2026-10-16 (Asia/Bangkok).

## 1. Mục tiêu đã thống nhất

Người dùng chọn hướng 2: tự thêm hiệu ứng bằng ảnh PNG trong suốt và cấu hình,
hiển thị trên camera và xuất đúng vào JPG. Ưu tiên hiệu năng và không can thiệp
logic/file ngoài phạm vi. Sau triển khai phải chạy đầy đủ bộ kiểm thử hiện có
cùng testcase bổ sung cho sticker, không kết luận chỉ từ kiểm tra cú pháp.

Đây là thay đổi kiến trúc nhỏ trong luồng hiệu ứng có sẵn: thêm danh mục tài
nguyên và kiểu phần tử PNG vào hợp đồng faceEffects. Dùng lại MediaPipe,
Worker, Canvas 2D, PHP/GD, thứ tự xử lý ảnh và luồng chụp hiện tại.

Phạm vi phiên bản đầu:

- Một lựa chọn hiệu ứng mặt tại một thời điểm, áp dụng cho tối đa 4 mặt như cũ.
- Một lựa chọn có thể chứa nhiều thành phần PNG, ví dụ hai má hoặc hai tai.
- Giữ nguyên lựa chọn Không hiệu ứng, Kính thời trang và Má hồng hiện tại.
- Hỗ trợ đặt sticker theo vùng mắt, má, mũi, miệng, trán, cằm và tâm mặt.
- Thêm mẫu bằng PNG và JSON; có hướng dẫn và mẫu cấu hình dùng được.
- Chỉ PNG tĩnh; chưa thêm upload/admin editor, 3D, animation hay trigger biểu cảm.
- Không chuyển hệ thống sang p5.js, không thêm npm dependency hay dịch vụ ngoài.

## 2. Ranh giới thay đổi và bảo toàn công việc hiện có

Workspace đã có 27 file tracked thay đổi và các file untracked từ trước.
Chúng là dữ liệu đầu vào của công việc, không được reset, checkout đè,
format toàn dự án hoặc gom vào commit của tính năng.

Chỉ sửa các đoạn liên quan trong file nằm ở checklist bên dưới. Nếu phát hiện
cần mở rộng phạm vi, ghi rõ lý do và cập nhật checklist trước khi sửa.
Các lỗi tồn tại trước không thuộc sticker phải được báo riêng, không tự sửa.

README trong resources/js còn nói đến src/js và gulp; checkout hiện tại không
có src/ hay gulpfile.js, package.json chạy test khi build. Các file JS runtime
đang được index.php nạp trực tiếp là điểm tích hợp thực tế của đặc tả này.

## 3. Danh mục và tài nguyên

Tạo resources/face-effects/catalog.json với schema có version. Đây là nguồn
cấu hình chung cho trình duyệt và PHP. Giữ danh sách legacy trong code hiện
tại; danh mục mới chỉ bổ sung hiệu ứng PNG, không ghi đè ID legacy.

Mỗi effect có ID duy nhất, tên hiển thị và danh sách tối đa 4 thành phần.
Mỗi thành phần xác định PNG, preset điểm neo, điểm gốc trong PNG, độ lệch
theo hệ trục mặt, độ rộng theo khoảng cách mắt, opacity và thứ tự vẽ.
Tỷ lệ ảnh được giữ nguyên. Các preset có thể dùng trung điểm của nhiều
landmark; offset và góc quay cùng theo đường nối hai điểm mắt.

Ảnh nằm trong resources/face-effects/stickers/. Phiên bản đầu giới hạn:
32 hiệu ứng, 4 thành phần/hiệu ứng, PNG tối đa 1024x1024 và 2 MiB/file.
ID chỉ gồm chữ thường ASCII, số, dấu gạch ngang/gạch dưới, dài tối đa 64.
Đường dẫn được resolve trong thư mục sticker; cấm URL, stream, đường dẫn
tuyệt đối, traversal và symlink thoát khỏi thư mục. PHP kiểm tra MIME/kích
thước PNG trước khi decode. Mẫu có vấn đề không được hiển thị là khả dụng;
request cố dùng ID/tài nguyên không hợp lệ bị từ chối trước khi sửa ảnh.

Trình duyệt nạp danh mục khi mở khu vực hiệu ứng lần đầu, không khi xem
gallery/classic. Cache danh mục trong phiên và tài nguyên đang dùng; không
nạp tất cả PNG từ đầu. Không có request mạng trong vòng lặp nhận diện/vẽ.

## 4. Dòng dữ liệu và tương thích

Frontend đọc catalog, kiểm tra schema, thêm nút vào rail hiệu ứng hiện có.
Khi chọn PNG, nạp/decode các ảnh của lựa chọn đó một lần rồi mới cho chụp.
Thao tác đổi lựa chọn/hủy dùng token để kết quả tải muộn không ghi đè lựa
chọn mới. Lỗi catalog/PNG không làm mất các hiệu ứng legacy hoặc filter màu.

Module sticker mới chịu trách nhiệm kiểm tra cấu hình, tính vị trí và vẽ.
Worker giữ bốn anchor legacy ở đầu kết quả; chỉ bổ sung landmark cần cho
PNG đang chọn qua danh sách index có giới hạn, không truyền toàn bộ mesh.
Mỗi lượt request ghi nhận effect/landmark mapping và revision của lựa chọn;
bỏ kết quả cũ nếu người dùng đổi effect khi inference còn chạy.

Khi chụp, tiếp tục detect lại ảnh raw như hiện tại; không dùng landmark
preview để xuất ảnh. Vẫn gửi map filename -> danh sách phần tử qua
faceEffects. Phần tử polygon cũ giữ nguyên cấu trúc points/color.

Phần tử PNG mới được phân biệt rõ bằng type, có effect ID, component index,
tâm ảnh chuẩn hóa, độ rộng chuẩn hóa theo chiều rộng ảnh và góc quay radian.
Opacity, file ảnh, tỷ lệ và thông số tài nguyên lấy từ catalog phía server;
không nhận đường dẫn hoặc bitmap/base64 tùy ý từ request. Bộ kiểm tra dùng
giới hạn JSON 64 KiB, tối đa 32 phần tử/ảnh hiện có; số phần tử PNG tối đa
16 tương ứng 4 mặt x 4 thành phần. Tọa độ phải hữu hạn trong [-1,2], độ rộng
trong (0,2], góc quay trong [-pi,pi]; kiểm tra thêm giới hạn bề mặt trước cấp phát.

PHP resolve/decode PNG, resize/rotate trên vùng sticker có giới hạn, giữ alpha
và composite vào ảnh raw. Tính vị trí sau xoay theo cùng tâm đã định nghĩa;
góc Canvas/GD phải được quy đổi và kiểm tra bằng fixture bất đối xứng.
Clip đúng khi sticker một phần ngoài ảnh, bỏ phần hoàn toàn ngoài ảnh.
Không cấp phát thêm một overlay bằng toàn bộ kích thước ảnh chụp.

applyFaceEffects() phân nhánh theo loại phần tử và gọi renderer PNG mới.
Giữ nguyên nhánh polygon, tên payload và đường đi qua core/API/
editSingleImage(); vì vậy không cần sửa các file đó trong thiết kế ban đầu.
Vẫn ghép hiệu ứng trước picture.flip, filter màu, polaroid, khung và rotation.
Sticker chịu filter màu như hai hiệu ứng mặt cũ; preview phải cùng quy tắc.

## 5. Hiệu năng và vòng đời

- Giữ một detector/Worker và tối đa một inference preview đang chạy.
- Giữ nhịp giới hạn khoảng 8 Hz, input preview tối đa rộng 480 px,
  input detect ảnh chụp tối đa rộng 960 px, overlay tối đa rộng 960 px.
- Không tăng numFaces, bật blendshape/3D hoặc inference riêng cho từng sticker.
- Tính sin/cos và tỷ lệ một lần mỗi mặt/lượt render, tái sử dụng cho các part.
- Chỉ giữ PNG đã decode của effect hiện tại và tối đa một effect gần nhất;
  giới hạn tổng cache 8 triệu pixel RGBA, giải phóng khi bị loại/pagehide.
- PHP decode mỗi asset một lần trong request, tái sử dụng cho nhiều mặt,
  giải phóng surface resize/rotate ngay sau ghép. Mỗi surface trung gian
  tối đa 4 triệu pixel; kiểm tra ngân sách cấp phát theo số pixel và
  memory_limit còn lại trước mỗi surface, trả lỗi có kiểm soát nếu vượt.
- Không tạo cache vô hạn theo mỗi vị trí/góc xoay; không ghi file mỗi frame.
- Dừng khi ẩn tab, hủy hoặc kết thúc phiên; lựa chọn phiên mới trở về none.
- Đo riêng inference và công đoạn vẽ/ghép PNG; báo median, p95 và peak memory
  trên cùng máy với ảnh 1280x720, 1800x1200 và 3840x2160, 1 và 4 mặt.
- Mục tiêu đường legacy/none không chậm hơn 10% median so với baseline cùng
  môi trường. Đây là mục tiêu đo để xem xét, không phải ngưỡng wall-clock CI
  dễ sai do tải hệ thống. Các giới hạn request, inference và cache là invariant
  có testcase bắt buộc; kết quả vượt mục tiêu phải được giải thích và xử lý.

## 6. Checklist file dự kiến

### File runtime hiện có: chỉ 4 file

- [x] resources/js/filter-stage.js: nạp catalog/ảnh khi cần, bổ sung lựa chọn,
  gọi module PNG cho preview và raw-image payload; xử lý race/lifecycle.
- [x] resources/js/face-effects-worker.js: hỗ trợ yêu cầu một tập landmark có
  giới hạn, giữ cấu trúc anchor legacy và một detector hiện có.
- [x] lib/face-effects.php: thêm nhánh kiểm tra/render PNG, giữ polygon cũ.
- [x] index.php: nạp module sticker trước filter-stage; đổi version URL chỉ
  với script bị sửa để trình duyệt không giữ giao thức cũ.

### File mới

- [x] resources/js/face-stickers.js: schema client, asset cache, hình học,
  Canvas renderer và builder phần tử payload PNG; có thể test bằng Node.
- [x] lib/face-stickers.php: đọc/kiểm tra catalog, resolve asset, kiểm tra
  phần tử PNG và renderer GD dùng vùng ảnh giới hạn.
- [x] resources/face-effects/catalog.json: danh mục có version và mẫu PNG.
- [x] resources/face-effects/stickers/: tối thiểu một sticker PNG gốc dùng
  được; không sao chép tài nguyên không rõ quyền từ repo tham khảo.
- [x] resources/face-effects/README.md: cách tự thêm PNG/config, đơn vị,
  preset, giới hạn, cách hiệu chỉnh và kiểm tra ảnh xuất.
- [x] scripts/test-face-stickers.cjs: testcase schema, hình học và vòng đời cache.
- [x] scripts/test-face-stickers.php: testcase catalog, alpha, GD, bounds,
  payload và endpoint thật trong fixture tạm.

### File kiểm thử hiện có

- [x] scripts/test-filter-stage-browser.mjs: bổ sung thử chọn PNG, preview,
  countdown, ảnh xuất, đổi nhanh/hủy, nhiều phiên và lỗi tài nguyên.
- [x] scripts/run-project-tests.cjs: đăng ký hai bộ testcase mới.
- [x] scripts/test-filter-effects.php: chỉ cập nhật fixture copy nếu cần
  để endpoint trong thư mục tạm có helper/catalog/assets mới.
- [x] scripts/filter-test-router.php: đã xác định không cần sửa; dùng CDP inject lỗi để kiểm
  thử PNG; không sửa hành vi production hay giả kết quả nhận diện MediaPipe.

### File giữ nguyên theo thiết kế

api/takePic.php, api/applyEffects.php, resources/js/core.js,
resources/js/preview.js, resources/js/filter-session.js, lib/applyEffects.php,
lib/filter.php, lib/resize.php, lib/frames.php, resources/js/hmu-frames.js,
config/*, gallery/receipt/delete/print, CSS/template, vendor, package.json,
package-lock.json. Các file này vẫn được kiểm thử hồi quy đầy đủ.

## 7. Checklist testcase sau triển khai

- [x] Schema hợp lệ; ID trùng/legacy, part sai, preset sai, JSON lỗi/quá lớn,
  file thiếu/sai MIME/quá lớn, traversal/URL/symlink đều xử lý đúng.
- [x] Geometry fixture độc lập: tâm, tỷ lệ ảnh, offset theo trục mặt, góc 0,
  góc dương/âm, mặt gần/xa, ảnh ngang/dọc và một phần ngoài khung.
- [x] PNG alpha 0/50/100%, biên xoay không có nền đen; màu và tâm khớp Canvas/GD
  trong sai số raster/JPEG đã định nghĩa, dùng ảnh bất đối xứng để bắt lật góc.
- [x] Payload polygon cũ tiếp tục hợp lệ và pixel output giữ nguyên.
- [x] Payload PNG hợp lệ ghi đúng JPG/thumbnail; không ghép preview độ phân giải thấp.
- [x] Payload lỗi ở phần tử sau không gây ghi ảnh/file một phần.
- [x] 0, 1, 4 mặt; lựa chọn nhiều part; không mặt thì không có sticker.
- [x] PNG + plain/Inkwell/Reyes/Moon + khung + flip/rotation;
  keep_original bật/tắt; đường chroma legacy giữ hành vi.
- [x] Real MediaPipe + Chrome fake camera + PHP/GD thật đi hết luồng PNG.
- [x] Countdown giữ sticker; double click chỉ chụp một lần; phiên mới reset.
- [x] Đổi effect/camera/hủy khi load hoặc inference chậm không để lộ dữ liệu cũ.
- [x] Lỗi catalog/asset/worker không khóa chụp với lựa chọn none/legacy khả dụng.
- [x] Tab ẩn, back, pagehide giải phóng tài nguyên; cache/request/inference đúng giới hạn.
- [x] Kiểm tra hiệu năng theo mục 5, ghi rõ số đo và điều kiện chạy.
- [x] npm.cmd test: toàn bộ regression PHP/API/JS, resize, receipt, frames,
  scroll, session, gallery, kiosk và testcase sticker mới.
- [x] npm.cmd run test:browser: toàn bộ browser filter và photo receipt.
- [x] git diff --check; đối chiếu diff với checklist và baseline file người dùng.

Test thiết bị thật (độ rung khi người di chuyển, webcam thực và DSLR thực)
được ghi riêng; fake camera/hardware fixture không được báo là kiểm chứng
thiết bị thật. Nếu môi trường ngăn một bộ test chạy, báo rõ bộ nào và nguyên
nhân, không tuyên bố toàn dự án không xung đột từ các test chưa chạy.

## 8. Baseline và bước duyệt

- Đã chạy npm.cmd test trên source trước thay đổi: exit 0, quality gate 5/5;
  13 resize, 130 API, 164 filter/GD, 69 receipt, 7 deployment, 40 frame preview,
  14 capture session, 22 gallery polling, 10 kiosk zoom checks; scroll passed.
- Đã chạy npm.cmd run test:browser trước thay đổi: exit 0; 90 filter/camera/
  PHP flow checks và 62 receipt browser/HTTP checks passed. Có đối chiếu
  pixel JPG thực cho Inkwell + kính và Reyes + má hồng.
- Lệnh npm.ps1 bị PowerShell ExecutionPolicy chặn; dùng npm.cmd, không đổi
  chính sách máy hay sửa package.json để né lỗi môi trường.

Đã tự rà soát: phạm vi, hợp đồng payload, tương thích polygon, đơn vị hình
học, lỗi tài nguyên, ngân sách bộ nhớ, checklist và điều kiện nghiệm thu.

Theo skill Superpowers brainstorming, người dùng cần duyệt đặc tả này trước
khi chuyển sang kế hoạch triển khai; kế hoạch và phương thức thực hiện cũng
được duyệt trước khi sửa code. Việc người dùng chọn hướng 2 xác nhận hướng
thiết kế, chưa thay thế bước duyệt tài liệu cụ thể này.

## 9. Quyết định khi triển khai

Người dùng đã duyệt đặc tả và yêu cầu thực hiện trong phiên. Chọn thực hiện native trên nhánh feature/configurable-png-face-stickers, giữ nguyên baseline đang sửa. Chỉ 7 file có sẵn thay đổi (4 runtime + 3 file kiểm thử); không cần sửa test router, core hoặc API. Review độc lập phát hiện hai lỗi đã có testcase RED -> GREEN: lỗi budget phải chuyển thành RuntimeException để API trả JSON, và cache phải hủy/giới hạn lượt tải đang chạy. Dữ liệu geometry dùng bitmap aspect ratio. Báo cáo kiểm chứng ở reports/png-stickers-verification.md.

Kết quả cuối: 60 PNG JS + 69 PNG PHP/GD/API checks; browser 108 + 62 checks. Giới hạn kiểm chứng: thiết bị thật chưa thử; fixture symlink trên Windows không tạo được, guard đã được review. Chi tiết và số đo nằm trong báo cáo kiểm chứng.
