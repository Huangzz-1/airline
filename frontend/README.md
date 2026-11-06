# Frontend

Đây là mã nguồn frontend dùng chung của Airline Smart:

- `app.css`: hệ thống giao diện, responsive layout và component styles.
- `app.js`: tương tác dùng chung, tìm chuyến bay, bộ lọc, sidebar và trạng thái form.

Laravel vẫn render Blade từ `backend/resources/views`. Lệnh `npm run assets` đồng bộ các tài sản trong thư mục này sang `backend/public`, nơi web server phục vụ chúng tại `/app.css`, `/app.js` và `/bootstrap.min.css`.

Không chỉnh sửa trực tiếp bản runtime trong `backend/public`; hãy sửa file trong thư mục này rồi chạy lại:

```bash
npm run assets
```
