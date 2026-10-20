# Checklist camera, preview, QR và thư viện

## 1. Nguồn camera
- [x] Mặc định mới dùng camera trình duyệt; giữ cấu hình cá nhân đã có.
- [x] Chọn nguồn chụp camera thiết bị hoặc máy ảnh ngoài; chọn camera được trình duyệt nhận diện.
- [x] Truyền nguồn đã chọn đến API; kiểm tra ảnh JPEG và camera sẵn sàng trước khi lưu.
- [x] Thông báo khi camera bị từ chối quyền hoặc lệnh chụp máy ảnh ngoài thất bại.
- [ ] Thử webcam laptop, webcam USB và camera trước/sau điện thoại thực tế.
- [ ] Thử máy ảnh DSLR/Mirrorless có driver hỗ trợ với lệnh chụp đã cấu hình.

Máy ảnh phải được hệ điều hành/trình duyệt nhận diện hoặc được digiCamControl/gphoto2 hỗ trợ. Camera của trình duyệt thuộc thiết bị đang mở trang; máy ảnh ngoài thuộc máy chủ chạy booth. Không tự cài driver hoặc thay đổi cấu hình hệ điều hành.

## 2. Khóa zoom
- [x] Chặn phím tắt zoom, Ctrl/cuộn chuột, pinch và zoom PhotoSwipe.
- [x] Giữ cuộn trang bình thường, chọn ảnh và chuyển ảnh.
- [ ] Kiểm tra cử chỉ trên thiết bị cảm ứng thực tế.

## 3. Preview khi đếm ngược
- [x] Giữ camera đang chạy cùng khung và hiệu ứng khuôn mặt đã chọn.
- [x] Dùng cùng nguồn video để chụp ảnh; xử lý hiệu ứng trên ảnh gốc ở máy chủ.
- [x] Trở về màn chọn khung và giải phóng camera khi hủy.

## 4. QR và tunnel
- [x] Dùng chung URL nhận ảnh cho màn kết quả, thư viện và bản in.
- [x] Cho phép cấu hình public URL trong quản trị; giữ ưu tiên biến môi trường.
- [x] Cache QR phụ thuộc toàn bộ URL; cấu hình JavaScript không được cache.
- [ ] Xác nhận public URL của môi trường triển khai trùng với URL QR thực tế.
- [ ] Bật/kiểm tra tunnel trỏ đến cổng web thực tế; quét QR từ điện thoại để tải ảnh.

Các bước kiểm tra trên đúng máy chủ: [TUNNEL_TROUBLESHOOTING.md](TUNNEL_TROUBLESHOOTING.md). Trạng thái tunnel phải được xác minh riêng cho mỗi môi trường triển khai.

Xác nhận cổng Apache đang lắng nghe trước khi cấu hình tunnel. URL localhost không ghi cổng có thể truy cập một dịch vụ khác. URL public phải chứa thư mục cài đặt, ví dụ `https://your-tunnel.example/photobooth`. File `config/photo-receipt.local.php` chứa URL riêng và không được đưa lên Git.

## 5. Xóa hàng loạt
- [x] Chọn từng ảnh, chọn tất cả, bỏ chọn và hủy chế độ chọn.
- [x] Xác nhận số lượng trước khi xóa; chỉ xóa ảnh đã chọn.
- [x] Kiểm tra toàn bộ tên file trước khi xóa; giới hạn mỗi request 100 ảnh.
- [x] Báo riêng thành công/thất bại; giữ ảnh thất bại để thử lại.
- [x] Hoãn tự tải lại thư viện trong lúc chọn/xóa ảnh.

## Kiểm thử tự động
- `npm.cmd test`
- `npm.cmd run test:browser`

Đã chạy thành công ngày 15/10/2026: 5 quality gates; 130 kiểm tra API, 164 hiệu ứng, 69 nhận ảnh, 7 URL triển khai, 40 frame preview, 13 resize, 14 capture session, 22 gallery polling, 10 khóa zoom và kiểm tra tương tác cuộn. Bộ trình duyệt có 90 kiểm tra luồng camera/PHP và 62 kiểm tra nhận ảnh/HTTP, không có lỗi JavaScript/PHP. Rà soát code đã xử lý các trường hợp đổi nguồn URL → browser → máy ảnh ngoài, khởi động lại preview khi chụp lại và cử chỉ nhiều điểm chạm qua Pointer Events.

Kiểm thử chụp dùng Chrome headless, camera giả lập và PHP/GD thực. Ảnh xóa trong kiểm thử nằm trong thư mục fixture riêng; không xóa ảnh của người dùng.
