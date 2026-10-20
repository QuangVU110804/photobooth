# Test case: Hướng dẫn chụp bằng Spotlight Tour

Ngày kiểm tra: 20/10/2026.

Tour dùng Driver.js 1.10.2 được cài và phục vụ trực tiếp từ dự án. Nút **Hướng dẫn** trên trang chủ HMU là điểm bắt đầu duy nhất; không có tự chạy hoặc ghi nhớ lượt đã xem.

## Chạy lại

- `npm run test:guide`: kiểm tra tour trong Chrome với thao tác chuột, cảm ứng và bàn phím thật qua CDP.
- `npm test`: các kiểm tra PHP/API/JavaScript hiện có của dự án.
- `npm run test:browser`: tour, làm đẹp bằng GPU, luồng chụp và trang nhận ảnh trên điện thoại.

## Các tình huống của tour đã kiểm tra

| Mã | Tình huống | Kết quả mong đợi và đã quan sát |
| --- | --- | --- |
| TG01 | Mở hoặc tải lại trang | Tour không tự chạy. |
| TG02 | Chạm chọn khung trong vùng sáng | Khung thật được chọn; camera chưa mở và chưa chụp ảnh. |
| TG03 | Đồng bộ khung đang chờ hoặc thất bại | Giữ bước chọn khung; chỉ chuyển sau khi máy chủ xác nhận thành công. |
| TG04 | Chạm nút mở camera | Mở đúng màn hình camera hiện có; chờ camera sẵn sàng trước bước tiếp theo. |
| TG05 | Camera sẵn sàng | Chuyển trực tiếp sang nút Bắt đầu chụp ảnh, không chờ bộ chọn màu đã gỡ. |
| TG06 | Mở màn hình chụp | Không còn mục Màu ảnh; làm đẹp và hiệu ứng khuôn mặt vẫn sử dụng được. |
| TG07 | Tìm nút Next hoặc dùng phím mũi tên | Không có nút Next hiển thị; không thể dùng mũi tên để bỏ qua hành động. |
| TG08 | Nhấn × hoặc Escape | Tour kết thúc, lớp tối biến mất, camera vẫn dùng được; focus trở về nút thao tác phù hợp. |
| TG09 | Nhấn Bắt đầu chụp ảnh | Không có spotlight trong đếm ngược/xử lý; phát sinh đúng một yêu cầu chụp thật. |
| TG10 | Ảnh đã sẵn sàng, nhấn QR | Nút QR được hướng dẫn sau khi ảnh hiện; mở modal QR thật rồi kết thúc tour để mã không bị che. |
| TG11 | Kết thúc, tải lại và mở tour nhiều lần | Không ghi tiến độ hoặc hoàn thành vào localStorage/sessionStorage; mở lại được bình thường. |
| TG12 | Trình duyệt từ chối quyền camera | Chờ ở bước camera với nút dừng; có thể chuyển sang nguồn máy ảnh ngoài để tiếp tục. |
| TG13 | Tắt QR hoặc nhóm nút kết quả | Hoàn tất sau khi ảnh sẵn sàng mà không bị kẹt ở nút ẩn. |
| TG14 | Lệnh chụp máy ảnh ngoài thất bại | Kết thúc tour, bỏ lớp tối và giữ nguyên thông báo lỗi chụp của ứng dụng. |
| TG15 | Màn hình thấp và mobile 390 × 844, bật giảm chuyển động | Hộp hướng dẫn nằm trong viewport; hướng dẫn chọn khung không che vùng chọn khung. |
| TG16 | Giao diện Classic và chụp không mở tour | Classic không nạp tour; luồng chụp thường vẫn hoạt động. |
| TG17 | Enter/Space vừa là phím thao tác vừa được cấu hình chụp | Mở Hướng dẫn và chọn khung không đồng thời kích hoạt phím chụp; phím chụp hoạt động lại sau khi thoát tour. |
| TG18 | Các bước trong dialog camera | Hộp hướng dẫn, nút đóng và hướng dẫn chờ camera nằm trong dialog đang hoạt động, không nằm ngoài ranh giới aria-modal. |

Lệnh `npm run test:guide` đã hoàn thành với **55 kiểm tra đạt**, không có lỗi JavaScript hoặc cảnh báo/lỗi PHP trong phiên chạy.

## Kết quả kiểm tra hồi quy cuối cùng

| Lệnh / nhóm kiểm tra | Kết quả |
| --- | --- |
| `npm test` | Đạt; toàn bộ nhóm PHP/API/JavaScript kết thúc với exit code 0. |
| `npm run test:browser` | Đạt; toàn bộ chuỗi kiểm tra kết thúc với exit code 0. |
| Tour trong bộ kiểm tra trình duyệt | 55 kiểm tra đạt. |
| Làm đẹp GPU/camera/PHP | 52 kiểm tra đạt. |
| Chụp, hiệu ứng, sticker và xuất ảnh | 120 kiểm tra đạt. |
| Trang nhận ảnh và HTTP | 62 kiểm tra đạt. |

Kết quả trên được kiểm tra lại sau khi gỡ phần Màu ảnh khỏi giao diện và xử lý. Chạy các lệnh trong mục Chạy lại để lấy log của môi trường hiện tại.

## Phạm vi kiểm tra

Các test dùng PHP 8.2.12/GD, Node.js 24 và Chrome headless với camera giả lập. Pipeline chụp và xử lý ảnh là pipeline thật của ứng dụng; ảnh, QR và trạng thái kiểm thử được tách vào thư mục tạm. Ảnh chụp màn hình được xuất vào `reports/guide-*.png`.

Kiểm tra cấu trúc dialog và bàn phím đã được tự động hóa. Chưa thử trực tiếp với trình đọc màn hình, điện thoại vật lý hoặc DSLR/máy in thật.
