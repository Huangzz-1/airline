const {test,expect}=require('@playwright/test');
const {php,provision,login,fillTripDetails,mail,continueTripPreview}=require('./helpers');
let fixture;
test.beforeAll(()=>{
 fixture=JSON.parse(php(`
  $db=Illuminate\\Support\\Facades\\DB::connection('ops');$prefix='ASTR'.strtoupper(substr(uniqid(),-5));
  foreach(['VCA','PQC'] as $code)$db->table('airports')->insertOrIgnore(['code'=>$code,'name'=>$code,'city'=>$code,'timezone'=>'Asia/Ho_Chi_Minh']);
  $start=Carbon\\Carbon::parse($db->table('flights')->max('arrival'))->addDay()->startOfDay()->addHours(2);$rows=[];
  foreach([['HAN','SGN',0,90,1500000],['HAN','DAD',0,60,800000],['DAD','SGN',135,75,700000],['HAN','VCA',0,40,300000],['VCA','PQC',110,30,300000],['PQC','SGN',215,45,300000],['SGN','HAN',2880,90,1600000],['SGN','DAD',2880,60,900000],['DAD','HAN',3015,75,800000]] as $i=>[$from,$to,$minutes,$duration,$price]){
   $db->table('routes')->updateOrInsert(['origin'=>$from,'destination'=>$to],['active'=>true,'duration_minutes'=>$duration,'distance_km'=>600]);
   $plane=$db->table('aircraft')->insertGetId(['registration'=>'VN-TR'.strtoupper(substr(uniqid(),-7)),'type'=>'A320','capacity'=>12]);
   $dep=$start->copy()->addMinutes($minutes);$id=$db->table('flights')->insertGetId(['number'=>$prefix.sprintf('%02d',$i),'origin'=>$from,'destination'=>$to,'aircraft_id'=>$plane,'departure'=>$dep,'arrival'=>$dep->copy()->addMinutes($duration),'status'=>$i<6?'BOARDING':'SCHEDULED','gate'=>'A1']);
   Illuminate\\Support\\Facades\\DB::table('inventory')->insert(['flight_id'=>$id,'capacity'=>12,'price'=>$price]);
   Illuminate\\Support\\Facades\\DB::table('fare_classes')->insert(['flight_id'=>$id,'code'=>'ECONOMY','name'=>'Phổ thông','price'=>$price,'quota'=>12]);
   $rows[]=['id'=>$id,'date'=>$dep->copy()->tz('Asia/Ho_Chi_Minh')->toDateString()];
  }
  echo json_encode(['prefix'=>$prefix,'flights'=>$rows]);
 `));
});
const query=extra=>'/?'+new URLSearchParams({origin:'HAN',destination:'SGN',date:fixture.flights[0].date,flight_number:fixture.prefix,...extra});
const ready=page=>expect(page.locator('[data-flight-search-results]')).toHaveAttribute('aria-busy','false');

test('Bay thẳng/1/2 điểm dừng lọc lịch trình thật, chọn một chiều mở trang thông tin',async({page})=>{
 await page.goto(query());
 await expect(page.getByText('Nhiều chặng',{exact:true})).toHaveCount(0);
 await expect(page.locator('[data-trip-choose]')).toHaveCount(3);
 await expect(page.locator('.flight-result-card .round-trip-badge')).toHaveCount(0);
 for(const stop of ['0','1','2']){
  for(const value of ['0','1','2'])await page.locator(`input[name="stops[]"][value="${value}"]`).setChecked(value===stop);
  await ready(page);
  await expect(page.locator('[data-flight-card]')).toHaveCount(1);
  await expect(page.locator('.flight-route-duration')).toContainText(stop==='0'?'Bay thẳng':stop+' điểm dừng');
 }
 await page.locator('[data-trip-choose]').click();
 await expect(page.getByRole('dialog')).toBeVisible();
 await expect(page.getByRole('dialog').locator('.trip-preview-layover')).toHaveCount(2);
 await page.keyboard.press('Escape');
 await expect(page.getByRole('dialog')).not.toBeVisible();
 await expect(page.locator('[data-trip-choose]')).toBeFocused();
 await page.locator('[data-trip-choose]').click();
 await continueTripPreview(page);
 await expect(page).toHaveURL(/\/trip\/passengers\?/);
 await expect(page.getByRole('heading',{name:'Thông tin hành khách',exact:true})).toBeVisible();
 await expect(page.getByText('Chuyến về',{exact:true})).toHaveCount(0);
 await expect(page.locator('[data-trip-total]')).toContainText('900.000');
});

test('Khứ hồi màu vàng, hai lượt có nối chuyến, nhập một khách rồi thanh toán một lần',async({page})=>{
 test.setTimeout(90000);
 const email=`trip-${Date.now()}@example.test`;provision([[email,'passenger']]);await login(page,email);
 await page.goto(query({trip_type:'ROUND_TRIP',return_date:fixture.flights[6].date,passenger_count:'1','stops[0]':'1'}));
 const badge=page.locator('.flight-result-card .round-trip-badge').first();await expect(badge).toHaveText('Khứ hồi');
 expect(await badge.evaluate(el=>getComputedStyle(el).backgroundColor)).toBe('rgb(255, 240, 172)');
 await page.locator(`[data-flight-id="${fixture.flights[1].id}-${fixture.flights[2].id}"] [data-trip-choose]`).click();
 await continueTripPreview(page);
 await expect(page.getByText('Đã chọn lượt đi · Tiếp theo chọn lượt về',{exact:true})).toBeVisible();
 await page.locator(`[data-flight-id="${fixture.flights[7].id}-${fixture.flights[8].id}"] [data-trip-choose]`).click();
 await expect(page.getByRole('dialog').locator('[data-preview-direction]')).toHaveCount(2);
 await expect(page.getByRole('dialog').locator('.trip-preview-price')).toContainText('3.200.000');
 await page.screenshot({path:'test-results/trip-preview-round-trip.png',fullPage:true});
 await continueTripPreview(page);
 await expect(page.getByText('Chuyến đi',{exact:true})).toBeVisible();await expect(page.getByText('Chuyến về',{exact:true})).toBeVisible();
 await expect(page.locator('[data-trip-total]')).toContainText('3.200.000');
 await fillTripDetails(page);
 const contact=`contact-${Date.now()}@example.test`;await page.locator('#trip-contact-email').fill(contact);
 await page.locator('[data-save-trip-person]').first().click();await expect(page.locator('[data-trip-person]').first()).not.toHaveAttribute('open','');
 await page.screenshot({path:'test-results/trip-passengers-round-trip.png',fullPage:true});
 await page.getByRole('button',{name:'Tiếp tục',exact:true}).click();await expect(page).toHaveURL(/\/reservations\/[a-f0-9-]+$/);
 const id=page.url().split('/').pop();await expect(page.getByRole('heading',{name:'Thanh toán chuyến đi',exact:true})).toBeVisible();
 expect(php(`echo Illuminate\\Support\\Facades\\DB::table('bookings')->where('reservation_id','${id}')->count();`)).toBe('4');
 expect(php(`echo Illuminate\\Support\\Facades\\DB::table('checkout_sessions')->whereIn('booking_id',Illuminate\\Support\\Facades\\DB::table('bookings')->where('reservation_id','${id}')->pluck('id'))->count();`)).toBe('0');
 await page.getByLabel('Phương thức thanh toán',{exact:true}).selectOption('VNPAY');await page.getByRole('button',{name:'Thanh toán toàn bộ hành trình',exact:true}).click();await page.getByRole('link',{name:'Thanh toán qua VNPay',exact:true}).click();await page.getByRole('button',{name:'Xác nhận thanh toán',exact:true}).click();
 await expect(page.getByText('Đã xuất vé',{exact:true})).toHaveCount(5);
 expect(php(`echo Illuminate\\Support\\Facades\\DB::connection('finance')->table('checkout_intents')->where('reservation_id','${id}')->count();`)).toBe('1');
 const message=await mail(contact,'Kết quả thanh toán');expect(message.Text).toContain('đã được phát hành');
});

test('Một chiều trên điện thoại, bản nháp giữ qua đăng nhập và tiếp tục mới tạo giữ chỗ',async({page})=>{
 await page.setViewportSize({width:390,height:844});
 await page.goto(query({'stops[0]':'0'}));await page.getByRole('button',{name:'Chọn',exact:true}).first().click();
 await expect(page.getByRole('dialog')).toBeVisible();
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await page.screenshot({path:'test-results/trip-preview-mobile.png',fullPage:true});
 await continueTripPreview(page);
 await expect(page.getByText('Chuyến về',{exact:true})).toHaveCount(0);await fillTripDetails(page);
 await page.screenshot({path:'test-results/trip-passengers-mobile.png',fullPage:true});
 expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await page.getByRole('button',{name:'Tiếp tục',exact:true}).click();await expect(page).toHaveURL(/\/login$/);
 const email=`draft-${Date.now()}@example.test`;provision([[email,'passenger']]);await login(page,email);
 await expect(page).toHaveURL(/\/trip\/passengers\?/);await expect(page.locator('#surname-0')).toHaveValue('Nguyen');await expect(page.locator('#trip-contact-phone')).toHaveValue('0912345678');
 await page.getByRole('button',{name:'Tiếp tục',exact:true}).click();await expect(page.getByRole('heading',{name:'Thanh toán chuyến đi',exact:true})).toBeVisible();
});

test('Lỗi tải thông tin chuyến không cho tiếp tục; đóng và chọn lại có thể phục hồi',async({page})=>{
 await page.goto(query({'stops[0]':'0'}));
 const before=php('echo Illuminate\\Support\\Facades\\DB::table("reservations")->count();');
 await page.route('**/trip/select?**',route=>route.fulfill({status:409,contentType:'application/json',body:JSON.stringify({message:'Hạng vé không còn chỗ. Vui lòng chọn lại.'})}));
 await page.locator('[data-trip-choose]').first().click();
 const dialog=page.getByRole('dialog');
 await expect(dialog.getByRole('alert')).toContainText('Hạng vé không còn chỗ');
 await expect(dialog.locator('[data-trip-preview-continue]')).toHaveCount(0);
 await dialog.getByRole('button',{name:'Đóng thông tin chuyến bay'}).click();
 await page.unroute('**/trip/select?**');
 await page.locator('[data-trip-choose]').first().click();
 await expect(dialog.locator('[data-trip-preview-continue]')).toBeVisible();
 await page.keyboard.press('Escape');
 expect(php('echo Illuminate\\Support\\Facades\\DB::table("reservations")->count();')).toBe(before);
});
