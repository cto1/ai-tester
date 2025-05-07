<?php

require_once __DIR__ . '/AiProviderInterface.php';

class ClaudeProvider implements AiProviderInterface {
    private $apiKey;
    private $maxRetries = 3;
    
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function callApi(string $prompt, string $systemPrompt, string $outputDir): ?array {
        echo "\n====== Claude (Anthropic) ======\n";
        echo "Sending request to Claude (Anthropic)...\n";
        
        $result = $this->callApiWithoutEcho($prompt, $systemPrompt);
        if (!$result) {
            return null;
        }
        
        // Save output to file
        // $timestamp = date('Y-m-d_H-i-s');
        // $outputFile = $outputDir . "/claude_response_{$timestamp}.txt";
        // file_put_contents($outputFile, "====== Claude (Anthropic) Response ======\n\n" . $result['content'] . "\n\n");
        
        // if (isset($result['usage'])) {
        //     file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
        //     file_put_contents($outputFile, "Input tokens: " . $result['usage']['input_tokens'] . "\n", FILE_APPEND);
        //     file_put_contents($outputFile, "Output tokens: " . $result['usage']['output_tokens'] . "\n", FILE_APPEND);
        // }
        
        // echo "Response saved to: " . $outputFile . "\n";
        
        return $result;
    }
    
    public function callApiWithoutEcho(string $prompt, string $systemPrompt): ?array {
        $retryCount = 0;
        $success = false;
        $response = null;
        $responseData = null;
        $httpCode = 0;
        $latencyMs = 0;
        
        $data = [
            'model' => 'claude-3-opus-20240229',
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2,
            'max_tokens' => 1000
        ];
        
        $headers = [
            'x-api-key: ' . $this->apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ];
        
        while ($retryCount <= $this->maxRetries && !$success) {
            if ($retryCount > 0) {
                $sleepTime = pow(2, $retryCount - 1);
                echo "Retrying Claude request ($retryCount/$this->maxRetries) after {$sleepTime}s delay...\n";
                sleep($sleepTime);
            }
            
            $ch = curl_init('https://api.anthropic.com/v1/messages');
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
            
            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                if (isset($responseData['content'][0]['text'])) {
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
            echo "Failed to get response from Claude after $this->maxRetries retries.\n";
            return null;
        }
        
        return [
            'content' => $responseData['content'][0]['text'],
            'tokens_in' => $responseData['usage']['input_tokens'] ?? 0,
            'tokens_out' => $responseData['usage']['output_tokens'] ?? 0,
            'latency_ms' => $latencyMs
        ];
    }
} 