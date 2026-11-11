<?php

return [
    'store_name' => env('RECEIPT_STORE_NAME', 'MINIMARKET'),
    'connection' => env('RECEIPT_PRINTER_CONNECTION', 'network'),
    'host' => env('RECEIPT_PRINTER_HOST', '127.0.0.1'),
    'port' => (int) env('RECEIPT_PRINTER_PORT', 9100),
    'windows_printer' => env('RECEIPT_WINDOWS_PRINTER', ''),
];
