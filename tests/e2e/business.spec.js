const {test,expect}=require('@playwright/test');
const {php,provision,login,chooseFirstTrip}=require('./helpers');
const users=Object.fromEntries(['passenger','customer_service','finance','finance2','data_admin','operations','ops_manager','airport_agent'].map(role=>[role,`business-${role}@example.test`]));
let fixture;
const form=(page,action)=>page.locator(`form[action="/workspace/business/${action}"]`);
async function rolePage(browser,role){const context=await browser.newContext();const page=await context.newPage();await login(page,users[role]);return {context,page};}
function held(flight=1,paid=true){return JSON.parse(php(`
 $u=App\\Models\\User::where('email','${users.passenger}')->firstOrFail();
 $r=app(App\\Modules\\Booking\\ReservationService::class)->hold($u,['idempotency_key'=>(string)Illuminate\\Support\\Str::uuid(),'trip_type'=>'ONE_WAY','outbound_count'=>1,'passengers'=>[['name'=>'Nguyen Van An','date_of_birth'=>'1990-01-01','details'=>['surname'=>'Nguyen','given_name'=>'Van An','nationality'=>'VN','gender'=>'M','document_number'=>'P12345678','document_expiry'=>'2035-01-01']]],'contact_details'=>['name'=>'Nguyen Van An','email'=>$u->email,'phone'=>'0912345678'],'segments'=>[['flight_id'=>${flight},'fare_class'=>'ECONOMY']]]);
 $b=Illuminate\\Support\\Facades\\DB::table('bookings')->where('reservation_id',$r->id)->first();
 ${paid?`$s=app(App\\Modules\\Booking\\ReservationService::class)->pay($u,$r->id,'BANK','127.0.0.1');for($i=0;$i<3;$i++)app(App\\Modules\\Finance\\OutboxRelay::class)->drain();$f=App\\Models\\User::where('email','${users.finance}')->firstOrFail();app(App\\Modules\\Finance\\GatewayPayments::class)->decideBank($f,$s->intent_id,true,'FIXTURE-'.Illuminate\\Support\\Str::uuid());for($i=0;$i<3;$i++)app(App\\Modules\\Finance\\OutboxRelay::class)->drain();`:''}
 echo json_encode(['rid'=>$r->id,'bid'=>$b->id,'ref'=>$b->reference,'pnr'=>$r->reference]);
 `));}
test.beforeAll(()=>{
 provision(Object.entries(users).map(([role,email])=>[email,role==='finance2'?'finance':role]));
 fixture=JSON.parse(php(`
  $db=Illuminate\\Support\\Facades\\DB::connection('ops');
  $db->table('flights')->where('id',1)->update(['departure'=>now()->addHours(4),'arrival'=>now()->addHours(6),'status'=>'SCHEDULED']);
  Illuminate\\Support\\Facades\\DB::table('fare_classes')->updateOrInsert(['flight_id'=>1,'code'=>'ECONOMY'],['name'=>'Phổ thông','quota'=>60,'price'=>1290000]);
  $ids=[];foreach([['MANIFEST',45,'BOARDING'],['SOURCE',240,'SCHEDULED'],['TARGET',480,'SCHEDULED']] as [$tag,$minutes,$state]){
   $plane=$db->table('aircraft')->insertGetId(['registration'=>'VN-BZ'.strtoupper(substr(uniqid(),-7)),'type'=>'A320','capacity'=>60]);
   $id=$db->table('flights')->insertGetId(['number'=>'ASBZ'.$tag,'origin'=>'HAN','destination'=>'SGN','aircraft_id'=>$plane,'departure'=>now()->addMinutes($minutes),'arrival'=>now()->addMinutes($minutes+90),'status'=>$state,'gate'=>'A1']);
   Illuminate\\Support\\Facades\\DB::table('inventory')->insert(['flight_id'=>$id,'capacity'=>60,'price'=>1500000]);
   Illuminate\\Support\\Facades\\DB::table('fare_classes')->insert(['flight_id'=>$id,'code'=>'ECONOMY','name'=>'Phổ thông','quota'=>60,'price'=>1500000]);$ids[$tag]=$id;
  }echo json_encode($ids);
 `));
});
// Every fixture helper refuses non-test databases. Clear the test cache between independent
// scenarios so the production 5 logins/minute limit does not couple staff role tests.
test.beforeEach(()=>php('Illuminate\\Support\\Facades\\Cache::flush();'));

test('Sửa trước thanh toán, mua hành lý và nhập sao kê để xác nhận thu',async({page,browser})=>{
 test.setTimeout(90000);
 await login(page,users.passenger);await page.goto('/?flight_number=AS101');const rid=await chooseFirstTrip(page);
 await page.goto(`/reservations/${rid}/details`);await page.locator('[name="passenger[given_name]"]').fill('Thu Ha');await page.locator('[name=reason]').fill('Sửa tên đúng với giấy tờ hành khách');await page.getByRole('button',{name:'Lưu / Gửi yêu cầu sửa'}).click();
 await expect(page.getByRole('status')).toContainText('Đã cập nhật');
 await page.goto(`/reservations/${rid}`);await page.locator('[name=package]').selectOption('10:1590000');await page.getByRole('button',{name:'Cập nhật hành lý'}).click();await expect(page.locator('main')).toContainText('1.590.000');
 await page.getByLabel('Phương thức thanh toán',{exact:true}).selectOption('BANK');await page.getByRole('button',{name:'Thanh toán toàn bộ hành trình',exact:true}).click();
 const bank=await rolePage(browser,'finance');
 try{
  const data=JSON.parse(php(`$r=Illuminate\\Support\\Facades\\DB::table('reservations')->find('${rid}');echo json_encode(['ref'=>$r->reference,'date'=>now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s')]);`));
  await bank.page.goto('/workspace/bank-statements');await form(bank.page,'statement_import').locator('[name=source]').fill('browser-bank');
  await form(bank.page,'statement_import').locator('[name=file]').setInputFiles({name:'test-statement.csv',mimeType:'text/csv',buffer:Buffer.from(`transaction_ref,booking_reference,amount,currency,booked_at\nBANK-BROWSER-${Date.now()},${data.ref},1590000,VND,${data.date}\n`)});
  await bank.page.getByRole('button',{name:'Nhập và đối chiếu',exact:true}).click();await expect(form(bank.page,'statement_confirm')).toHaveCount(1);
  await form(bank.page,'statement_confirm').locator('[name=consent]').check();await bank.page.getByRole('button',{name:'Xác nhận đã thu',exact:true}).click();await expect(form(bank.page,'statement_confirm')).toHaveCount(0);
  await bank.page.screenshot({path:'test-results/business-statements.png',fullPage:true});
  await page.goto(`/reservations/${rid}`);await expect(page.getByText('Đã xuất vé',{exact:true}).first()).toBeVisible();
  expect(php(`echo Illuminate\\Support\\Facades\\DB::table('reservation_passengers')->where('reservation_id','${rid}')->value('name');`)).toBe('Nguyen Thu Ha');
  await page.setViewportSize({width:390,height:844});await page.goto(`/reservations/${rid}/details`);expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);await page.screenshot({path:'test-results/business-details-mobile.png',fullPage:true});
 }finally{await bank.context.close();}
});

test('Điều chỉnh độc lập và báo cáo doanh thu theo đường bay, xuất CSV',async({page,browser})=>{
 const booking=held();await login(page,users.finance);await page.goto('/workspace/financial-adjustments');
 await form(page,'adjustment_request').locator('[name=booking_id]').selectOption(booking.bid);await form(page,'adjustment_request').locator('[name=amount]').fill('-90000');await form(page,'adjustment_request').locator('[name=reason]').fill('Điều chỉnh chênh lệch theo chứng từ kiểm thử');await page.getByRole('button',{name:'Gửi phiếu chờ duyệt'}).click();
 await expect(page.getByText('Phiếu đang chờ một nhân viên tài chính khác duyệt.')).toBeVisible();
 const approver=await rolePage(browser,'finance2');try{
  await approver.page.goto('/workspace/financial-adjustments');await form(approver.page,'adjustment_decide').locator('[name=note]').fill('Đã đối chiếu đủ chứng từ và căn cứ');await approver.page.getByRole('button',{name:'Duyệt điều chỉnh',exact:true}).click();await expect(form(approver.page,'adjustment_decide')).toHaveCount(0);
  await approver.page.goto('/workspace/revenue?group=route&origin=HAN&destination=SGN');const expected=Number(php("echo Illuminate\\Support\\Facades\\DB::connection('finance')->table('ledger_entries')->sum('amount');"));
  const vn=new Intl.NumberFormat('vi-VN').format(expected);await expect(approver.page.locator('tfoot')).toContainText(vn);await expect(approver.page.locator('tbody')).toContainText('HAN');
  const download=approver.page.waitForEvent('download');await approver.page.getByRole('button',{name:'Xuất CSV / Excel'}).click();expect((await download).suggestedFilename()).toMatch(/doanh-thu.*\.csv/);
  await approver.page.screenshot({path:'test-results/business-revenue.png',fullPage:true});
 }finally{await approver.context.close();}
});

test('Khóa mở check-in, boarding và chốt danh sách theo phiên bản',async({page,browser})=>{
 test.setTimeout(90000);const booking=held(fixture.MANIFEST);await login(page,users.airport_agent);
 const url=`/workspace/boarding-control?flight=${fixture.MANIFEST}`;await page.goto(url);
 await form(page,'checkin_control').locator('[name=mode]').selectOption('CLOSED');await form(page,'checkin_control').locator('[name=reason]').fill('Tạm khóa để kiểm tra tại quầy');await page.getByRole('button',{name:'Lưu cửa sổ check-in'}).click();
 const customer=await rolePage(browser,'passenger');const ops=await rolePage(browser,'operations');try{
  await customer.page.goto('/journeys/'+booking.bid);await expect(customer.page.getByRole('button',{name:'Xác nhận check-in'})).toHaveCount(0);
  await page.goto(url);await form(page,'checkin_control').locator('[name=mode]').selectOption('OPEN');await form(page,'checkin_control').locator('[name=reason]').fill('Đã kiểm tra và mở lại quầy');await page.getByRole('button',{name:'Lưu cửa sổ check-in'}).click();
  await customer.page.reload();await customer.page.locator('input[name=seat][value="1A"]').check();await customer.page.getByRole('button',{name:'Xác nhận check-in'}).click();await expect(customer.page.locator('svg')).toBeVisible();
  const token=php(`$b=Illuminate\\Support\\Facades\\DB::table('bookings')->find('${booking.bid}');echo app(App\\Modules\\Boarding\\BoardingService::class)->pass($b->id,$b->user_id)->token;`);
  await page.goto('/staff');await page.getByLabel('Chuyến bay',{exact:true}).selectOption(String(fixture.MANIFEST));await page.getByLabel('Mã thẻ từ máy quét QR').fill(token);await page.getByRole('button',{name:'Xác nhận lên tàu',exact:true}).click();await expect(page.getByRole('status')).toContainText('Đã xác nhận boarding');
  await ops.page.goto('/workspace/scheduling');const state=ops.page.locator('form[action="/workspace/actions/flight_state"]');await state.locator('[name=flight_id]').selectOption(String(fixture.MANIFEST));await state.locator('[name=status]').selectOption('GATE_CLOSED');await state.locator('[name=reason]').fill('Đã hoàn tất lên tàu và đóng cửa');await state.getByRole('button').click();
  await page.goto(url);await page.getByRole('button',{name:'Chốt danh sách lên tàu'}).click();await expect(page).toHaveURL(/\/manifests\//);await expect(page.locator('main')).toContainText('Phiên bản 1');await expect(page.locator('tbody')).toContainText('Nguyen Van An');
  const download=page.waitForEvent('download');await page.getByRole('link',{name:'Xuất CSV danh sách này'}).click();expect((await download).suggestedFilename()).toContain('v1.csv');await page.screenshot({path:'test-results/business-manifest.png',fullPage:true});
 }finally{await customer.context.close();await ops.context.close();}
});

test('Duyệt sửa vé đã mua và phân công, chuyển cấp, giải quyết hỗ trợ',async({page,browser})=>{
 test.setTimeout(90000);const booking=held();await login(page,users.passenger);await page.goto(`/reservations/${booking.rid}/details`);
 await page.locator('[name="passenger[given_name]"]').fill('Minh Duc');await page.locator('[name=reason]').fill('Yêu cầu sửa tên đúng theo giấy tờ');await page.getByRole('button',{name:'Lưu / Gửi yêu cầu sửa'}).click();await expect(page.getByRole('status')).toContainText('Đã gửi yêu cầu');
 const revision=php(`echo Illuminate\\Support\\Facades\\DB::table('passenger_revisions')->where('reservation_id','${booking.rid}')->value('id');`);const cs=await rolePage(browser,'customer_service');try{
  await cs.page.goto('/workspace/passenger-revisions/'+revision);await form(cs.page,'revision_decide').locator('[name=note]').fill('Đã kiểm tra giấy tờ và xác nhận');await cs.page.getByRole('button',{name:'Duyệt cập nhật',exact:true}).click();
  await page.reload();await expect(page.locator('[name="passenger[given_name]"]')).toHaveValue('Minh Duc');
  await page.goto('/workspace/customer');const create=page.locator('form[action="/workspace/actions/support"]');await create.locator('[name=type]').selectOption('COMPLAINT');await create.locator('[name=subject]').fill('Cần hỗ trợ hành trình thử nghiệm');await create.locator('[name=message]').fill('Tôi cần được hỗ trợ xác nhận lại lịch trình');await create.getByRole('button').click();
  const data=JSON.parse(php(`echo json_encode(['id'=>Illuminate\\Support\\Facades\\DB::table('support_cases')->orderByDesc('id')->value('id'),'agent'=>App\\Models\\User::where('email','${users.customer_service}')->value('id'),'due'=>now('Asia/Ho_Chi_Minh')->addHours(2)->format('Y-m-d\\TH:i')]);`));
  await cs.page.goto('/workspace/support-desk');const assign=form(cs.page,'support_assign').filter({has:cs.page.locator(`[name=id][value="${data.id}"]`)});await assign.locator('..').evaluate(el=>el.open=true);await assign.locator('[name=assigned_to]').selectOption(String(data.agent));await assign.locator('[name=priority]').selectOption('HIGH');await assign.locator('[name=due_at]').fill(data.due);await assign.locator('[name=reason]').fill('Giao nhân viên chuyên trách theo dõi');await assign.getByRole('button',{name:'Lưu phân công'}).click();
  expect(php(`echo Carbon\\Carbon::parse(Illuminate\\Support\\Facades\\DB::table('support_cases')->where('id',${data.id})->value('due_at'))->tz('Asia/Ho_Chi_Minh')->format('Y-m-d\\TH:i');`)).toBe(data.due);
  php(`Illuminate\\Support\\Facades\\DB::table('support_cases')->where('id',${data.id})->update(['due_at'=>now()->subMinute()]);`);
  await expect.poll(()=>php(`echo Illuminate\\Support\\Facades\\DB::table('support_cases')->where('id',${data.id})->value('priority');`),{timeout:20000}).toBe('URGENT');
  await cs.page.goto('/workspace/support-desk?overdue=1');await expect(cs.page.locator('main')).toContainText('Đã nâng ưu tiên');await cs.page.screenshot({path:'test-results/business-support.png',fullPage:true});
  await cs.page.goto('/support/'+data.id);await cs.page.locator('[name=status]').selectOption('RESOLVED');await cs.page.locator('[name=response]').fill('Đã xác nhận lịch trình và gửi thông tin cho bạn');await cs.page.getByRole('button',{name:'Gửi nội dung'}).click();
  await page.goto('/support/'+data.id);await expect(page.getByText('Yêu cầu đã được giải quyết; lịch sử trao đổi vẫn được giữ để tra cứu.')).toBeVisible();
 }finally{await cs.context.close();}
});

test('Thiết lập điều kiện vé, đánh giá gián đoạn và khách tiếp tục phương án đã duyệt',async({page,browser})=>{
 test.setTimeout(90000);await login(page,users.data_admin);await page.goto('/workspace/fare-rules');const policy=form(page,'fare_rules').first();const fare=await policy.locator('[name=fare_id]').inputValue();await policy.locator('[name=change_fee]').fill('120000');await policy.locator('[name=refund_fee]').fill('180000');await policy.getByRole('button',{name:'Lưu điều kiện vé'}).click();expect(JSON.parse(php(`echo Illuminate\\Support\\Facades\\DB::table('fare_classes')->where('id',${fare})->value('rules');`)).change_fee).toBe(120000);
 const booking=held(fixture.SOURCE);const ops=await rolePage(browser,'operations');const manager=await rolePage(browser,'ops_manager');const customer=await rolePage(browser,'passenger');try{
  await ops.page.goto('/workspace/disruptions');const disruption=ops.page.locator('form[action="/workspace/actions/disruption"]');await disruption.locator('[name=flight_id]').selectOption(String(fixture.SOURCE));await disruption.locator('[name=type]').selectOption('DELAY');await disruption.locator('[name=delay_minutes]').fill('30');await disruption.locator('[name=reason]').fill('Thử quy trình chậm chuyến do thời tiết');await disruption.getByRole('button').click();
  const disruptionId=php(`echo Illuminate\\Support\\Facades\\DB::connection('ops')->table('disruptions')->where('flight_id',${fixture.SOURCE})->value('id');`);
  await manager.page.goto('/workspace/disruptions');const approve=manager.page.locator('form[action="/workspace/actions/approve_disruption"]');await approve.locator('[name=disruption_id]').selectOption(disruptionId);await approve.getByRole('button').click();
  const url=`/workspace/passenger-recovery?flight=${fixture.SOURCE}`;await ops.page.goto(url);const offer=form(ops.page,'recovery_propose').filter({has:ops.page.locator(`[name=booking_id][value="${booking.bid}"]`)});await offer.locator('[name=choice]').selectOption(fixture.TARGET+':ECONOMY');await offer.locator('[name=reason]').fill('Đề xuất chuyến muộn hơn do chậm chuyến');await offer.getByRole('button',{name:'Lập phương án cho khách'}).click();await expect(ops.page.locator('main')).toContainText('Phương án đã lập');await ops.page.screenshot({path:'test-results/business-recovery.png',fullPage:true});
  const id=php(`echo Illuminate\\Support\\Facades\\DB::table('recovery_offers')->where('booking_id','${booking.bid}')->value('id');`);await manager.page.goto(url);await manager.page.getByRole('button',{name:'Duyệt & thông báo khách'}).click();
  await customer.page.goto('/recovery-offers/'+id);await expect(customer.page.getByRole('heading',{name:'Chuyến thay thế được duyệt'})).toBeVisible();await customer.page.getByRole('button',{name:'Gửi yêu cầu đổi sang chuyến này'}).click();await expect(customer.page).toHaveURL(/\/journeys\/[a-f0-9-]+$/);
  expect(php(`echo Illuminate\\Support\\Facades\\DB::table('exchange_requests')->where('id','${id}')->value('status');`)).toBe('PENDING');
 }finally{await ops.context.close();await manager.context.close();await customer.context.close();}
});
