# Photobooth Kiosk cho Windows

Mở Photobooth trong cửa sổ toàn màn hình ở zoom web 100%. Khi có yêu cầu đóng cửa sổ hoặc đổi chế độ hiển thị, người vận hành xác thực bằng mật khẩu riêng. Tính năng chạy ở cửa sổ **Photobooth Kiosk**, nên đường dẫn Photobooth mở bằng trình duyệt thường và đường dẫn QR trên điện thoại vẫn dùng luồng web hiện có.

## Mở kiosk

1. Chọn thư mục bản chạy phù hợp kiến trúc Windows. Giữ các tệp phát hành cùng nhau trong thư mục ngoài web root.
2. Mở **Dat-mat-khau.cmd** để tự đặt mật khẩu lần đầu. Mật khẩu cần từ 10 đến 256 ký tự. Khi đổi mật khẩu, đóng kiosk trước, chạy lại tệp này và nhập mật khẩu hiện hành.
3. Bật Apache trong XAMPP rồi mở **PhotoboothKiosk.exe**. Mặc định chương trình mở `http://localhost:8080/photobooth/`.

Nếu Photobooth dùng cổng hoặc thư mục khác, tạo shortcut với đối số tương ứng. Các địa chỉ được hỗ trợ là `localhost`, `127.0.0.1` và `[::1]`.

```powershell
.\PhotoboothKiosk.exe --url http://localhost:9090/booth/
```

Nếu địa chỉ HTTPS được dùng, WebView2 kiểm tra chứng chỉ theo chính sách Windows hiện hành. [Yêu cầu WebView2 và phiên bản Windows](https://learn.microsoft.com/en-us/microsoft-edge/webview2/) · [WebView2 Evergreen Runtime](https://learn.microsoft.com/en-us/microsoft-edge/webview2/concepts/distribution)

## Vận hành

- `Ctrl+Shift+F12` mở xác thực. Bảng vận hành có mức thu phóng, nút toàn màn hình/cửa sổ, **Khóa lại** và **Thoát Photobooth**.
- `Ctrl+W`, `Alt+F4` và yêu cầu đóng cửa sổ thông thường đi qua cùng luồng xác thực. Khóa lại hoặc hết 2 phút sẽ đưa trang về toàn màn hình 100%.
- Nếu XAMPP chưa khởi động, cửa sổ hiện thông báo và nút **Thử lại**.
- Dữ liệu hồ sơ WebView2 và hash mật khẩu nằm trong `%LOCALAPPDATA%\HMU Photobooth\Kiosk`. Nếu tệp xác thực hỏng, đóng kiosk rồi khôi phục bản sao `operator.json`. Nếu quên mật khẩu, người vận hành có quyền vào desktop Windows phải đưa tệp cũ ra khỏi thư mục này và chạy thiết lập lại.
- Lối thoát dự phòng là quay về cách mở Photobooth bằng trình duyệt hiện tại. Tổ hợp hệ thống, Trình quản lý tác vụ và tắt máy thuộc Windows.
- Với máy có chính sách Application Control, bộ phận quản trị cần dùng quy trình phê duyệt hoặc ký ứng dụng của đơn vị trước khi vận hành.

## Biên dịch và kiểm thử

Dùng .NET 10 SDK trên máy biên dịch. Bản chạy tự chứa phần .NET và dùng WebView2 Runtime của máy nhận. Chọn RID theo mục **System type** của Windows.

```powershell
.\publish.ps1 -Architecture win-x64 -OutputDirectory C:\Kiosk\Photobooth
dotnet run --project .\tests\PhotoboothKiosk.Tests.csproj
dotnet build .\tests\window\PhotoboothKiosk.WindowTests.csproj
```

Có thể đổi RID sang `win-x86` hoặc `win-arm64` trên các bản Windows tương thích của Microsoft. [Nền tảng .NET được hỗ trợ](https://learn.microsoft.com/en-us/dotnet/core/install/windows)

Kiểm thử cửa sổ dùng một URL fixture **đang tắt** và hai đường dẫn marker trong thư mục tạm. Chạy chương trình test với ba đối số theo thứ tự URL fixture, marker yêu cầu bật máy chủ, marker máy chủ đã sẵn sàng. Khi chương trình tạo marker đầu, bật máy chủ fixture trên URL đó rồi tạo marker thứ hai. Fixture là bản sao Photobooth cô lập với danh mục khung đơn và 5 bố cục Bộ ảnh. Không trỏ test vào XAMPP đang phục vụ người dùng. Sau khi test tự đóng, xóa hồ sơ fixture trong thư mục tạm.

Chạy thêm `npm test` và `npm run test:browser` trong bản sao XAMPP. Trước sự kiện, thử cảm ứng, bàn phím, camera thật, tải ảnh, QR trên điện thoại và in nếu máy đang dùng in ảnh. So sánh hash của dữ liệu gốc và cấu hình trước/sau.
