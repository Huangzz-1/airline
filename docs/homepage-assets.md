# Trang chủ và ảnh banner

Trang `/home` là trang chào mừng mặc định sau khi khách hàng đăng nhập. Đăng nhập hoặc xác minh bằng OTP/link đều về `/home`, kể cả session còn lưu trang hành trình cũ. Chỉ quay lại `/trip/passengers` khi có bản nháp đặt vé thực sự; nhân viên vẫn vào `/staff`. Logo và mục **Trang chủ** dẫn tới `/home`, còn **Đặt chuyến đi** dẫn tới trang tìm chuyến hiện có `/`.

Ảnh dùng trong giao diện: `backend/public/images/aviation-home-hero.png`, tạo bằng công cụ imagegen tích hợp (không dùng API/CLI dự phòng). Ảnh là hình minh họa do AI tạo, không phải ảnh chứng minh đội tàu hoặc nhà ga cụ thể. Lưu trong dự án, không tải ảnh bên ngoài khi khách mở trang.

Prompt đã dùng:

> Create a premium photorealistic aviation website hero photograph, very wide landscape composition approximately 16:7. A modern narrow-body commercial passenger jet with a clean white fuselage and an unmarked deep teal tail, parked on an airport apron in beautiful early morning light. Main aircraft on the RIGHT half, side three-quarter front view, physically realistic wings, engines and landing gear. Terminal glass and distant mountains subtly visible behind it, pale blue sky with warm sun on the horizon, elegant calm travel atmosphere. LEFT 45 percent mainly quiet blue sky and distant apron, low visual detail suitable for dark navy gradient and white website heading overlay. Realistic editorial photography, crisp natural textures, sophisticated navy and teal palette, subtle warm golden accents. No text, no lettering, no logos, no watermark, no fictional typography. Deliver as one finished image for a real airline web application, not a website mockup.

Số sân bay, đường bay đang hoạt động và chuyến sắp khởi hành lấy từ DB1. Chuyến đi sắp tới và thông báo chỉ lấy của tài khoản đang đăng nhập; không hiển thị dữ liệu cá nhân của tài khoản khác. Form tìm chuyến dùng mạng điểm đến hiện có và chuyển tới luồng chọn chuyến/nhập hành khách/thanh toán.

Kiểm tra ngày 03/11/2026: PHPUnit `HomePageTest`, `TripBookingFlowTest` và kiểm tra đặt lại mật khẩu/đăng nhập đạt 10 bài, 134 assertions. Playwright `home.spec.js` và `local-services.spec.js` đạt 5 bài trên ba database `airline_test` riêng, gồm trang chủ sau đăng nhập, chọn điểm đến/ngày về, tìm chuyến, FAQ trên điện thoại và luồng thanh toán mock hiện có. Đã mở trang local cổng 8000 ở độ rộng 360, 390, 768, 1024 và 1440 px: ảnh tải thành công, không lỗi JavaScript hoặc tràn ngang; lần kiểm tra này không tạo đặt chỗ/thanh toán trên dữ liệu chính.
