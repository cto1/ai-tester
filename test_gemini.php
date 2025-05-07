<?php

// Load environment variables
require_once __DIR__ . '/vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get API key from .env
$apiKey = $_ENV['GEMINI_API_KEY'];
if (empty($apiKey)) {
    die("GEMINI_API_KEY not found in .env file\n");
}

// Command line arguments
$model = $argv[1] ?? 'gemini-1.5-pro';
$prompt = $argv[2] ?? 'Write a simple test';

echo "Testing Gemini API with model: $model\n";
echo "Prompt: $prompt\n\n";

// API request setup
$data = [
    'contents' => [
        [
            'parts' => [
                [
                    'text' => $prompt
                ]
            ]
        ]
    ],
    'generationConfig' => [
        'temperature' => 0.2,
        'maxOutputTokens' => 2000,
        'topK' => 40,
        'topP' => 0.95
    ],
    'safetySettings' => [
        [
            'category' => 'HARM_CATEGORY_HARASSMENT',
            'threshold' => 'BLOCK_NONE'
        ],
        [
            'category' => 'HARM_CATEGORY_HATE_SPEECH',
            'threshold' => 'BLOCK_NONE'
        ],
        [
            'category' => 'HARM_CATEGORY_SEXUALLY_EXPLICIT',
            'threshold' => 'BLOCK_NONE'
        ],
        [
            'category' => 'HARM_CATEGORY_DANGEROUS_CONTENT',
            'threshold' => 'BLOCK_NONE'
        ]
    ]
];

// Check if this is a newer model version and adjust format if needed
if (strpos($model, 'gemini-1.5') !== false || strpos($model, 'gemini-2') !== false) {
    $data['contents'][0]['role'] = 'user';
}

// Gemini API URL
$apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . $apiKey;

$headers = [
    'Content-Type: application/json'
];

// Print request
echo "Request URL: $apiUrl\n";
echo "Request payload:\n";
echo json_encode($data, JSON_PRETTY_PRINT) . "\n\n";

// Make the API call
$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

$startTime = microtime(true);
$response = curl_exec($ch);
$endTime = microtime(true);
$latencyMs = round(($endTime - $startTime) * 1000);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

// Output results
echo "HTTP Status: $httpCode\n";
echo "Latency: {$latencyMs}ms\n\n";

if ($httpCode >= 200 && $httpCode < 300) {
    $responseData = json_decode($response, true);
    
    // Check for errors in the response
    if (isset($responseData['error'])) {
        echo "ERROR: " . $responseData['error']['message'] . "\n";
        
        if (isset($responseData['error']['details'])) {
            echo "Error details: " . json_encode($responseData['error']['details'], JSON_PRETTY_PRINT) . "\n";
        }
    }
    // Check for finish reason
    else if (isset($responseData['candidates'][0]['finishReason']) && 
             $responseData['candidates'][0]['finishReason'] !== 'STOP') {
        echo "Generation finished with reason: " . $responseData['candidates'][0]['finishReason'] . "\n";
    }
    // Check for content
    else if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
        $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
        echo "RESPONSE CONTENT:\n$content\n\n";
    }
    else {
        echo "No content in response. Full response:\n";
        echo json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
    }
    
    // Display token usage
    if (isset($responseData['usageMetadata'])) {
        echo "Token usage:\n";
        echo "Input tokens: " . ($responseData['usageMetadata']['promptTokenCount'] ?? 'N/A') . "\n";
        echo "Output tokens: " . ($responseData['usageMetadata']['candidatesTokenCount'] ?? 'N/A') . "\n";
        echo "Total tokens: " . (
            ($responseData['usageMetadata']['promptTokenCount'] ?? 0) + 
            ($responseData['usageMetadata']['candidatesTokenCount'] ?? 0)
        ) . "\n";
    }
} else {
    echo "HTTP Error: $httpCode\n";
    if (!empty($error)) {
        echo "cURL Error: $error\n";
    }
    echo "Response: $response\n";
}

// Print full raw response for debugging
echo "\nDEBUG - Full raw response:\n";
echo $response . "\n";
 