# Kiểm chứng thực thi

## Mốc min/max toàn hệ thống — 03/11/2026

- `GlobalFlightRangesTest`, `FlightRangeFiltersTest`, `FlightSearchTest`: **14 tests / 121 assertions passed** (78,1 giây). Oracle độc lập kiểm tra tổng giá cùng hạng, hành trình 1–3 chuyến thành phần, giới hạn 2 điểm dừng, phạm vi mọi tuyến/ngày/hãng/hạng, loại lịch quá khứ/hủy, làm mới khi giá/lịch thay đổi và không nới miền theo URL ngoài dữ liệu.
- `flight-ranges.spec.js` và `flight-search.spec.js`: **6 passed** (36,0 giây), gồm kiểm tra min/max giữ nguyên khi đổi tuyến/ngày/hãng/hạng, kể cả không có kết quả; kéo hai đầu/dịch vùng chọn, bàn phím, URL/reset, mobile, lỗi tải và Chọn.
- Đối chiếu chỉ đọc trên main với 3 bộ điều kiện khác nhau: giá **280.000–9.780.000 VND**, hành trình **40–840 phút**, quá cảnh **0–590 phút** đều giữ nguyên. Tổng kết quả lần lượt 4, 1 và 0; phản hồi khoảng 0,7–1,4 giây. Mốc lấy từ tất cả lịch đã công bố chưa khởi hành, không lấy lịch quá khứ/nháp/hủy.
- Không tạo migration, không thay dữ liệu vé/lịch/thanh toán; dữ liệu tạm dùng khóa theo nội dung lịch/giá để làm mới đúng khi nguồn thay đổi. Các ghi chép miền theo tuyến ở đợt trước là hành vi cũ.

## Một thanh chung cho mỗi khoảng — 03/11/2026

- Thay phần tương tác bằng một rail chung với hai nút min/max, vùng chọn xanh và vùng ngoài xám. Các giá trị phía trên đổi khi kéo; kết quả đổi khi thả, tránh mất nút đang giữ do AJAX.
- Chạy `flight-ranges.spec.js` và `flight-search.spec.js`: **5 passed** (31,5 giây), gồm kéo thực cả hai đầu, giữ nút trên 350 ms mà DOM không bị thay, dịch cả khoảng giữ độ rộng, bàn phím, clamp, URL/reset, mobile, phân trang và luồng Chọn. Đã xem ảnh cận cảnh thanh giá.

## Khoảng giá, quá cảnh và hành trình — 03/11/2026

- `FlightRangeFiltersTest`, `FlightSearchTest`, `MultiAirlineFlightsTest`: **15 tests / 715 assertions passed** (83,1 giây). Kiểm tra hai đầu bao gồm, giá khớp cùng hạng, hành trình gồm thời gian chờ, tổng quá cảnh nhiều điểm, bay thẳng 0 phút, giới hạn đảo/ngược/không nguyên bị từ chối, miền kéo giữ nguyên khi kết quả rỗng và khoảng cố định.
- `flight-ranges.spec.js` và `flight-search.spec.js`: **5 passed** (31,5 giây). Có kéo thumb bằng chuột, dùng Home/End, chặn hai đầu vượt nhau, ghép giá/thời gian, giữ URL/tải lại/đặt lại, mobile 390 px, bay thẳng không quá cảnh, sắp xếp/phân trang, phản hồi chậm và khóa Chọn khi tải lỗi. Đã xem ảnh desktop/mobile.
- Truy vấn chỉ đọc trên main: VN/HAN → SGN ngày 04/11 có 4 lịch trình; `layover_max=0` còn 2 bay thẳng; `duration_min=200&duration_max=300` còn 1 lịch trình. Không thêm migration hay sửa dữ liệu chuyến/vé/thanh toán cho thay đổi này.
- Chi tiết định nghĩa và API: [bộ lọc tìm chuyến](flight-search.md). Miền range tính theo dữ liệu; không hard-code các giá trị trong ảnh tham khảo.

## Chuyến bay nhiều hãng — 03/11/2026

- `MultiAirlineFlightsTest`, `FlightSearchTest`, `TripBookingFlowTest`: **18 tests / 778 assertions passed** (28,2 giây), kiểm tra lịch tàu không giao nhau, quay đầu, cabin/tồn, bảo toàn giá/vé khi chạy lại, lọc hãng và nối chuyến, preview, một người và khứ hồi.
- `multi-airline.spec.js`: **2 passed** (11,1 giây), thao tác bộ lọc hãng/số điểm dừng với số lượng thật, hành trình qua DAD/CXR, preview và trang thông tin; khứ hồi VN/VJ trên màn hình 390 px không tràn ngang. Đã xem ảnh desktop/mobile.
- Hồi quy `flight-search.spec.js` và `reservations.spec.js`: **7 passed** (36,7 giây), kiểm tra lọc/sắp xếp/phân trang, đổi hạng giá, lỗi tải, một người, khứ hồi nối chuyến thanh toán chung, draft qua đăng nhập. Tổng 9 kịch bản trình duyệt mới/hồi quy đều qua.
- Main đã thêm **1.008** chuyến (04–17/11), nhập lại: **0 tạo mới / 1.008 giữ nguyên**. Truy vấn trực tiếp giao diện HAN → SGN ngày 04/11, riêng VN: **2 bay thẳng / 1 một điểm dừng / 1 hai điểm dừng**, phản hồi khoảng 0,8 giây. Thao tác đọc không tạo đặt chỗ/thanh toán trên main.
- Đếm lại main: mỗi hãng **252 chuyến**, **16 tàu**, **1.008 dòng tồn**, **2.520 hạng giá**; health cả ba nút trả `ok`.
- Đã sao lưu ba nút trước migration `flight_carriers`; bộ test mutation dùng ba DB `airline_test` riêng. Giá/lịch/điều kiện là fixture, xem [phạm vi dữ liệu](multi-airline-demo.md).

## Tám nhóm nghiệp vụ — 03/11/2026

- Toàn bộ backend đã qua **162 tests / 1.514 assertions**. Sau đó bổ sung ca tính phí đổi + hành lý trên vé thay thế, kiểm tra hoàn đúng số tiền và endpoint tiếp tục phương án đã duyệt; hai ca liên quan qua **52 assertions**.
- Kiểm tra cuối `BusinessFeaturesTest`: **14 tests / 242 assertions passed** (73,2 giây), bao gồm hạn hỗ trợ nhập giờ Việt Nam được lưu UTC và từ chối hạn đã qua. Pint và biên dịch Blade đều qua.
- `business.spec.js`: **5 passed** (47,9 giây), thực hiện sửa thông tin trước/sau xuất vé, CS duyệt, mua hành lý, sao kê xác nhận thu, finance lập/duyệt điều chỉnh độc lập, báo cáo và CSV, khóa/mở check-in, quét boarding, chốt/xuất danh sách, phân công/quá hạn/giải quyết hỗ trợ, đổi điều kiện vé, đề xuất/duyệt gián đoạn và khách tiếp tục phương án. Kiểm tra form sửa thông tin ở 390 px không tràn chiều ngang.
- Chạy hồi quy `reservations.spec.js`: **4 passed** (19,2 giây), giữ luồng một người, bay thẳng/nối chuyến, khứ hồi, hộp xem trước và bản nháp qua đăng nhập.
- `journey.spec.js`: **2 passed** (14,3 giây), từ đăng ký/OTP đến chuyển khoản, check-in, boarding và chặn QR dùng lại. Sửa màn hình quét giữ chuyến đã chọn sau mỗi lần thành công; fixture dùng mã giao dịch riêng và chọn ghế còn trống để chạy lại được. Chạy lại riêng luồng CS sau sửa múi giờ: **1 passed** (13,6 giây). Tổng các kịch bản trình duyệt khác nhau ở đợt này: **11**.
- Đã khởi động lại worker nghiệp vụ và email trên local chính; `/health/ready` trả `ok` với cả ba nút. Dịch vụ E2E đã dừng sau kiểm tra.
- Ba database chính đã sao lưu trước migration bổ sung. Các bài mutation trình duyệt/backend dùng ba database và tài khoản `airline_test`, mock hoặc tài khoản ngân hàng giả; không xác nhận thu/chi trên dữ liệu ngân hàng thật.
- Phạm vi, giới hạn và cách dùng: [8 nhóm nghiệp vụ](eight-business-features.md). Số lượng và quy trình nhiều hành khách ở các ghi chép kiểm thử cũ bên dưới thuộc phạm vi trước đây.

## Một chiều / khứ hồi và thông tin hành khách — 03/11/2026

- Bộ kiểm tra liên quan `TripBookingFlowTest`, `FlightSearchTest`, `PlanSearchTest`, `ReservationTest`, `BankTransferQrCheckoutTest`: **38 tests / 400 assertions passed** (3 phút 03,7 giây). Chạy riêng lại `TripBookingFlowTest` sau hoàn thiện bản nháp/giấy tờ và ID lịch trình nối chuyến: **6 tests / 95 assertions passed**.
- `reservations.spec.js` đã thay các bài thêm/bớt nhiều chặng bằng luồng mới: **3 tests passed** (17,3 giây), kiểm tra lọc lịch trình 0/1/2 điểm dừng, nhãn vàng, khứ hồi hai lượt có nối chuyến và hai khách, 8 vé/1 thanh toán, SMTP tới email liên hệ; một chiều trên điện thoại và giữ bản nháp qua đăng nhập.
- `flight-search.spec.js`: **3 passed** (19,8 giây), gồm bộ lọc/sắp xếp/phân trang, phản hồi chậm, lỗi tải, giá theo hạng và chuyển qua trang thông tin. `journey.spec.js`: **2 passed** (10,9 giây), gồm OTP/email, chuyển khoản đối chiếu bởi nhân viên tài chính, check-in/boarding và responsive. Các lượt này dùng schema/user `airline_test`, cổng 18081.
- `local-services.spec.js`: **3 passed** (17,4 giây), gồm reset mật khẩu qua SMTP và VNPay/MoMo Mock đi qua bước thông tin mới rồi xuất vé, ghi sổ thu, gửi email. Tổng 11 bài trình duyệt ở các lượt trên. Giới hạn đăng nhập và gửi thông tin chuyến có prefix riêng để các thao tác khác không tiêu hao nhầm cùng bộ đếm.
- QR, chuyển khoản và giao dịch mô phỏng trong kiểm thử không tạo chuyển tiền thật. Main DB2 đã sao lưu trước migration thêm trường nullable; dữ liệu đặt chỗ cũ được giữ nguyên. Đã xem luồng chọn khứ hồi và trang hành khách trên main cổng 8000 ở desktop/390 px bằng thao tác chỉ đọc, không gửi tạo đặt chỗ/thanh toán.

Mô tả hiện tại: [Đặt chuyến đi](trip-booking.md). Các bài kiểm thử nhiều chặng được ghi ở những lượt lịch sử bên dưới thuộc phạm vi cũ, không đại diện cho giao diện đặt mới.

## Bộ lọc tìm chuyến — 03/11/2026

- `FlightSearchTest` và `PlanSearchTest`: **10 tests / 80 assertions passed** trong 43,5 giây. Kiểm tra lọc/sắp xếp trước phân trang, ngày/giờ Việt Nam, đến qua ngày, nhiều khoảng giờ, điều kiện giá khớp cùng hạng, quota/cabin/tồn chung, giữ chỗ hết hạn, đóng bán/đổi tàu, fallback phổ thông và hạng ngừng bán, fragment và validation.
- `flight-search.spec.js`: **3 Playwright tests passed**, 21,2 giây. Gồm phân trang và URL, lọc kết hợp, kéo mức giá, tải lại, Back/Forward, bỏ kết quả cũ trả về muộn, bộ lọc điện thoại, giữ hạng/số khách sang PNR, giá thực tế khi đặt và thử lại sau lỗi tải.
- Chạy lại `reservations.spec.js`: **4 tests passed**, 40,3 giây, bao gồm sân bay trống/lọc điểm đến, tự đồng bộ hành trình, khứ hồi thanh toán/check-in/boarding và nhiều chặng trên điện thoại.
- Browser dùng cổng **18081** và ba schema/user `airline_test`; đã dừng dịch vụ test. Cổng 8001 đang phục vụ dự án khác và được giữ nguyên. Đã kiểm tra chỉ đọc trên ứng dụng chính cổng 8000 ở desktop 1440 px và điện thoại 390 px: không tràn ngang, không có lỗi JavaScript. Không chạy lại toàn bộ PHPUnit trong lượt sửa giao diện này.

Phạm vi dữ liệu/bộ lọc: [Trang tìm chuyến](flight-search.md). Không kết nối kho vé, hãng hay chương trình khuyến mãi của Traveloka.

## Bổ sung PNR/cabin — 02–03/11/2026

- Lượt PHPUnit cuối ngày 03/11: **114 tests / 1031 assertions passed**, 5 phút 45.8 giây, trên ba MySQL local với schema/user `airline_test` riêng. Bao gồm bộ kiểm thử cũ và các lớp `ReservationTest`, `CabinConfigurationTest`, `CabinLayoutTest` mới.
- Các kiểm thử đồng thời dùng tiến trình PHP riêng: một nhóm thắng khi tranh các ghế cuối trên hai chặng; cùng khóa yêu cầu với hai hành trình khác nhau chỉ tạo một PNR. Các khóa inventory được lấy trước khóa tài khoản để thống nhất thứ tự với vé đơn.
- **2 Playwright tests passed**, Chrome hệ thống, 15.5 giây: khứ hồi 2 khách/4 vé/1 intent, tên và QR riêng, check-in đúng khoang, nhân viên tra cứu PNR và boarding; nhiều chặng trên điện thoại 390×844, thêm/bớt chặng, tổng tiền và MoMo decline cho toàn bộ vé.
- Kiểm thử mới bao gồm rollback toàn bộ khi thiếu chỗ/quota một chặng, ownership, chống thanh toán riêng qua vé thành phần, TTL, callback replay, đối soát các khoản phân bổ, hoàn riêng một vé, thay tàu remap đúng cabin và từ chối khi thiếu ghế thương gia dù tổng ghế còn đủ.
- Đổi vé kiểm tra thời gian chặng trước/sau và người đi khi đề nghị; phê duyệt kiểm tra lại khi lịch chuyến khác đã đổi. Cấu hình cabin qua HTTP cần người duyệt độc lập và không ghi đè snapshot của chuyến đã công bố.
- Đã sao lưu ba DB ứng dụng trước migration; migration thêm bảng/cột nullable, giữ vé và sơ đồ cũ. Các dịch vụ E2E dùng cổng 8001 đã dừng sau chạy; app/worker local cổng 8000 tiếp tục hoạt động.

Các số kiểm thử bên dưới là baseline 30/10, không cộng dồn các lượt chạy lặp. Xem [phạm vi và cách dùng PNR/cabin](reservations-and-cabins.md).

## Baseline kiểm tra ngày 30/10/2026

- **88 PHP tests passed, 780 assertions**, 153.55 giây, không skipped/disabled: 78 integration tests trên ba MySQL instance và 10 trường hợp biên SeatMap.
- **11 browser tests passed**, 1.9 phút, Chromium hệ thống, CSRF thật. Giao diện không còn banner mô phỏng, số module hoặc trường nhập UUID trong thao tác thông thường.
- PHP lint, Blade compile và PHP lint view đã biên dịch, Pint, cú pháp JavaScript, bash -n, Compose config và git diff --check đã qua.
- Health HTTP 200/status=ok. App, worker nghiệp vụ, worker email và ba MySQL instance đang chạy; dịch vụ web/worker test được dừng và xóa.
- Backup/restore **49 bảng** khớp counts và tham chiếu inventory→flight; dữ liệu ứng dụng trước/sau restore không đổi.

## Kiểm tra chức năng và quyền

`JourneyTest` và `OperationsWorkflowTest`: topology/quyền DB test; signup không nâng quyền; xác minh signed URL, reset một lần; hold/expiry/cancel, hai tiến trình tranh ghế cuối; sandbox chỉ trong test, decline/network retry; check-in/QR/boarding, ownership/role/airport/time windows; lịch/crew/approval/batch, license/rest/conflict, master revision/CSV staging và rollback; MRO, tài nguyên, ground SLA, baggage/custody, đổi/hoàn, hủy trong lúc chờ thanh toán, reconciliation/lock kỳ/CSV; IoT trùng/trễ và KPI; hỗ trợ append-only và trang workspace theo vai trò.

`FlightResourceChangeTest` (12): đổi tàu prepare/commit/ack trên node thật, giữ hai tàu/freeze tồn, remap/cấp QR mới, thiếu chỗ giữ nguyên phương án cũ, phân quyền/stale/reject, chặn MRO/sửa cấu hình/publish xung đột, replay không xoay QR lần hai, đóng bán thủ công, DB1/DB2 mất TCP và khôi phục, expiry không chặn relay, thay tổ bay/lịch sử inactive, giữ ghế hợp lệ trước remap, payment pending và phương án hủy không ghi đè swap.

`LocalServicesTest` (6): OTP giới hạn thử/hạn/resend/mã cũ, không lộ mã qua trang xác minh; Mock VNPay ownership/server amount/kết quả ký/idempotency; MoMo decline và capture; production từ chối mock; bootstrap không ghi đè admin hoặc chạy production.

`UsablePortalTest` (7): chứng từ chỉ cho chủ/agent đúng sân bay và vé hợp lệ; không in QR thu hồi; tra cứu/làm thủ tục theo sân bay và audit nhân viên thực; chọn mã đặt chỗ của chính mình, giao diện dùng tên nghiệp vụ; admin tạo nhân viên, bắt đổi mật khẩu, khóa/mở và thu hồi truy cập, không tự khóa; đăng ký vẫn xếp mail được mã hóa khi SMTP chưa truy cập được; đánh dấu thông báo tại chỗ có ownership.

`PaymentGatewayTest` (9): simulator bị chặn khi tắt, merchant chưa cấu hình không làm đổi booking; báo chuyển khoản không xuất vé, tài chính xác nhận theo sao kê, idempotency/mã chuyển khoản không dùng hai lần; VNPay ký đúng query và kiểm tra IPN/amount, return không capture; MoMo ký yêu cầu và kiểm tra IPN/amount/replay; timeout giữ pending và dùng cùng order ID; callback thành công vẫn được ghi nhận khi phản hồi tạo thanh toán bị mất; đổi vé thật chờ khách trả vé mới, hoàn vé cũ chờ chi; hủy chuyến không ghi hoàn thật tự động; tài khoản nhận hoàn được mã hóa, phải duyệt và người khác xác nhận chi.

Các lần mô phỏng mất TCP dùng cổng local không lắng nghe rồi khôi phục cấu hình đến DB thật, không giả lập DB thành công. Provider HTTP và IPN dùng **khóa/payload kiểm thử**, MoMo dùng Http::fake, không phát sinh giao dịch thật. SMTP Mailpit đã gửi/nhận thực trên Docker network; browser đọc OTP, reset link và thông báo từ hộp thư local. Chưa dùng SMTP/merchant thật, đúng phạm vi kiểm thử local được yêu cầu. Cần nghiệm thu merchant/SMTP theo deployment.md khi có tài khoản của đơn vị triển khai.

## Browser coverage

Lượt cập nhật bộ chọn sân bay: 4 bài `reservations.spec.js` đã qua trong 40,9 giây, gồm kiểm tra điểm đi/đến trống khi mở mới, khóa điểm đến trước khi chọn điểm đi, lọc đúng chiều đường bay đang hoạt động trên cả trang tìm chuyến và `/plan`, loại đường bay ngừng hoạt động, xóa điểm đến không còn hợp lệ, chặng mới để trống và giữ liên kết đặt từ chuyến đã chọn. Ba bài `PlanSearchTest` cũng qua với 22 assertions. Đã kiểm tra thêm hai trang trên cổng 8000, không tạo đặt chỗ trong dữ liệu chính. Không chạy lại toàn bộ PHPUnit trong lượt sửa này.

Kiểm tra riêng cập nhật hành trình `/plan`: 3 bài trong `reservations.spec.js` đã qua (28,8 giây) trên ba schema `airline_test`. Bao gồm đổi ngày lấy đúng danh sách chuyến, thêm/bớt chặng, chuyển loại hành trình, đổi số khách và tính lại tổng tiền, giữ tên khách, bỏ lựa chọn không còn khớp; kết quả cũ trả về chậm không ghi đè bộ lọc mới, lỗi mạng khóa giữ chỗ và có thể tải lại. Hai bài còn lại kiểm tra đặt nhóm khứ hồi/thanh toán/check-in/boarding và nhiều chặng trên điện thoại. Đây là lượt kiểm tra riêng giao diện; không chạy lại toàn bộ PHPUnit trong lượt sửa này.

1. Signup → nhận OTP từ email SMTP Mailpit và nhập trên UI → giữ chỗ → chuyển khoản → khách báo giao dịch → finance đăng nhập độc lập, đối chiếu và xác nhận → vé xuất → chọn ghế → QR → agent boarding; quét lặp bị từ chối; thiếu CSRF bị 419. Tài khoản ngân hàng trong DB test được ghi rõ là kiểm thử.
2. Mobile 390×844 không tràn ngang; lọc không có chuyến có empty state.
3. Fleet thêm tàu → operations lập nháp → crew thêm và phân công pilot/cabin → trình duyệt → ops_manager công bố → inventory thực và chuyến xuất hiện công khai.
4. Staff chọn mã đặt chỗ để tag/scan, Accepted→Screened→Loaded; custody 4 sự kiện; khách tra cứu đúng bag của mình.
5. Khách gửi yêu cầu → CSKH phản hồi/resolved → khách đọc lịch sử đầy đủ.
6. Quên mật khẩu nhận email SMTP qua Mailpit và dùng reset link đúng một lần.
7. VNPay Mock đi hết thanh toán, khoản thu, vé và email thông báo qua Mailpit.
8. MoMo Mock đi hết thanh toán, khoản thu, vé và email thông báo qua Mailpit.
9. Admin tạo nhân viên qua form → đăng nhập lần đầu bắt đổi mật khẩu → bàn làm việc đúng vai trò, không có thao tác quản trị ngoài quyền.
10. Agent tra cứu mã đặt chỗ → chọn ghế → làm thủ tục → in thẻ QR, tạo PDF bằng chế độ print của trình duyệt.
11. Operations đề nghị đổi tàu 18→12 → manager duyệt → worker thật hoàn tất → remap 3F→1A → khách nhận QR mới, agent từ chối mã cũ và nhận mã mới.

E2E dùng web port 8001, APP_ENV=local, user/schema airline_test, worker nghiệp vụ và worker mail riêng. Helper chỉ tạo tài khoản trong DB test; không có endpoint public để bỏ xác minh hoặc cấp admin. Simulator cũ chỉ dùng fixture ngoài luồng trình duyệt; luồng khách kiểm tra chuyển khoản và VNPay/MoMo Mock bằng giao diện, không gọi cổng ngoài. Các lượt mới đọc OTP/reset qua SMTP thực, không tạo signed URL bằng helper để bỏ qua email. Camera phụ thuộc BarcodeDetector/HTTPS/quyền thiết bị; chưa có bài kiểm tra camera vật lý.

Ảnh/PDF thật từ browser: [bàn làm việc](screenshots/staff-dashboard.png), [phục vụ hành khách](screenshots/passenger-desk.png), [thẻ lên tàu](screenshots/boarding-pass.png), [PDF](screenshots/boarding-pass.pdf), [đổi tàu](screenshots/aircraft-change.png).

## Dữ liệu ứng dụng và sao lưu

Fixture onboarding cũ được sao lưu trước khi dọn. Công cụ cleanup chỉ chạy local, đòi hỏi không có users/bookings/giao dịch/audit/outbox hoặc thay đổi nghiệp vụ và kiểm tra danh tính fixture. Đã xóa 7 chuyến/inventory, 2 tàu, 2 nhân sự, 1 lệnh và 1 thiết bị mẫu; giữ 3 sân bay, 2 đường bay, 12 tài nguyên nền. Không xóa dữ liệu người dùng. Seed mẫu mặc định tắt; production luôn không seed mẫu. Sau đó bootstrap đã tạo quản trị viên local duy nhất từ credential ngẫu nhiên trong backend/.env; mật khẩu bắt đổi lần đầu, không được in ra hoặc đóng gói. Test bật fixture ở tiến trình test.

Sau cleanup, backup ba node được phục hồi bằng user hạn chế vào **airline_test**. Counts cả 49 bảng và tham chiếu inventory→flight trùng baseline, main trước/sau không đổi: **1 admin local, 0 bookings, 0 flights, 14 migrations**. Các bảng thanh toán/đổi tàu trong main đang trống, nên lần restore này xác minh schema/counts/invariant, không chứng minh khôi phục Saga đang chạy hoặc mọi giá trị bit-for-bit. Không globally atomic, chưa là nghiệm thu DR/RTO/RPO. APP_KEY phải giữ riêng; backup không nằm trong Git/gói mã nguồn.

Ba server UUID khác nhau: DB1 c90469db-d438-11f1-9c29-16e2073315b4; DB2 c8efa380-d438-11f1-8395-327815a736bb; DB3 c90ec26e-d438-11f1-a42c-16356d1c0b01. Test chỉ yêu cầu độc lập, không hardcode các ID.

Hướng dẫn startup môi trường đã lưu bản nháp, chưa Publish snapshot. Các giới hạn nghiệp vụ/triển khai được ghi trong implementation-progress.md và deployment.md; số kiểm thử không thay thế nghiệm thu hàng không hoặc xác minh kết nối nhà cung cấp thật.

## Setup và cấu hình local

Đã chạy lại scripts/setup.sh thành công sau khi thêm Mailpit/Mock/OTP. Byte hash của backend/.env trước/sau giữ nguyên, credential và APP_KEY không đổi; admin có sẵn không bị đặt lại mật khẩu. Đã kiểm tra migration root .env cũ sang symlink backend/.env bằng thư mục tạm, giữ credential Compose đang có hiệu lực và không ghi đè sau chạy lần hai.

Mailpit v1.32.0 được pin bằng image digest, nhận SMTP local thực; cả VNPay, MoMo và BANK ở chế độ thử nghiệm đã sẵn sàng. Các khoản checkout/payment có trường mode. Không có giao dịch thật. Bản local dùng ba MySQL instance cho nghiệp vụ, Mailpit chỉ là hộp thư thử nghiệm phụ trợ.

Sau thay đổi nhãn chứng từ, hai E2E portal được chạy lại và qua (13.2 giây); ảnh/PDF đã tạo lại.
