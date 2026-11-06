const {execFileSync}=require('node:child_process');
exports.php=function(code){
 const bootstrap="require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); foreach(['ops','mysql','finance'] as $node){if(config('database.connections.'.$node.'.database')!=='airline_test'||config('database.connections.'.$node.'.username')!=='airline_test')exit(99);} ";
 if(process.env.E2E_PHP_BINARY){
  return execFileSync(process.env.E2E_PHP_BINARY,['-r',bootstrap+code],{cwd:process.env.E2E_BACKEND_PATH||require('node:path').resolve(__dirname,'../../backend'),env:process.env,encoding:'utf8'}).trim();
 }
 return execFileSync('docker',['compose','-f','compose.yaml','-f','compose.test.yaml','exec','-T','e2e','php','-r',bootstrap+code],{encoding:'utf8'}).trim();
};
exports.login=async function(page,email,password='WorkflowPassword2026!'){
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Mật khẩu',{exact:true}).fill(password);await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();
};
exports.provision=function(users){
 const {php}=exports;
 for(const [email,role] of users)php(`$u=App\\Models\\User::firstOrCreate(['email'=>'${email}'],['name'=>'Browser ${role}','password'=>'WorkflowPassword2026!']);$u->forceFill(['role'=>'${role}','airport_code'=>'HAN','email_verified_at'=>now()])->save();`);
};
exports.fillTripDetails=async function(page,count=1){
 for(let i=0;i<count;i++){
  const person=page.locator('[data-trip-person]').nth(i);
  await person.evaluate(el=>{el.open=true;});
  await person.locator('input[name$="[surname]"]').fill('Nguyen');
  await person.locator('input[name$="[given_name]"]').fill(i===0?'Minh Anh':'Hoang Nam');
  await person.locator('input[type=radio][value="'+(i===0?'F':'M')+'"]').check();
 }
 await page.locator('#trip-contact-name').fill('Nguyen Minh Anh');
 await page.locator('#trip-contact-phone').fill('0912345678');
 if(!await page.locator('#trip-contact-email').inputValue())await page.locator('#trip-contact-email').fill('contact-browser@example.test');
 await page.locator('input[name=consent]').check();
};
exports.continueTripPreview=async function(page){
 const {expect}=require('@playwright/test');const dialog=page.getByRole('dialog');
 await expect(dialog).toBeVisible();await dialog.locator('[data-trip-preview-continue]').click();
};
exports.chooseFirstTrip=async function(page){
 await page.getByRole('button',{name:'Chọn',exact:true}).first().click();
 await exports.continueTripPreview(page);
 await exports.fillTripDetails(page);
 await page.getByRole('button',{name:'Tiếp tục',exact:true}).click();
 await require('@playwright/test').expect(page).toHaveURL(/\/reservations\/[a-f0-9-]+$/);
 return page.url().split('/').pop();
};
exports.mail=async function(to,subject){
 const {expect}=require('@playwright/test');let id;
 await expect.poll(async()=>{
  const response=await fetch('http://127.0.0.1:8025/api/v1/search?query='+encodeURIComponent('to:'+to));
  if(!response.ok)return null;
  const data=await response.json();const found=(data.messages||[]).find(message=>message.Subject.includes(subject));id=found?.ID;
  return id??null;
 },{timeout:30000,intervals:[300,700,1000]}).not.toBeNull();
 const response=await fetch('http://127.0.0.1:8025/api/v1/message/'+encodeURIComponent(id));
 if(!response.ok)throw new Error('Cannot read the test mailbox message');return response.json();
};
