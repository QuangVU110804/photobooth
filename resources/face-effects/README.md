# Tự thêm sticker bám khuôn mặt

Thêm PNG nền trong suốt vào `stickers/`, thêm một effect trong `catalog.json`,
rồi tải lại trang booth. Không cần sửa JavaScript/PHP cho mẫu dùng các điểm
neo đã có. Giữ `version: 1`; không dùng ID `none`, `glasses`, `blush`.

Ví dụ một effect (đặt trong mảng `effects`):

```json
{
  "id": "my-crown",
  "title": "Vương miện của tôi",
  "parts": [
    {
      "file": "my-crown.png",
      "anchor": "forehead",
      "width": 1.6,
      "origin": [0.5, 1],
      "offset": [0, 0.04],
      "opacity": 1
    }
  ]
}
```

`crown.png` đi kèm là hình vương miện gốc vẽ riêng cho dự án. Có thể sao chép
effect `gold-crown` để thử thông số trước khi thay bằng ảnh của bạn.

Danh mục hiện giữ vương miện vàng cùng hai PNG `hat`, `mask`.
Má hồng và kính thời trang vẫn là các hiệu ứng có sẵn, không khai báo lại trong JSON.
Tọa độ của các PNG nằm trong `catalog.json`; không cần file JSON riêng.
Mũ neo vào trán, khẩu trang neo vào miệng. `hat.png` rộng 1500 px và `mask.png`
rộng 1254 px; giữ nguyên kích thước ảnh được cung cấp.

PNG ống nghe chưa có trong bộ source, nên effect `stethoscope` được tạm bỏ khỏi
catalog để các sticker đã đủ tài nguyên xuất ảnh bình thường. Khi bổ sung PNG gốc,
có thể khai báo lại với anchor `chin`, width `2.4`, origin `[0.385, 0.075]`,
offset `[0, 0.15]`, opacity `1`. Ống nghe neo vào cằm rồi dịch xuống
`0.15` lần khoảng cách mắt, tạo hiệu ứng đeo ở cổ/ngực. Điểm đặt trong PNG là
`origin: [0.385, 0.075]`, gần giữa hai nút tai của nhánh chữ U, thay vì giữa
toàn ảnh có mặt nghe lệch phải. Cấu hình này tính cả vùng trong suốt của PNG
1500x1500 và giữ nguyên ảnh gốc. Ống nghe bám chuyển động đầu theo cơ chế 2D,
không nhận diện vai/ngực hay gắn hai nút tai vào hai tai thật.

| Trường | Ý nghĩa |
|---|---|
| `id` | Duy nhất, tối đa 64 ký tự ASCII thường/số/gạch ngang/gạch dưới; bắt đầu bằng chữ hoặc số |
| `title` | Tên nút chọn, tối đa 80 ký tự |
| `file` | Tên file `.png` trong `stickers/`; chỉ chữ ASCII/số/gạch ngang/gạch dưới |
| `anchor` | Vùng khuôn mặt để bám |
| `width` | Độ rộng PNG / khoảng cách hai điểm mắt; từ 0.01 đến 4 |
| `origin` | Điểm trong PNG sẽ bám vào anchor: `[0,0]` góc trên trái, `[0.5,1]` giữa đáy; mặc định `[0.5,0.5]` |
| `offset` | Dịch từ anchor theo khoảng cách mắt; X dương sang phải và Y dương xuống dưới theo trục khuôn mặt; mặc định `[0,0]`, mỗi số trong [-2,2] |
| `opacity` | Độ đậm 0..1; mặc định 1. PNG có thể chứa alpha/chuyển sắc riêng |

Các anchor: `eyes`, `left-eye`, `right-eye`, `left-cheek`, `right-cheek`,
`nose`, `mouth`, `forehead`, `chin`, `face`. Tên left/right theo thứ tự
landmark đầu vào chưa lật; camera xem trước có thể được cấu hình lật.
`eyes` là trung điểm landmark 33/263; `face` là trung điểm trán/cằm.
Mọi thành phần xoay theo đường nối hai điểm mắt và giữ tỷ lệ PNG gốc.

Một effect có tối đa 4 part, vẽ theo thứ tự trong mảng: part sau phủ lên part
trước. Hai má dùng hai part với anchor `left-cheek`/`right-cheek`. Bộ chọn
giữ một effect tại một thời điểm và áp dụng cùng mẫu cho tối đa 4 người.

Giới hạn tài nguyên: tối đa 32 effect; PNG tối đa 1536x1536, 2 MiB/file;
catalog tối đa 64 KiB. Không dùng URL, thư mục con, SVG/GIF/WebP hoặc đường
dẫn tuyệt đối. Thiết kế PNG quanh nội dung, tránh nhiều vùng trong suốt dư.
PNG lỗi bị vô hiệu hóa khi chọn; các lựa chọn không hiệu ứng/legacy vẫn dùng được.

Để hiệu chỉnh: dùng camera xem trước, chỉnh `width` trước, rồi `origin` và
`offset`; thử nghiêng đầu và đứng gần/xa. Cuối cùng chụp và mở JPG tải về để
kiểm tra với khung, filter màu và chế độ lật ảnh đang dùng. Sticker chịu
filter màu như má hồng/kính hiện tại, nên Inkwell/Moon có thể đổi màu sticker.
Nếu sticker quá lớn cho ngân sách bề mặt xuất ảnh, giảm `width`; không tăng
giới hạn bộ nhớ của cả hệ thống chỉ để bù cho một tài nguyên quá lớn.

PNG được nạp khi chọn, không nạp toàn bộ danh mục ngay lúc mở booth. Module
giữ tối đa hai bộ ảnh/8 triệu pixel; nhận diện dùng Worker hiện có và ảnh JPG
được nhận diện lại riêng trước khi ghép. Hiệu ứng là 2D; góc quay ngang mạnh
hoặc che khuất bởi tóc/mũi chưa có xử lý chiều sâu.
Các lượt tải có thể hủy khi đổi sang không hiệu ứng, quay lại hoặc rời trang;
tối đa 4 lượt tải/decode chạy đồng thời, dùng lại HTTP cache của trình duyệt.

Kiểm tra tự động: `npm.cmd test`, `npm.cmd run test:browser` trên Windows
(`npm test`, `npm run test:browser` nếu shell không chặn npm.ps1).
