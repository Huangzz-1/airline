const {test,expect}=require('@playwright/test');
const {execFileSync}=require('node:child_process');
const {mail}=require('./helpers');
function php(code){
  return execFileSync('docker',['compose','-f','compose.yaml','-f','compose.test.yaml','exec','-T','e2e','php','-r',
    `require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if(config('database.connections.mysql.database')!=='airline_test') exit(99); ${code}`],{encoding:'utf8'}).trim();
}
test('Đăng ký, giữ chỗ, thanh toán, check-in và quét boarding',async({page,browser})=>{
  const email=`e2e-${Date.now()}@example.test`;const password='BrowserPassword2026!';
  await page.goto('/register');await page.getByLabel('Họ và tên',{exact:true}).fill('Hành khách E2E');
  await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Mật khẩu',{exact:true}).fill(password);
  await page.getByLabel('Xác nhận mật khẩu',{exact:true}).fill(password);await page.locator('[name=consent]').check();
  await page.getByRole('button',{name:'Đăng ký hành khách'}).click();await expect(page.getByRole('heading',{name:'Xác minh email'})).toBeVisible();
  const verification=await mail(email,'Xác minh tài khoản');const code=verification.Text.match(/Mã xác minh của bạn:\s*(\d{6})/u)?.[1];
  expect(code).toMatch(/^\d{6}$/);await page.getByLabel('Mã xác minh',{exact:true}).fill(code);await page.getByRole('button',{name:'Xác minh tài khoản',exact:true}).click();await expect(page.getByRole('heading',{name:'Hành trình của tôi'})).toBeVisible();
  await page.goto('/');await page.getByRole('button',{name:'Giữ chỗ 10 phút'}).first().click();
  await expect(page.getByText('Chờ thanh toán',{exact:true})).toBeVisible();
  // A mutation with the session but without CSRF must be rejected by the real HTTP middleware.
  const csrf=await page.request.post('/api/v1/bookings',{data:{flight_id:1,idempotency_key:'missing-csrf-token'},headers:{Accept:'application/json'}});
  expect(csrf.status()).toBe(419);
  const checkoutBooking=page.url().split('/').pop();
  await page.getByLabel('Phương thức thanh toán',{exact:true}).selectOption('BANK');
  await page.getByRole('button',{name:/^Thanh toán /}).click();
  await page.getByLabel('Mã giao dịch chuyển khoản của bạn').fill('CUSTOMER-E2E-TRANSFER');
  await page.getByRole('button',{name:'Tôi đã chuyển khoản',exact:true}).click();
  await expect(page.getByText('Đã nhận thông tin chuyển khoản. Đang chờ tài chính xác nhận.')).toBeVisible();
  const financeEmail='payment-finance@example.test';
  php(`$u=App\\Models\\User::firstOrCreate(['email'=>'${financeEmail}'],['name'=>'Finance E2E','password'=>'${password}']);$u->forceFill(['role'=>'finance','email_verified_at'=>now()])->save();`);
  const bankContext=await browser.newContext();const bankPage=await bankContext.newPage();await bankPage.goto('/login');await bankPage.getByLabel('Email',{exact:true}).fill(financeEmail);await bankPage.getByLabel('Mật khẩu',{exact:true}).fill(password);await bankPage.getByRole('button',{name:'Đăng nhập',exact:true}).click();await bankPage.goto('/workspace/finance');
  const review=bankPage.locator('.transfer-review').filter({hasText:'CUSTOMER-E2E-TRANSFER'});
  await review.getByLabel('Mã giao dịch trên sao kê').fill('BANK-E2E-SETTLEMENT');await review.getByRole('button',{name:'Xác nhận đã nhận tiền',exact:true}).click();await expect(bankPage.getByRole('status')).toContainText('Đã ghi nhận');await bankContext.close();
  await page.goto('/journeys/'+checkoutBooking);await expect(page.getByText('Đã xuất vé',{exact:true})).toBeVisible();
  await page.locator('input[name=seat][value="1A"]').check();
  await page.getByRole('button',{name:'Xác nhận check-in'}).click();
  await expect(page.locator('svg')).toBeVisible();
  const bookingId=page.url().split('/').pop();
  const token=php(`$b=Illuminate\\Support\\Facades\\DB::table('bookings')->find('${bookingId}');echo app(App\\Modules\\Boarding\\BoardingService::class)->pass($b->id,$b->user_id)->token;`);expect(token).toMatch(/^[a-f0-9]{64}$/);
  await expect(page.getByRole('link',{name:'In / Lưu thẻ lên tàu'})).toBeVisible();
  const agent='agent-e2e@example.test';
  php(`$u=App\\Models\\User::firstOrCreate(['email'=>'${agent}'],['name'=>'Agent E2E','password'=>'${password}']);$u->forceFill(['role'=>'airport_agent','airport_code'=>'HAN','email_verified_at'=>now()])->save();`);
  const context=await browser.newContext();const gate=await context.newPage();await gate.goto('/login');
  await gate.getByLabel('Email',{exact:true}).fill(agent);await gate.getByLabel('Mật khẩu',{exact:true}).fill(password);
  await gate.getByRole('button',{name:'Đăng nhập',exact:true}).click();
  await gate.getByLabel('Chuyến bay',{exact:true}).selectOption('1');await gate.getByLabel('Mã thẻ từ máy quét QR').fill(token);
  await gate.getByRole('button',{name:'Xác nhận lên tàu',exact:true}).click();await expect(gate.getByRole('status')).toContainText('Đã xác nhận boarding');
  await gate.getByLabel('Mã thẻ từ máy quét QR').fill(token);await gate.getByRole('button',{name:'Xác nhận lên tàu',exact:true}).click();
  await expect(gate.getByRole('alert')).toContainText('QR đã được sử dụng');await context.close();
});
test('Trang công khai responsive, bộ lọc và thông báo không có chuyến',async({page})=>{
  await page.setViewportSize({width:390,height:844});await page.goto('/');
  await expect(page.getByRole('heading',{name:/Mỗi hành trình/})).toBeVisible();
  expect(await page.evaluate(()=>document.documentElement.scrollWidth<=window.innerWidth)).toBe(true);
  await page.getByLabel('Điểm khởi hành',{exact:true}).selectOption('SGN');await page.getByRole('button',{name:'Tìm chuyến bay →'}).click();
  await expect(page.getByText(/Không tìm thấy chuyến bay phù hợp/)).toBeVisible();
});
