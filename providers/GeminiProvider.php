<?php

require_once __DIR__ . '/AiProviderInterface.php';

class GeminiProvider implements AiProviderInterface {
    private $apiKey;
    private $model;
    private $maxRetries = 3;
    
    public function __construct(string $apiKey, string $model = 'gemini-pro') {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }
    
    public function callApi(string $prompt, string $systemPrompt, string $outputDir): ?array {
        echo "\n====== Google Gemini ======\n";
        echo "Sending request to Google Gemini ({$this->model})...\n";
        
        $result = $this->callApiWithoutEcho($prompt, $systemPrompt);
        if (!$result) {
            return null;
        }
        
        // We no longer save files here to avoid duplication
        return $result;
    }
    
    public function callApiWithoutEcho(string $prompt, string $systemPrompt): ?array {
        $retryCount = 0;
        $success = false;
        $response = null;
        $responseData = null;
        $httpCode = 0;
        $latencyMs = 0; // Initialize latency
        
        // Combine system prompt and user prompt as Gemini's API structure
        $fullPrompt = $prompt;
        if (!empty($systemPrompt)) {
            $fullPrompt = "$systemPrompt\n\n$prompt";
        }
        
        $data = [
            'contents' => [
                [
                    'parts' => [
                        [
                            'text' => $fullPrompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 1000
            ]
        ];
        
        // Gemini API URL - using the specified model
        $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . $this->apiKey;
        
        $headers = [
            'Content-Type: application/json'
        ];
        
        while ($retryCount <= $this->maxRetries && !$success) {
            if ($retryCount > 0) {
                $sleepTime = pow(2, $retryCount - 1);
                echo "Retrying Gemini request ($retryCount/$this->maxRetries) after {$sleepTime}s delay...\n";
                sleep($sleepTime);
            }
            
            $ch = curl_init($apiUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $startTime = microtime(true); // Start timer
            $response = curl_exec($ch);
            $endTime = microtime(true); // End timer
            $latencyMs = round(($endTime - $startTime) * 1000); // Calculate latency in ms
            
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode >= 200 && $httpCode < 300) {
                $responseData = json_decode($response, true);
                
                // Extract content from Gemini's response structure
                if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    $success = true;
                    break;
                }
            }
            
            if ($retryCount < $this->maxRetries) {
                echo "HTTP Code: " . $httpCode . "\n";
                if (!empty($error)) {
                    echo "cURL Error: " . $error . "\n";
                }
                if (!empty($response)) {
                    echo "Response: " . $response . "\n";
                }
            }
            
            $retryCount++;
        }
        
        if (!$success) {
            echo "Failed to get response from Gemini after $this->maxRetries retries.\n";
            return null;
        }
        
        // Extract the response text
        $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
        
        // Get token usage if available
        $tokensIn = 0;
        $tokensOut = 0;
        
        if (isset($responseData['usageMetadata'])) {
            $tokensIn = $responseData['usageMetadata']['promptTokenCount'] ?? 0;
            $tokensOut = $responseData['usageMetadata']['candidatesTokenCount'] ?? 0;
        }
        
        return [
            'content' => $content,
            'tokens_in' => $tokensIn,
            'tokens_out' => $tokensOut,
            'latency_ms' => $latencyMs
        ];
    }
} 