<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AiService
{
    public function ask($prompt)
    {
        $response = Http::timeout(120)
            ->post('http://127.0.0.1:11434/api/generate', [
                'model' => 'mistral',
                'prompt' => $prompt,
                'stream' => false
            ]);

        return $response->json()['response'] ?? 'No response';
    }
}