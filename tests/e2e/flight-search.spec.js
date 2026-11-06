const {test, expect} = require('@playwright/test');
const {php, provision, login, fillTripDetails, continueTripPreview} = require('./helpers');
let fixture;
test.beforeAll(() => {
  fixture = JSON.parse(php(`
    $db=Illuminate\\Support\\Facades\\DB::connection('ops');
    $db->table('routes')->updateOrInsert(['origin'=>'DAD','destination'=>'HAN'],['active'=>true,'duration_minutes'=>90,'distance_km'=>700]);
    $prefix='ASFS'.strtoupper(substr(uniqid(),-6)); $date=now('Asia/Ho_Chi_Minh')->nextWeekday()->toDateString(); $rows=[];
    for($i=0;$i<12;$i++){
      $type=$i%2?'ATR72':'A320';
      $plane=$db->table('aircraft')->insertGetId(['registration'=>'VN-FS'.strtoupper(substr(uniqid(),-7)),'type'=>$type,'capacity'=>12,'status'=>'AVAILABLE']);
      $dep=Carbon\\Carbon::parse($date.' '.sprintf('%02d:00',$i+7),'Asia/Ho_Chi_Minh')->utc(); $price=1500000-$i*50000;
      $id=$db->table('flights')->insertGetId(['number'=>$prefix.sprintf('%02d',$i),'origin'=>'DAD','destination'=>'HAN','aircraft_id'=>$plane,'departure'=>$dep,'arrival'=>$dep->copy()->addMinutes(70+($i%4)*20),'status'=>'SCHEDULED','gate'=>'A1']);
      Illuminate\\Support\\Facades\\DB::table('inventory')->insert(['flight_id'=>$id,'capacity'=>12,'price'=>$price]);
      foreach(['ECONOMY'=>[$price,8,'Phổ thông'],'BUSINESS'=>[$price+500000,4,'Thương gia']] as $code=>[$amount,$quota,$name])Illuminate\\Support\\Facades\\DB::table('fare_classes')->insert(['flight_id'=>$id,'code'=>$code,'price'=>$amount,'quota'=>$quota,'name'=>$name]);
      $rows[]=['id'=>$id,'price'=>$price,'type'=>$type];
    }
    echo json_encode(['prefix'=>$prefix,'date'=>$date,'flights'=>$rows]);
  `));
});
const url = (extra = {}) => '/?' + new URLSearchParams({origin: 'DAD', destination: 'HAN', date: fixture.date, flight_number: fixture.prefix, ...extra});
const ready = page => expect(page.locator('[data-flight-search-results]')).toHaveAttribute('aria-busy', 'false');
async function priceMax(page, value) { await page.locator('#price-max').evaluate((input, next) => { input.value = next; input.dispatchEvent(new Event('input', {bubbles:true})); }, value); }

test('Bộ lọc, sắp xếp và phân trang dùng toàn bộ chuyến; URL và xóa lọc giữ chặng/ngày', async ({page}) => {
  await page.goto(url());
  await expect(page.locator('[data-flight-result-count]')).toHaveText('12');
  await expect(page.locator('[data-flight-card]')).toHaveCount(8);
  await page.getByRole('link', {name: /Giá thấp nhất/}).click();
  await ready(page);
  await expect(page.locator('[data-flight-card]').first()).toHaveAttribute('data-flight-id', String(fixture.flights[11].id));
  await page.locator('.flight-pagination a').filter({hasText: /^2$/}).click();
  await ready(page);
  await expect(page.locator('[data-flight-card]')).toHaveCount(4);
  await expect(page).toHaveURL(/page=2/);
  await expect(page).toHaveURL(/sort=price_asc/);
  await page.locator('input[name="departure_periods[]"][value=morning]').check();
  await ready(page);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('5');
  expect(new URL(page.url()).searchParams.has('page')).toBe(false);
  await priceMax(page, '1360000');
  await ready(page);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('2');
  await page.locator('input[name="fare_classes[]"][value=BUSINESS]').check();
  await ready(page);
  await expect(page.getByRole('heading', {name: 'Không tìm thấy chuyến bay phù hợp'})).toBeVisible();
  await page.locator('.filter-heading a').click();
  await ready(page);
  await expect(page.locator('#origin')).toHaveValue('DAD');
  await expect(page.locator('#destination')).toHaveValue('HAN');
  await expect(page.locator('#date')).toHaveValue(fixture.date);
  expect(await page.locator('#price-max').evaluate(input => input.value === input.max)).toBe(true);
  await expect(page.locator('#flight-number-filter')).toHaveValue('');
  await page.goto(url());
  await page.locator('#price-max').evaluate(input => { input.value = '1000000'; input.dispatchEvent(new Event('input', {bubbles: true})); });
  await ready(page);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('2');
  await page.reload();
  await expect(page.locator('#price-max')).toHaveValue('1000000');
  await expect(page.locator('[data-flight-result-count]')).toHaveText('2');
  await page.evaluate(() => {
    const original = window.fetch;
    window.fetch = async (url, options) => {
      const slow = new URL(url).searchParams.get('price_max') === '1300000';
      if (slow) window.searchOldStarted = true;
      const response = await original(url, {...options, signal: undefined});
      if (slow) { await new Promise(resolve => setTimeout(resolve, 1000)); window.searchOldDelivered = true; }
      return response;
    };
  });
  await priceMax(page, '1300000');
  await expect.poll(() => page.evaluate(() => window.searchOldStarted)).toBe(true);
  await priceMax(page, '1000000');
  await ready(page);
  await expect.poll(() => page.evaluate(() => window.searchOldDelivered)).toBe(true);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('2');
  await expect(page.locator('#price-max')).toHaveValue('1000000');
  await page.screenshot({path: 'test-results/flight-search-filtered.png', fullPage: true});
});

test('Bộ lọc điện thoại mở/đóng, giữ lựa chọn khi tải lại và không tràn ngang', async ({page}) => {
  await page.setViewportSize({width: 390, height: 844});
  await page.goto(url());
  await expect(page.locator('.flight-filters')).toBeHidden();
  await page.locator('[data-flight-filter-toggle]').click();
  await expect(page.locator('.flight-filters')).toBeVisible();
  await page.locator('input[name="departure_periods[]"][value=evening]').check();
  await ready(page);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('1');
  await expect(page.locator('.flight-filters')).toBeVisible();
  await page.locator('input[name="departure_periods[]"][value=evening]').uncheck();
  await ready(page);
  await page.getByText('Loại tàu bay', {exact: true}).click();
  await page.locator('input[name="aircraft_types[]"][value=ATR72]').check();
  await ready(page);
  await expect(page.locator('[data-flight-result-count]')).toHaveText('6');
  await page.locator('[data-flight-filter-toggle]').click();
  await expect(page.locator('.flight-filters')).toBeHidden();
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
  await page.getByRole('link', {name: /Bay nhanh nhất/}).click();
  await ready(page);
  await page.goBack();
  await ready(page);
  await expect(page.locator('#flight-sort')).toHaveValue('recommended');
  await page.screenshot({path: 'test-results/flight-search-mobile.png', fullPage: true});
});

test('Nút Chọn đi qua thông tin hành khách, giữ đúng hạng/giá và khóa lựa chọn cũ khi lỗi tải', async ({page}) => {
  const email = `search-book-${Date.now()}@example.test`;
  provision([[email, 'passenger']]);
  await login(page, email);
  await page.goto(url({'fare_classes[0]':'BUSINESS',passenger_count:'1',available_only:'1'}));
  await page.locator('[data-trip-choose]').first().click();
  await continueTripPreview(page);
  await expect(page.locator('[data-trip-total]')).toContainText('2.000.000');
  await fillTripDetails(page);
  await page.getByRole('button',{name:'Tiếp tục',exact:true}).click();
  await expect(page).toHaveURL(/\/reservations\/[a-f0-9-]+$/);
  await page.goto(url());
  await page.route('**/?**', route => route.request().headers()['x-flight-results'] ? route.abort('failed') : route.continue());
  await page.locator('#price-max').fill('1490000');
  await expect(page.locator('[data-flight-search-feedback]')).toHaveClass(/is-error/);
  await expect(page.locator('[data-trip-choose]').first()).toBeDisabled();
  await page.unroute('**/?**');
  await page.locator('#price-max').evaluate(input => { input.value = input.max; input.dispatchEvent(new Event('input', {bubbles:true})); });
  await page.getByRole('button',{name:'Tìm chuyến bay →',exact:true}).click();
  await ready(page);
  const card=page.locator('[data-flight-card]').first();
  await card.locator('[data-flight-fare]').selectOption('BUSINESS');
  await expect(card.locator('[data-flight-price]')).toContainText('2.000.000');
  await card.locator('[data-trip-choose]').click();
  await continueTripPreview(page);
  await expect(page.locator('[data-trip-total]')).toContainText('2.000.000');
});
