const {test,expect}=require('@playwright/test');
const {php}=require('./helpers');
let date;
test.beforeAll(()=>{date=php(`$start=now('Asia/Ho_Chi_Minh')->addDay()->startOfDay();(new Database\\Seeders\\MarketplaceFlightSeeder)->generate($start,1);echo $start->toDateString();`);});
const query=()=> '/?'+new URLSearchParams({origin:'HAN',destination:'SGN',date,'carriers[0]':'VN','fare_classes[0]':'ECONOMY'});
const ready=page=>expect(page.locator('[data-flight-search-results]')).toHaveAttribute('aria-busy','false');
const domains=page=>page.locator('[data-flight-range]').evaluateAll(groups=>Object.fromEntries(groups.map(group=>[group.dataset.flightRange,{min:Number(group.querySelector('[data-range-bound=min]').min),max:Number(group.querySelector('[data-range-bound=max]').max)}])));
async function position(page,key,side,value){
 const input=page.locator(`#${key}-${side}`);await input.scrollIntoViewIfNeeded();const bounds=await input.evaluate(el=>({min:Number(el.min),max:Number(el.max),value:Number(el.value)}));const rail=await page.locator(`[data-flight-range=${key}] .flight-range-track`).boundingBox();
 return {x:rail.x+(bounds.value-bounds.min)/(bounds.max-bounds.min)*rail.width,to:rail.x+(value-bounds.min)/(bounds.max-bounds.min)*rail.width,y:rail.y+rail.height/2};
}
async function range(page,key,low,high){
 await page.locator(`[data-flight-range="${key}"]`).evaluate((group,values)=>{
  for(const [side,value] of [['min',values.low],['max',values.high]])if(value!==undefined){const input=group.querySelector(`[data-range-bound="${side}"]`);input.value=value;input.dispatchEvent(new Event('input',{bubbles:true}));}
 },{low,high});await ready(page);
}

test('Khoảng giá, quá cảnh và hành trình lọc thật, giữ URL, phục hồi khi không có kết quả',async({page})=>{
 await page.goto(query());await expect(page.locator('[data-flight-result-count]')).toHaveText('4');await expect(page.locator('[data-flight-range]')).toHaveCount(3);
 await range(page,'price',1300000,1600000);await expect(page.locator('[data-flight-result-count]')).toHaveText('1');await expect(page.locator('[data-flight-card]')).toContainText('2 điểm dừng');await expect(page.locator('[data-flight-range=price] [data-range-output]')).toContainText('1.300.000 VND');
 await page.reload();await expect(page.locator('#price-min')).toHaveValue('1300000');await expect(page.locator('#price-max')).toHaveValue('1600000');
 await range(page,'duration',200,300);await expect(page.getByRole('heading',{name:'Không tìm thấy chuyến bay phù hợp'})).toBeVisible();await expect(page.locator('#price-max')).toBeEnabled();
 await page.locator('.filter-heading a').click();await ready(page);expect(new URL(page.url()).searchParams.has('price_max')).toBe(false);expect(await page.locator('#price-max').evaluate(input=>input.value===input.max)).toBe(true);
 await page.goto(query());await range(page,'layover',1,120);await expect(page.locator('[data-flight-result-count]')).toHaveText('1');await expect(page.locator('[data-flight-card]')).toContainText('1 điểm dừng');await expect(page.locator('#layover-min')).toHaveValue('1');
 await page.goto(query());await range(page,'layover',0,0);await expect(page.locator('[data-flight-result-count]')).toHaveText('2');await expect(page.locator('[data-flight-card]').first()).toContainText('Bay thẳng');expect(new URL(page.url()).searchParams.get('layover_max')).toBe('0');
 await page.goto(query());await page.locator('#duration-min').focus();await page.keyboard.press('End');await ready(page);expect(await page.locator('#duration-min').inputValue()).toBe(await page.locator('#duration-min').getAttribute('max'));
 await page.locator('#duration-max').evaluate(input=>{input.value=input.min;input.dispatchEvent(new Event('input',{bubbles:true}));});await ready(page);expect(await page.locator('#duration-max').inputValue()).toBe(await page.locator('#duration-min').inputValue());
 await page.screenshot({path:'test-results/flight-ranges-desktop.png',fullPage:true});
});

test('Kéo hai đầu bằng chuột, bộ lọc điện thoại và trường hợp chỉ có bay thẳng',async({page})=>{
 await page.goto(query());const global=await domains(page);const slider=await position(page,'price','min',1600000);
 await page.mouse.move(slider.x,slider.y);await page.mouse.down();await page.mouse.move(slider.to,slider.y,{steps:8});await page.mouse.up();await ready(page);
 expect(Number(await page.locator('#price-min').inputValue())).toBeGreaterThan(1500000);await expect(page.locator('[data-flight-result-count]')).toHaveText('2');
 const price=page.locator('[data-flight-range=price]');await expect(price.locator('.flight-range-track')).toHaveCount(1);await expect(price.locator('[data-range-handle]')).toHaveCount(2);
 const upper=await position(page,'price','max',1900000);
 await page.mouse.move(upper.x,upper.y);await page.mouse.down();await page.mouse.move(upper.to,upper.y,{steps:5});
 await page.evaluate(()=>window.rangeBeforeRelease=document.querySelector('#price-max'));await page.waitForTimeout(500);expect(await page.evaluate(()=>window.rangeBeforeRelease===document.querySelector('#price-max'))).toBe(true);
 await page.mouse.up();await ready(page);expect(Number(await page.locator('#price-max').inputValue())).toBeLessThan(1990000);await expect(page.locator('[data-flight-result-count]')).toHaveText('1');
 await price.screenshot({path:'test-results/single-range-price.png'});
 const span=global.price.max-global.price.min;await range(page,'price',undefined,Math.round(global.price.min+span*.65));await range(page,'price',Math.round(global.price.min+span*.25),undefined);
 const lowBefore=Number(await page.locator('#price-min').inputValue()), highBefore=Number(await page.locator('#price-max').inputValue());const selected=await price.locator('[data-range-fill]').boundingBox();
 await page.mouse.move(selected.x+selected.width/2,selected.y+selected.height/2);await page.mouse.down();await page.mouse.move(selected.x+selected.width/2+20,selected.y+selected.height/2,{steps:5});await page.mouse.up();await ready(page);
 expect(Number(await page.locator('#price-min').inputValue())).toBeGreaterThan(lowBefore);expect(Number(await page.locator('#price-max').inputValue())-Number(await page.locator('#price-min').inputValue())).toBe(highBefore-lowBefore);
 await page.setViewportSize({width:390,height:844});await page.goto(query());await page.locator('[data-flight-filter-toggle]').click();await range(page,'duration',200,300);await expect(page.locator('[data-flight-result-count]')).toHaveText('1');await expect(page.locator('.flight-filters')).toBeVisible();expect(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1)).toBe(true);await page.screenshot({path:'test-results/flight-ranges-mobile.png',fullPage:true});
 await page.goto('/?'+new URLSearchParams({origin:'PQC',destination:'SGN',date,'carriers[0]':'VN','fare_classes[0]':'ECONOMY'}));await page.locator('[data-flight-filter-toggle]').click();await expect(page.locator('#layover-min')).toBeEnabled();await expect(page.locator('#layover-max')).toBeEnabled();expect(await domains(page)).toEqual(global);
});

test('Mốc min max toàn hệ thống giữ nguyên khi đổi tuyến, ngày, hãng hoặc không còn kết quả',async({page})=>{
 await page.goto(query());const global=await domains(page);expect(global.price.min).toBeLessThan(1250000);expect(global.price.max).toBeGreaterThan(1990000);
 for(const filters of [{origin:'PQC',destination:'SGN',date,'carriers[0]':'VU','fare_classes[0]':'FLEX'},{origin:'HAN',destination:'DLI',date:'2035-01-01'},{origin:'HAN',destination:'SGN',date,'price_max':'1'}]){
  await page.goto('/?'+new URLSearchParams(filters));expect(await domains(page)).toEqual(global);
 }
 await expect(page.getByText('Khoảng lọc nằm ngoài dữ liệu hiện có; hãy chọn lại.')).toBeVisible();
});
