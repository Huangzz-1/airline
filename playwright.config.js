const {defineConfig}=require('@playwright/test');
module.exports=defineConfig({
  testDir:'tests/e2e', workers:1, retries:0, timeout:45000,
  use:{baseURL:process.env.E2E_BASE_URL||'http://127.0.0.1:8001',
    launchOptions:{executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',args:['--no-sandbox']},
    screenshot:'only-on-failure',trace:'retain-on-failure'},
  reporter:'list'
});
