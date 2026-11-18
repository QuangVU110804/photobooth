# Khung cho chế độ Bộ ảnh

Đặt ảnh PNG và tệp JSON cùng tên trong thư mục này, hoặc cùng một thư mục chủ đề trực tiếp như **01 - HMU Studio**. Số trước tên thư mục quyết định thứ tự hiển thị. Tệp ở thư mục gốc được xếp vào **Khác**.

Dùng một cặp tệp mẫu trong HMU Studio để thêm khung. Trong JSON, đặt mã `id` duy nhất, tên hiển thị, `layout`, chiều rộng, chiều cao và danh sách `slots`. Mỗi ô là `[x, y, rộng, cao]` theo pixel ở kích thước gốc. Liệt kê từ trái sang phải rồi xuống hàng. PNG cần để trong suốt tại các ô và có cùng kích thước với JSON. Nếu thiếu JSON hoặc số ô không khớp, mẫu không xuất hiện.

Bố cục hỗ trợ gồm 1×2, 1×3, 1×4, 2×2 và 2×3. Giữ `crop` bằng `cover` để ảnh được cắt giữa và phủ kín mỗi ô.
