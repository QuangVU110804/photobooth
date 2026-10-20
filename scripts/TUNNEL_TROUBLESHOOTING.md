# QR không mở được trên điện thoại: kiểm tra tunnel HTTPS

## Phân biệt trang nhận ảnh và kết nối tunnel

Kiểm tra bằng một ảnh đang tồn tại trong thư mục ảnh đã cấu hình. Nếu trang
`photo.php?image=<filename>` và nút tải hoạt động ở localhost nhưng URL public
không mở được, kiểm tra DNS, HTTPS và tunnel trên đúng máy đang giữ hostname.
DNS không phân giải, kết nối timeout và lỗi chứng chỉ là các lỗi khác nhau;
không kết luận chứng chỉ hết hạn khi chưa nhận được chứng chỉ từ máy chủ.
HTTP 404 của trang nhận ảnh thường là tên ảnh không tồn tại trên máy đích.

Chỉ lưu URL triển khai thật trong biến môi trường, cấu hình Admin hoặc
`config/photo-receipt.local.php`; không đưa cấu hình riêng và ảnh thực vào Git.

## Các bước trên đúng máy chạy booth và Tailscale

1. Xác nhận ảnh tồn tại và URL nhận ảnh mở được tại máy chủ. Dùng đúng cổng Apache của máy đó, không tự suy ra từ cổng trên workspace này.
2. Kiểm tra trạng thái đăng nhập và cấu hình:

   ```powershell
   tailscale status
   tailscale funnel status --json
   tailscale serve status --json
   ```

3. Đối chiếu hostname trả về với hostname trong QR. Nếu đã chuyển máy hoặc đổi hostname, cập nhật **Admin → General → Public URL / Tunnel URL**. Kiểm tra `PHOTOBOOTH_PUBLIC_URL` vì biến môi trường có độ ưu tiên cao hơn cấu hình Admin.
4. Để điện thoại ngoài tailnet truy cập được, cần Funnel công khai. Serve chỉ cho truy cập nội bộ tailnet. Ví dụ cho máy chỉ chạy booth, sau khi xác nhận Apache dùng cổng 8080:

   ```powershell
   tailscale funnel --bg http://127.0.0.1:8080
   ```

   Chỉ dùng cổng 8080 sau khi xác nhận Apache trên máy đó lắng nghe cổng này. Upstream XAMPP dùng HTTP; HTTPS công khai do Funnel xử lý. Kiểm tra cấu hình hiện có trước khi thay đổi để tránh ảnh hưởng ứng dụng khác.
5. Kiểm tra lại **chính URL ảnh** qua HTTPS, không bỏ qua xác minh chứng chỉ, rồi mở bằng điện thoại dùng 4G/5G. Đạt khi trang trả HTTP 200 và nút tải trả đúng ảnh. Nếu đã đổi public URL, mở lại QR của ảnh; QR cũ không tự đổi địa chỉ.

Không dùng `curl -k`, HTTP công khai hoặc bỏ qua cảnh báo Safari để che lỗi TLS. Không đổi public URL sang một hostname chưa được kết nối với máy đang giữ ảnh.

Tham khảo: [Tailscale Funnel CLI](https://tailscale.com/docs/reference/tailscale-cli/funnel), [Funnel và Serve](https://tailscale.com/docs/features/tailscale-funnel).
