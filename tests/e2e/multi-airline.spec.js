const {test,expect}=require('@playwright/test');
const {php,continueTripPreview}=require('./helpers');
let dates;
test.beforeAll(()=>{
 dates=JSON.parse(php(`$start=now('Asia/Ho_Chi_Minh')->addDay()->startOfDay();(new Database\\Seeders\\MarketplaceFlightSeeder)->generate($start,3);echo json_encode(['out'=>$start->toDateString(),'back'=>$start->copy()->addDays(2)->toDateString()]);`));
});
const query=extra=>'/?'+new URLSearchParams({origin:'HAN',destination:'SGN',date:dates.out,...extra});
const ready=page=>expect(page.locator('[data-flight-search-results]')).toHaveAttribute('aria-busy','false');

test('Bốn hãng, số chuyến thực tế và hành trình một/hai điểm dừng khớp giờ',async({page})=>{
 await page.goto(query());
 for(const code of ['VN','VJ','QH','VU'])await expect(page.locator(`input[name="carriers[]"][value="${code}"]`)).toBeVisible();
 await page.locator('input[name="carriers[]"][value="VN"]').check();await ready(page);
 for(const stop of ['0','1','2']){
  for(const value of ['0','1','2'])await page.locator(`input[name="stops[]"][value="${value}"]`).setChecked(value===stop);
  await ready(page);await expect(page.locator('[data-flight-card]').first()).toBeVisible();
  const count=await page.locator(`label:has(input[name="stops[]"][value="${stop}"]) .stop-filter-count`).innerText();
  const total=await page.locator('[data-flight-result-count]').innerText();expect(count).toBe(total+' chuyến');
  for(const code of await page.locator('[data-flight-card] .carrier-symbol').evaluateAll(nodes=>nodes.map(n=>n.dataset.carrier)))expect(code).toBe('VN');
 }
 await page.locator('.flight-connection-details').first().locator('summary').click();await expect(page.locator('.flight-connection-details').first()).toContainText('Nha Trang (CXR)');
 await page.screenshot({path:'test-results/multi-airline-desktop.png',fullPage:true});
 await page.getByRole('button',{name:'Chọn',exact:true}).first().click();const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();await expect(dialog.locator('.trip-preview-layover')).toHaveCount(2);await expect(dialog.locator('.trip-preview-flight')).toHaveCount(3);await expect(dialog).toContainText('Vietnam Airlines');
 await continueTripPreview(page);await expect(page.locator('.trip-direction-carrier')).toContainText('Vietnam Airlines');await expect(page.getByText('Thông tin hành khách',{exact:true})).toBeVisible();
});

test('Khứ hồi chọn khác hãng cho hai lượt và xem đúng tên hãng trên điện thoại',async({page})=>{
 await page.setViewportSize({width:390,height:844});await page.goto(query({trip_type:'ROUND_TRIP',return_date:dates.back,'carriers[0]':'VN','stops[0]':'0'}));
 await page.getByRole('button',{name:'Chọn',exact:true}).first().click();await continueTripPreview(page);
 await page.locator('[data-flight-filter-toggle]').click();
 const previousCarrier=page.locator('input[name="carriers[]"][value="VN"]');if(await previousCarrier.isChecked()){await previousCarrier.uncheck();await ready(page);}
 await page.locator('input[name="carriers[]"][value="VJ"]').check();await ready(page);
 await page.locator('[data-flight-filter-toggle]').click();await page.getByRole('button',{name:'Chọn',exact:true}).first().click();
 const dialog=page.getByRole('dialog');await expect(dialog).toBeVisible();await expect(dialog).toContainText('Vietnam Airlines');await expect(dialog).toContainText('Vietjet Air');expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);
 await page.screenshot({path:'test-results/multi-airline-mobile.png',fullPage:true});await continueTripPreview(page);await expect(page.locator('.trip-direction-carrier').nth(0)).toContainText('Vietnam Airlines');await expect(page.locator('.trip-direction-carrier').nth(1)).toContainText('Vietjet Air');
});
