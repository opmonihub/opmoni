<?php

return ['ingress_key' => env('FRONTEND_INGRESS_KEY', ''), 'origin' => env('FRONTEND_ORIGIN', 'http://localhost:3000'), 'lock_seconds' => 30, 'lock_wait' => 2];
