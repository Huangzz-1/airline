const {execFileSync}=require('node:child_process');
exports.php=function(code){
 return execFileSync('docker',['compose','-f','compose.yaml','-f','compose.test.yaml','exec','-T','e2e','php','-r',`require 'vendor/autoload.php'; $a=require 'bootstrap/app.php'; $a->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap(); if(config('database.connections.mysql.database')!=='airline_test')exit(99); ${code}`],{encoding:'utf8'}).trim();
};
exports.login=async function(page,email,password='WorkflowPassword2026!'){
 await page.goto('/login');await page.getByLabel('Email',{exact:true}).fill(email);await page.getByLabel('Mật khẩu',{exact:true}).fill(password);await page.getByRole('button',{name:'Đăng nhập',exact:true}).click();
};
exports.provision=function(users){
 const {php}=exports;
 for(const [email,role] of users)php(`$u=App\\Models\\User::firstOrCreate(['email'=>'${email}'],['name'=>'Browser ${role}','password'=>'WorkflowPassword2026!']);$u->forceFill(['role'=>'${role}','airport_code'=>'HAN','email_verified_at'=>now()])->save();`);
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
