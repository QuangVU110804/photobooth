# Kế hoạch triển khai công tắc Ảnh đơn / Bộ ảnh

> Dùng superpowers:executing-plans theo từng bước và xác nhận hành vi bằng kiểm thử trước khi triển khai vào XAMPP.

**Mục tiêu.** Đưa hai chế độ lên đầu trang chủ của bản XAMPP. Giữ một nút **Chọn hiệu ứng và chụp**, thay vùng khung bên dưới theo chế độ và tiếp tục trả ảnh ghép bằng QR.

**Thiết kế.** Modern và Modern Squared dùng chung template, bộ tạo thẻ khung, bộ lọc chủ đề và màn hình hiệu ứng. Ảnh đơn dùng kho và trạng thái hiện hành. Bộ ảnh nhớ lựa chọn riêng, dùng kho PNG có tệp JSON chỉ vị trí ô. Khi mở trang, chế độ mặc định là Ảnh đơn.

- [ ] **Kho khung.** Dùng `resources/img/Multi-frames` với PNG ở thư mục gốc hoặc một thư mục chủ đề. Dùng lại quy tắc nhóm và sắp xếp của `frames`, tải ảnh xem trước qua cache hiện có. Chuyển năm mẫu đang có sang kho mới, giữ mã khung và tọa độ. Kiểm thử khám phá theo thư mục, từ chối PNG thiếu thông tin ô hoặc PNG hỏng và ghép đủ năm bố cục.
- [ ] **Trang chủ.** Đặt công tắc ngay dưới header. Dùng lại thẻ và bộ lọc chủ đề. Bộ ảnh có thêm bộ lọc bố cục. Kiểm thử chuyển chế độ, chọn khung mà chưa bật camera, xem trước riêng, bàn phím và khung dọc trên điện thoại.
- [ ] **Chụp và quay lại.** Đi từ nút chụp chung đến màn hình hiệu ứng đúng chế độ. Nút chọn lại khung trả về cùng chế độ. Hướng dẫn cũ và nút chụp ảnh mới kiểu đơn trở về Ảnh đơn. Nút Chụp bộ mới mở kho Bộ ảnh từ kết quả. Kiểm thử QR, lưu/khôi phục khi mất kết nối, chuyển ngược về Ảnh đơn và hồi quy máy ảnh ngoài.
- [ ] **Xác nhận và triển khai.** Chạy kiểm thử PHP, JS và Chrome trên bản sao từ XAMPP. Rà lỗi cũ của fixture sticker/khung. Sao lưu tệp thay đổi, đưa vào đúng XAMPP, sau đó chạy lại kiểm thử mục tiêu với mã đã cài.
