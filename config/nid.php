<?php

return [
    'ocr' => [
        'driver' => env('NID_OCR_DRIVER', 'tesseract'),
        'tesseract' => [
            'binary' => env('NID_TESSERACT_BINARY', 'tesseract'),
            'languages' => env('NID_OCR_LANGUAGES', 'ben+eng'),
            'psm' => env('NID_TESSERACT_PSM', '6'),
        ],
    ],

    'upload' => [
        'disk' => env('NID_UPLOAD_DISK', 'local'),
        'directory' => env('NID_UPLOAD_DIRECTORY', 'nid-uploads'),
        'max_size_kb' => (int) env('NID_UPLOAD_MAX_SIZE_KB', 10240),
    ],
];
