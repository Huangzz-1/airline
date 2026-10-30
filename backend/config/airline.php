<?php

return ['sample_data' => (bool) env('AIRLINE_SAMPLE_DATA', false), 'bootstrap_admin' => (bool) env('AIRLINE_BOOTSTRAP_ADMIN', false), 'admin_email' => env('LOCAL_ADMIN_EMAIL'), 'admin_password' => env('LOCAL_ADMIN_PASSWORD')];
