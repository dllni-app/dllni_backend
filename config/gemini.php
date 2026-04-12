<?php

declare(strict_types=1);

return [

    'api_key' => env('GEMINI_API_KEY'),

    'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    /*
    | gemini-3-flash-preview → vision (extract product / menu from image)
    | gemini-3.1-flash-image-preview → native image generation
    */
    'vision_model' => env('GEMINI_VISION_MODEL', 'gemini-3-flash-preview'),

    'image_gen_model' => env('GEMINI_IMAGE_GEN_MODEL', 'gemini-3.1-flash-image-preview'),

    'timeout' => (int) env('GEMINI_TIMEOUT', 120),

    'retry_times' => (int) env('GEMINI_RETRY_TIMES', 3),

    'retry_sleep' => (int) env('GEMINI_RETRY_SLEEP_MS', 500),

];
