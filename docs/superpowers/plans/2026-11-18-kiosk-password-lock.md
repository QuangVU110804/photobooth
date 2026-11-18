# Kế hoạch khóa thu phóng và thoát Photobooth bằng mật khẩu

> Khi triển khai, dùng skill `superpowers:executing-plans` để hoàn thành và kiểm thử từng mục.

**Mục tiêu.** Khi khách dùng Photobooth trong cửa sổ kiosk, trang luôn mở toàn màn hình ở mức zoom web 100%. Chỉ người vận hành đã nhập đúng mật khẩu mới có thể đổi zoom, chuyển sang cửa sổ hoặc đóng Photobooth.

**Kiến trúc đề xuất.** Chương trình Windows mở `http://localhost:8080/photobooth/` bằng WebView2. Cửa sổ bên ngoài quản lý toàn màn hình, zoom và xác thực. XAMPP tiếp tục xử lý camera, ảnh, hiệu ứng, QR và dữ liệu.

**Công nghệ.** C# WinForms, WebView2 Evergreen và bản .NET LTS được hỗ trợ. WebView2 Runtime đã có trên máy. Lần kiểm tra này chưa thấy lệnh `dotnet` trên PATH. Ưu tiên dựng bản chạy thử trong thư mục riêng và đóng gói dạng tự chứa để máy vận hành không cần cài SDK.

**Trạng thái.** Phương án để duyệt. Checklist bên dưới dùng cho giai đoạn triển khai.

## Hiện trạng và lựa chọn

- `index.php` nạp `resources/js/kiosk-zoom.js`. Script chặn một số cử chỉ và phím thu phóng trong trang. `resources/js/core.js` dùng Fullscreen API cho nút toàn màn hình của trang. Quyền đóng tab, đóng trình duyệt và thoát toàn màn hình của trình duyệt nằm ngoài các script này.
- **WebView2 trên máy hiện tại.** Windows báo `EditionID=Core` (Home), build `26200`. WebView2 cho phép đặt `ZoomFactor=1.0` và khóa các thao tác thu phóng của người dùng. Cửa sổ WinForms có thể giữ yêu cầu đóng cửa sổ cho tới khi xác thực. Đây là phương án đề xuất vì đáp ứng nhu cầu ngay trên máy hiện tại. [Điều khiển zoom của WebView2](https://learn.microsoft.com/en-us/dotnet/api/microsoft.web.webview2.core.corewebview2settings.iszoomcontrolenabled) · [Cấu hình cử chỉ và phím tắt](https://learn.microsoft.com/en-us/dotnet/api/microsoft.web.webview2.core.corewebview2settings)
- **Edge Assigned Access cho máy kiosk chuyên dụng.** Phù hợp nếu cần giới hạn cả phiên Windows và chấp nhận dùng bản Windows hỗ trợ Assigned Access. Kịch bản thoát kiosk mặc định của Windows và đăng nhập tài khoản quản trị là hai bước riêng. [Yêu cầu Assigned Access](https://learn.microsoft.com/en-us/windows/configuration/assigned-access/) · [Chế độ kiosk của Edge](https://learn.microsoft.com/en-us/deployedge/microsoft-edge-configure-kiosk-mode)

## Luồng sử dụng

| Tình huống | Hành vi dự kiến |
| --- | --- |
| Mở Photobooth Kiosk hoặc mở lại sau khi đóng | Mở đúng URL XAMPP, toàn màn hình, zoom web 100% và ở chế độ khách. Mức Scale của Windows giữ nguyên. |
| Khách dùng `Ctrl` với `+`, `-`, `0` hoặc con lăn, chụm ngón trên màn hình hay touchpad | Zoom vẫn ở 100%. Cuộn thường, chạm một ngón, điều hướng bàn phím trong trang và hướng dẫn vẫn hoạt động. |
| Khách nhấn `F11`, `Ctrl+W`, `Ctrl+T` hoặc tìm menu chuyển tab | Cửa sổ kiosk vẫn mở ở chế độ khách. Hộp thoại và phím `Esc` do Photobooth sử dụng trong trang vẫn hoạt động. |
| `Alt+F4` hoặc yêu cầu đóng cửa sổ thông thường | Mở một hộp thoại mật khẩu của chương trình Windows. Hủy hoặc nhập sai sẽ quay về Photobooth, giữ phiên chụp đang mở. |
| Người vận hành nhấn `Ctrl+Shift+F12` | Mở hộp thoại mật khẩu. Phím này là mặc định đề xuất và sẽ được kiểm tra xung đột với bàn phím, thiết bị điều khiển đang dùng. |
| Xác thực thành công | Mở bảng điều khiển có các mức 80%, 90%, 100%, 110%, 125% và 150%, nút chuyển toàn màn hình/cửa sổ, nút **Khóa lại** và nút **Thoát Photobooth** có xác nhận. |
| Khóa lại hoặc sau 2 phút từ lần xác thực | Tự trở về toàn màn hình, zoom 100%. Lần thay đổi tiếp theo cần nhập lại mật khẩu. |

## Phạm vi bảo vệ

- Khóa các thao tác điều khiển **cửa sổ Photobooth Kiosk**. `Alt+Tab`, phím Windows, `Ctrl+Alt+Del`, Trình quản lý tác vụ và tắt máy là thao tác của Windows. Phương án trên Home không tạo được ranh giới kiosk toàn hệ điều hành. Với khách có thể tiếp cận bàn phím và yêu cầu bảo vệ cả desktop, đánh giá phương án máy kiosk chuyên dụng trước khi triển khai.
- Mở `localhost` bằng Cốc Cốc hoặc một trình duyệt thường vẫn dùng luồng hiện có. Mật khẩu kiosk là mật khẩu vận hành cửa sổ trên máy đặt booth. Điện thoại quét QR tiếp tục dùng đường dẫn nhận ảnh hiện tại.
- Giữ nguyên ảnh đã chụp, khung gốc, cấu hình PHP/Apache, danh mục khung, chức năng Ảnh đơn/Bộ ảnh và hồ sơ trình duyệt hiện có. Tách hồ sơ WebView2 và tệp xác thực Windows ra ngoài thư mục phục vụ web.

## Checklist triển khai

### 1. Dựng mẫu và đo tương thích

- [ ] Dựng mẫu WinForms/WebView2 ở checkout riêng, dự kiến mã nguồn dưới `tools/windows-kiosk/` và bản chạy cùng dữ liệu vận hành ngoài `htdocs`. Xác nhận WebView2 Runtime, công cụ biên dịch và kiến trúc máy trước khi chọn phiên bản SDK. Cài thêm công cụ chỉ khi được phép.
- [ ] Tạo bản sao thử nghiệm từ `C:\xampp\htdocs\photobooth`. Chạy `npm test` và `npm run test:browser` với dữ liệu giả lập. Mở URL của bản sao bằng WebView2 và kiểm tra camera, WebGL/MediaPipe, hộp thoại quyền camera, tệp tải xuống và nút in nếu cấu hình có bật.
- [ ] Kiểm tra locale, chọn khung, lưu lựa chọn sau khi đóng mở, và đường dẫn QR từ điện thoại. Lần mở với hồ sơ mới, trang có thể tự đồng bộ `current_active_frame.png`. Xác định khung đang dùng trước khi mở thử trên XAMPP thật và kiểm tra lựa chọn ban đầu để tránh ghi đè ngoài ý muốn.
- [ ] Đo tải trang và một lượt chụp trên máy booth. Nếu WebView2 không đạt tương thích camera, tải ảnh, QR hoặc in đang dùng, dừng trước khi thay quy trình vận hành.

### 2. Khóa cửa sổ và thu phóng

- [ ] `tools/windows-kiosk/MainForm.cs` quản lý cửa sổ không viền khi khóa. Khi mở hoặc khóa lại, đặt WebView2 `ZoomFactor=1.0`, tắt `IsZoomControlEnabled` và `IsPinchZoomEnabled`. Dùng cơ chế phím tắt WebView2 chỉ cho những lệnh trình duyệt cần chặn. Kiểm tra không làm mất phím chọn, cuộn, nhập liệu và phím tắt đã có của Photobooth.
- [ ] Chặn tab mới hoặc điều hướng cấp trên ra ngoài `http://localhost:8080/photobooth/` bằng sự kiện điều hướng của WebView2. Kiểm tra URL theo giao thức, host, cổng và tiền tố đường dẫn đã chuẩn hóa. Giữ luồng nhận hoặc tải ảnh cùng site và kiểm chứng riêng luồng `target=_blank` hoặc cửa sổ in nếu đang dùng.
- [ ] Dùng `FormClosing` để đưa các yêu cầu đóng tương tác về cùng hộp thoại xác thực. Bảo toàn đường đóng do Windows shutdown. Giữ bảng điều khiển và mật khẩu ở giao diện Windows. Tắt cầu nối thông điệp web–native khi không dùng. [Hướng dẫn an toàn của WebView2](https://learn.microsoft.com/en-us/microsoft-edge/webview2/concepts/security)
- [ ] Trong hồ sơ thử, nhấn `F11`, `Ctrl+W`, `Ctrl+T`, `Alt+F4`, thử nút đóng, các phím zoom, con lăn có `Ctrl`, pinch màn hình và pinch touchpad. Kiểm tra 100% cả sau chuyển route, tải lại và mở lại. Chạy lại `node scripts/test-kiosk-zoom.cjs` để kiểm tra hành vi trong trang.

### 3. Xác thực người vận hành

- [ ] `tools/windows-kiosk/OperatorPassword.cs` quản lý mật khẩu riêng do người vận hành đặt trong lần thiết lập có chủ đích. Lưu hash PBKDF2-HMAC-SHA256 với salt ngẫu nhiên, tham số phiên bản và hệ số lặp ngoài `htdocs`. So sánh digest theo thời gian cố định. Không ghi mật khẩu vào URL, nhật ký, cấu hình PHP hay mã nguồn.
- [ ] Giữ phiên mở khóa trong RAM, hết hạn sau 2 phút kể từ xác thực. Sau 5 lần sai liên tiếp, khóa nhập 30 giây trước khi thử tiếp. Mở lại chương trình luôn vào chế độ khóa. Hộp thoại hủy bằng `Esc`. Cấu hình xác thực thiếu hoặc hỏng chuyển sang màn hình yêu cầu người vận hành khôi phục, không vào chế độ đã mở khóa.
- [ ] Viết kiểm thử cho mật khẩu đúng, sai, hủy, giới hạn thử, tự khóa, đóng trong chế độ đã xác thực, và đưa trạng thái về 100% sau khi khóa lại. Kiểm tra tắt máy vẫn thực hiện được và yêu cầu đóng khi người dùng đang chụp không làm mất phiên nếu hộp thoại bị hủy.

### 4. Chạy thử trên máy booth và chuẩn bị quay lui

- [ ] Chạy đầy đủ `npm test` và `npm run test:browser` trên bản sao. Trên máy booth, kiểm tra camera thật, Ảnh đơn, cả 5 bố cục Bộ ảnh, hiệu ứng, chụp lại, thư viện, tải ảnh và quét QR bằng điện thoại. Kiểm tra in thật nếu đang sử dụng máy in.
- [ ] Đối chiếu SHA-256 của ảnh đã có, cấu hình và khung gốc trước/sau chạy thử. Kiểm tra WebView2 có màn hình thử lại khi Apache chưa chạy và tự mở đúng URL sau khi người vận hành khởi động lại chương trình.
- [ ] Bàn giao phím mở bảng vận hành, cách đổi và khôi phục mật khẩu, cùng checklist kiểm tra nhanh trước sự kiện. Đặt lối mở Photobooth Kiosk riêng. Khi quay lui, người vận hành xác thực, đóng cửa sổ kiosk và dùng lại Cốc Cốc ở URL cũ. Giữ nguyên máy chủ XAMPP để việc quay lui chỉ thay cách mở trang.

## Điều kiện nghiệm thu

- [ ] Khi khóa, toàn bộ thao tác đóng cửa sổ tương tác đã liệt kê cần xác thực và zoom web vẫn ở 100%.
- [ ] Mật khẩu đúng cho phép thực hiện ba thao tác đã yêu cầu. Mật khẩu sai hoặc hủy không đổi trạng thái. Khóa lại và hết hạn luôn phục hồi 100% và toàn màn hình.
- [ ] Luồng chụp, nhận QR và các tính năng đang bật đạt kiểm thử trên đúng máy. Người vận hành thử được cả đường phục hồi khi Apache dừng và đường quay lui về trình duyệt hiện tại.

