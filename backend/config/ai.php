<?php

return [
    'provider' => env('AI_PROVIDER', 'ollama'),

    'ollama' => [
        'url' => env('AI_OLLAMA_URL', ''),
        'model' => env('AI_OLLAMA_MODEL', 'qwen2.5vl:3b'),
        'timeout' => (int) env('AI_OLLAMA_TIMEOUT', 300),
    ],
];
