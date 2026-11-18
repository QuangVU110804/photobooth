# Kế hoạch triển khai chụp nhiều ảnh ghép khung cho bản XAMPP

Kế hoạch áp dụng cho **`C:\xampp\htdocs\photobooth`**, theo hướng bổ sung **Chụp nhiều ảnh ghép khung** và bảo toàn toàn bộ hành vi của chức năng chụp đơn hiện tại.

Kế hoạch sử dụng **Brainstorming**, **Writing Plans** và **Ponytail** để rà phạm vi, xác định điểm tích hợp và chia công việc thành các bước có tiêu chí kiểm chứng.

Luồng đích gồm hai lựa chọn độc lập.

| Chức năng | Luồng người dùng |
|---|---|
| **Chụp đơn** | Chọn khung hiện có → chọn hiệu ứng → chụp → xem kết quả → nhận ảnh qua QR |
| **Chụp nhiều ảnh** | Chọn khung nhiều ô → chọn hiệu ứng → chụp lần lượt đủ ảnh → ghép → xem kết quả → nhận ảnh ghép qua QR |

Trong kế hoạch này, tôi đề xuất mặc định chụp tự động lần lượt, đếm ngược trước mỗi ảnh, dùng chung lựa chọn hiệu ứng trong một bộ và xuất một JPEG thành phẩm. Năm bố cục được hỗ trợ là **1×2, 1×3, 1×4, 2×2 và 2×3**, theo quy ước số cột × số hàng.

**Việc bảo toàn chức năng cũ được đưa thành tiêu chí nghiệm thu.** Phương án tái sử dụng cần bổ sung một số điểm nối trong mã hiện có. Mỗi điểm nối phải có kiểm thử xác nhận đường chụp đơn vẫn cho hành vi trước đó.

| Phần hiện có | Yêu cầu bảo toàn |
|---|---|
| Khung chụp đơn | Danh sách, chủ đề, thứ tự, lựa chọn đã nhớ và cách phủ khung |
| Camera | Webcam, lựa chọn thiết bị, máy ảnh ngoài, lệnh chụp và các khoảng chờ đã cấu hình |
| Hiệu ứng | Các điều chỉnh làm đẹp, sticker, xem trước, lật gương và hành vi khi hiệu ứng gặp lỗi |
| Kết quả | QR, tải ảnh, thư viện, in, email, xóa và các quyền bật/tắt hiện hành |
| Giao diện | Sáng/tối, hướng dẫn, thao tác cảm ứng, bàn phím, nút bấm ngoài và khóa zoom |
| Dữ liệu | Ảnh cũ, liên kết QR cũ, cấu hình riêng và chính sách giữ ảnh gốc |
| API cũ | Đầu vào, phản hồi và các trường hợp lỗi mà bên gọi hiện tại đang sử dụng |

Bản hiện tại đã loại bỏ collage cũ và có kiểm thử chủ động từ chối chế độ đó. Chức năng mới sẽ dùng một API riêng, còn các kiểm thử này tiếp tục bảo vệ hợp đồng cũ. [Cấu hình hiện tại](/C:/xampp/htdocs/photobooth/lib/config.php:117) · [Kiểm thử phiên chụp](/C:/xampp/htdocs/photobooth/scripts/test-filter-session.cjs:9)

1. **Ghi nhận hiện trạng và chuẩn bị môi trường kiểm thử**

   Khi bắt đầu triển khai, lấy mã và tài nguyên từ đúng bản XAMPP để tạo bản làm việc riêng. Ghi nhận các tệp sẽ thay đổi, cấu hình đang dùng, bộ khung hiện tại và một số ảnh kết quả làm mẫu đối chiếu.

   Chạy bộ kiểm thử hiện có trên môi trường tách biệt và xác định kết quả ban đầu. Kiểm tra PHP/GD, Node, Chrome, nguồn camera, địa chỉ nhận ảnh và cấu hình giữ ảnh gốc. Các bài kiểm tra có tạo hoặc xóa dữ liệu phải dùng thư mục thử nghiệm.

   **Điều kiện hoàn thành** là có mốc đối chiếu rõ ràng cho chụp đơn, các bài kiểm thử cũ và cấu hình của booth. Mỗi lỗi xuất hiện sau đó có thể đối chiếu với mốc này.

2. **Tạo kho khung nhiều ảnh và mô tả chính xác từng ô**

   Tạo kho mới tại `C:\xampp\htdocs\photobooth\resources\img\multishot-frames`, gồm PNG và danh mục `catalog.json`. Cách này cho phép bộ chọn khung chụp đơn tiếp tục đọc kho đang dùng.

   Mỗi mẫu mới cần có các thông tin sau.

   | Thông tin | Quy tắc |
   |---|---|
   | Mã khung | Duy nhất và ổn định, dùng để chọn mẫu qua API |
   | Tên và chủ đề | Dùng trong bộ chọn khung |
   | Bố cục | Một trong năm bố cục đã nêu |
   | Kích thước thành phẩm | Khớp kích thước PNG của mẫu |
   | Các ô ảnh | Tọa độ và kích thước từng ô, tính theo pixel của thành phẩm |
   | Thứ tự ảnh | Từ trên xuống với dải dọc, từ trái sang phải rồi xuống hàng với dạng lưới |
   | Cách cắt ảnh | Cắt giữa để phủ kín ô, giữ đúng tỷ lệ ảnh |

   Số lần chụp được suy ra từ mẫu đã chọn. Khung 1×3 yêu cầu ba ảnh, khung 2×3 yêu cầu sáu ảnh. Máy chủ kiểm tra số ô, kích thước, đường dẫn PNG và giới hạn tài nguyên trước khi nhận mẫu.

   Chuẩn bị ít nhất một mẫu hợp lệ cho mỗi bố cục. Phần đọc và kiểm tra danh mục nằm trong tệp mới `C:\xampp\htdocs\photobooth\lib\multishot-frames.php`, có thể dùng lại bộ đọc PNG và tạo ảnh xem trước đang có. [Xử lý khung hiện tại](/C:/xampp/htdocs/photobooth/lib/frames.php:4)

   **Điều kiện hoàn thành** là cả năm mẫu hiển thị đúng tỷ lệ và ghép thử đúng vị trí. Mẫu thiếu PNG, sai số ô hoặc có ô nằm ngoài ảnh phải được báo lỗi rõ ràng trong chức năng mới.

3. **Xây dựng phiên chụp và API dành cho chụp nhiều ảnh**

   Tạo `C:\xampp\htdocs\photobooth\api\multishot.php` làm điểm nhận yêu cầu và `C:\xampp\htdocs\photobooth\lib\multishot.php` để quản lý phiên.

   Mỗi phiên lưu khung đã chọn, bản mô tả bố cục, nguồn camera, lựa chọn hiệu ứng, danh sách ảnh của từng ô, trạng thái xử lý và tên thành phẩm dự kiến. Khung và mô tả được cố định theo phiên để thao tác ở tab khác hoặc cập nhật kho khung không làm sai bộ ảnh đang chụp.

   Hợp đồng API dự kiến gồm các thao tác sau.

   | Thao tác | Đầu vào chính | Kết quả |
   |---|---|---|
   | `frames` | Bộ lọc bố cục hoặc chủ đề | Các mẫu hợp lệ và thông tin xem trước |
   | `start` | Mã khung, nguồn camera, lựa chọn chụp | Mã phiên và số ảnh cần thu |
   | `capture` | Mã phiên, ô ảnh, mã yêu cầu, dữ liệu webcam khi cần | Ảnh nguồn đã được xác nhận |
   | `process` | Mã phiên, ảnh nguồn, dữ liệu hiệu ứng của ảnh | Ô ảnh đã xử lý xong |
   | `status` | Mã phiên | Các ô đã hoàn thành và thao tác đang chạy |
   | `complete` | Mã phiên | Tên JPEG thành phẩm đã lưu |
   | `cancel` | Mã phiên | Trạng thái hủy và kết quả dọn dữ liệu |

   Ảnh nguồn cần được xem qua API kiểm tra quyền sở hữu phiên. Dữ liệu phiên nằm trong thư mục riêng dưới `data/tmp`, có bảo vệ truy cập HTTP trực tiếp. Các thao tác ghi kiểm tra phiên, quyền truy cập theo cấu hình booth và dữ liệu đầu vào.

   Mỗi lần chụp có mã yêu cầu riêng. Khi mất phản hồi, frontend đọc lại trạng thái. Gửi lại cùng yêu cầu phải trả kết quả đã có. Các thao tác cùng phiên được khóa khi cập nhật để tránh hai yêu cầu cùng ghi một ô.

   **Điều kiện hoàn thành** là hai phiên có thể dùng hai khung riêng, yêu cầu lặp không chụp thêm ảnh và một phiên không thể sử dụng ảnh nguồn của phiên khác.

4. **Tái sử dụng camera và điều phối chuỗi chụp**

   Phần thao tác camera đang nằm trong `takePicture()` của API chụp đơn. Đưa phần có thể dùng chung sang thư viện capture, rồi để API cũ và API mới gọi lại. Việc chuyển này phải giữ đủ kiểm tra ảnh, xử lý lật, lệnh máy ảnh, thông báo lỗi và cách đặt tên đang có. [Hàm chụp hiện tại](/C:/xampp/htdocs/photobooth/api/takePic.php:27) · [Thư viện capture](/C:/xampp/htdocs/photobooth/lib/capture.php:22)

   Bộ điều phối mới thực hiện tuần tự từng ô.

   **Camera sẵn sàng → đếm ngược → lấy ảnh → xác nhận lưu → xử lý hiệu ứng → cập nhật ô → chuyển sang ô tiếp theo.**

   Với webcam, phiên duy trì luồng camera phù hợp giữa các lần chụp. Với máy ảnh ngoài, mỗi lần chụp tuân theo lệnh preview, lệnh dừng và khoảng chờ đã cấu hình. Những thao tác điều khiển cùng máy ảnh ngoài cần dùng chung cơ chế kiểm tra bận để tránh tranh chấp giữa chụp đơn, chụp nhiều ảnh và preview.

   Nếu ảnh thứ ba lỗi, hai ảnh đã hoàn thành tiếp tục thuộc phiên. Người dùng được thử lại ô hiện tại hoặc hủy bộ. Khi trạng thái chụp chưa rõ do timeout, hệ thống kiểm tra ảnh và tác vụ đang chạy trước khi cho phép phát lệnh chụp mới.

   **Điều kiện hoàn thành** là số lần chụp đúng với số ô, các ảnh thực sự khác nhau, lỗi giữa chuỗi có thể phục hồi và mọi kiểm thử camera của chụp đơn vẫn đạt.

5. **Áp dụng hiệu ứng từng ảnh và ghép bằng PHP/GD**

   Tạo `C:\xampp\htdocs\photobooth\lib\multishot-render.php` để ghép thành phẩm. Tái sử dụng bộ làm đẹp và sticker hiện có.

   Các lựa chọn hiệu ứng được giữ trong cả phiên, nhưng khuôn mặt phải được nhận diện lại ở từng ảnh. Dữ liệu vị trí mặt và ảnh làm đẹp phải gắn với đúng ảnh nguồn. Phần hiệu ứng hiện có đang nhớ kết quả theo tên ảnh và giải phóng tài nguyên khi kết thúc, nên điểm tích hợp cần hỗ trợ xử lý nhiều ảnh trước khi kết thúc cả phiên. [Chuẩn bị hiệu ứng hiện tại](/C:/xampp/htdocs/photobooth/resources/js/filter-stage.js:441)

   Quy trình ghép gồm chuẩn hóa hướng ảnh, áp dụng hiệu ứng từng ảnh, cắt vào đúng ô, phủ PNG lên bản ghép và xuất JPEG. Các thao tác trang trí cấp thành phẩm được thực hiện một lần ở bước hoàn thiện.

   PHP xử lý lần lượt từng ảnh và giải phóng bộ nhớ sau mỗi lượt. Kích thước đầu ra lấy từ mẫu, có kiểm tra trước khi cấp phát. Giới hạn làm đẹp hiện có, gồm trần 16 MP và giới hạn texture của thiết bị, tiếp tục được kiểm tra cho từng ảnh nguồn.

   **Điều kiện hoàn thành** là ảnh thử có đánh số vào đúng ô, ảnh dọc/ngang và EXIF được xử lý đúng, khung giữ đúng tỷ lệ, sticker bám đúng khuôn mặt và bộ sáu ảnh hoàn thành trong giới hạn tài nguyên của booth.

6. **Bổ sung giao diện lựa chọn chức năng và tiến độ nhiều ảnh**

   Bổ sung lựa chọn **Chụp nhiều ảnh** vào Modern và Modern Squared, hai giao diện đang dùng chung template. Chức năng mới có bộ chọn khung riêng, sau đó mở màn hình camera và hiệu ứng dùng chung.

   Các tệp mới dự kiến gồm

   - `C:\xampp\htdocs\photobooth\template\multishot-stage.template.php`.
   - `C:\xampp\htdocs\photobooth\resources\js\multishot.js`.
   - `C:\xampp\htdocs\photobooth\resources\css\multishot.css`.

   Màn hình chụp hiển thị **Ảnh 2/4**, vùng camera đúng tỷ lệ ô hiện tại và bản ghép thu nhỏ với các ảnh đã hoàn thành. Các ô chưa chụp được thể hiện rõ. Khi xử lý ảnh, giao diện thông báo trạng thái trước khi bắt đầu đếm ngược tiếp.

   Các điểm nối trong `filter-stage.js` và `core.js` xác định chức năng đang hoạt động, chuyển yêu cầu chụp tới đúng bộ điều phối và chỉ kết thúc hiệu ứng khi cả bộ hoàn tất. Nút bấm lặp, phím chụp và nút ngoài đều tuân theo trạng thái đang bận.

   Sau khi hoàn tất, màn hình kết quả có hành động **Chụp bộ mới**. Trở về chụp đơn khôi phục đúng ngữ cảnh của chụp đơn. CSS mới được giới hạn trong vùng giao diện nhiều ảnh.

   **Điều kiện hoàn thành** là chuyển qua lại giữa hai chức năng không nhầm khung, không giữ hiệu ứng của phiên trước và không khởi động hai chuỗi đồng thời. Kiểm tra thêm bố cục dọc trên điện thoại, bàn phím, focus và thông báo tiến độ cho trình đọc màn hình.

7. **Lưu thành phẩm và nối vào QR, thư viện, xóa ảnh**

   Máy chủ tạo một JPEG thành phẩm và một thumbnail. Khi cả hai đã ghi hoàn chỉnh, hệ thống ghi nhận ảnh vào danh sách ảnh rồi mới trả trạng thái hoàn tất.

   Tên thành phẩm được cố định theo phiên. Nếu ghép xong nhưng mất phản hồi, yêu cầu gửi lại trả đúng tên đó. Phần phục hồi cần kiểm tra từng điểm có thể gián đoạn giữa ghi ảnh, ghi thumbnail, cập nhật danh sách và đánh dấu hoàn tất.

   QR tiếp tục dùng địa chỉ nhận ảnh theo tên tệp. Ảnh ghép có thể đi qua trang nhận ảnh, tải xuống và thư viện hiện tại. [Địa chỉ nhận ảnh](/C:/xampp/htdocs/photobooth/lib/photo-receipt.php:12) · [Ghi nhận ảnh vào danh sách](/C:/xampp/htdocs/photobooth/lib/db.php:46)

   Quan hệ giữa thành phẩm và các ảnh nguồn phải được lưu để thực hiện đúng các thao tác sau.

   | Tình huống | Hành vi cần có |
   |---|---|
   | Hoàn tất và bật giữ ảnh gốc | Giữ các ảnh nguồn cùng thông tin liên kết |
   | Hoàn tất và tắt giữ ảnh gốc | Dọn ảnh nguồn sau khi xác nhận thành phẩm đã lưu thành công |
   | Hủy hoặc lỗi giữa chuỗi | Dọn ảnh trung gian theo trạng thái, áp dụng chính sách giữ ảnh gốc |
   | Xóa thành phẩm | Xóa ảnh, thumbnail và cache, xử lý ảnh nguồn theo chính sách hiện hành |
   | Admin reset có chọn xóa ảnh | Xử lý cả dữ liệu phiên mới theo phạm vi reset |
   | Gửi lại yêu cầu sau khi đã xóa | Trả trạng thái đã xóa, tránh tạo lại thành phẩm |

   Cần bổ sung xử lý dữ liệu phiên mới vào phần xóa và reset. Hiện hàm xóa dựa vào các tệp cùng tên, còn reset quét JPEG ở từng thư mục. [Xóa ảnh hiện tại](/C:/xampp/htdocs/photobooth/lib/photo-delete.php:5) · [Reset hiện tại](/C:/xampp/htdocs/photobooth/api/admin.php:175)

   Việc nhận lại trạng thái hoàn tất cũng phải tránh kích hoạt lặp in tự động hoặc lệnh sau chụp.

   **Điều kiện hoàn thành** là một phiên tạo đúng một mục trong thư viện, QR mở đúng bản ghép trên điện thoại và các trường hợp giữ/xóa ảnh nguồn đều đúng chính sách.

8. **Kiểm thử chức năng mới và hồi quy toàn bộ phần dùng chung**

   Bổ sung các bài kiểm thử vào

   - `C:\xampp\htdocs\photobooth\scripts\test-multishot.php` cho danh mục, phiên, xử lý ảnh và lưu kết quả.
   - `C:\xampp\htdocs\photobooth\scripts\test-multishot.cjs` cho điều phối và các chuyển trạng thái.
   - `C:\xampp\htdocs\photobooth\scripts\test-multishot-browser.mjs` cho luồng giao diện, camera giả, hiệu ứng và nhận ảnh.

   Các bài mới dùng lại cách tạo môi trường thử nghiệm của dự án. Đăng ký chúng vào bộ chạy hiện có để `npm test` và `npm run test:browser` kiểm tra cả hai chức năng.

   | Nhóm kiểm tra | Kết quả bắt buộc |
   |---|---|
   | Năm bố cục | Đúng số lần chụp, đúng thứ tự ô và kích thước thành phẩm |
   | Chụp đơn | Các bài kiểm thử cũ tiếp tục đạt với các xác nhận hiện có |
   | Mất mạng và gửi lại | Phục hồi đúng trạng thái, một thành phẩm cho mỗi phiên |
   | Lỗi ở ảnh giữa chuỗi | Giữ các ảnh đã thành công, thử lại đúng ô |
   | Hai tab hoặc hai phiên | Khung, ảnh và kết quả thuộc đúng phiên |
   | Hiệu ứng | Nhận diện lại từng ảnh, kiểm tra lật, EXIF, nhiều khuôn mặt và lỗi GPU |
   | Dữ liệu đầu vào sai | Từ chối khung sai, ô sai, ảnh ngoài phiên và dữ liệu quá giới hạn |
   | Xóa và reset | Đúng cả hai giá trị của cấu hình giữ ảnh gốc |
   | Cấu hình giao diện | Sáng/tối, hướng dẫn, thư viện, quyền tải/xóa/in/email vẫn được tôn trọng |
   | QR thực tế | Điện thoại mở được địa chỉ của booth và tải đúng JPEG ghép |

   **Điều kiện hoàn thành** là các bài cũ và mới đều đạt. Máy ảnh ngoài, tốc độ xử lý bộ sáu ảnh và truy cập QR qua mạng thực tế cần được kiểm tra trên thiết bị của booth.

9. **Đưa vào bản XAMPP và chuẩn bị quay lại phiên bản trước**

   Khi triển khai, sao lưu những tệp mã nguồn sẽ thay đổi và ghi nhận trạng thái dữ liệu tại booth. Đưa các tệp mới cùng các điểm nối đã kiểm thử vào đúng bản XAMPP.

   Phạm vi tệp cũ dự kiến cần chạm tới gồm trang nạp giao diện, template Modern, bộ điều phối chụp, màn hình hiệu ứng, thư viện capture, các điểm điều khiển camera, xử lý xóa/reset và bộ chạy kiểm thử. Từng thay đổi phải có lý do gắn với một bước ở trên.

   Kiểm tra tại chỗ một lượt chụp đơn, một bộ 1×4 và một bộ 2×3, gồm cả nhận ảnh bằng điện thoại. Đối chiếu lại cấu hình riêng và kho khung chụp đơn với mốc ban đầu.

   Phương án quay lại dùng bản sao mã nguồn trước triển khai. Những JPEG thành phẩm đã tạo vẫn là ảnh bình thường trong kho và tiếp tục dùng được với cơ chế nhận ảnh qua QR.

Các lựa chọn tôi chủ động đưa vào kế hoạch gồm chụp tự động tuần tự, năm bố cục, kho khung nhiều ảnh riêng, tích hợp vào Modern/Modern Squared, ghép bằng PHP/GD và một JPEG thành phẩm cho mỗi phiên.

Kế hoạch được lập từ việc đọc source. Phần triển khai và các kiểm thử trong kế hoạch chưa được thực hiện.

Tốc độ máy ảnh ngoài, độ khớp của preview với ảnh chụp thật và mức dùng bộ nhớ khi ghép sáu ảnh cần được đo trong giai đoạn triển khai.
