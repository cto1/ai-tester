<?php

require_once __DIR__ . '/AiProviderInterface.php';

class OpenaiProvider implements AiProviderInterface {
    private $apiKey;
    private $maxRetries = 3;
    
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function callApi(string $prompt, string $systemPrompt, string $outputDir): ?array {
        echo "\n====== OpenAI ======\n";
        echo "Sending request to OpenAI...\n";
        
        $result = $this->callApiWithoutEcho($prompt, $systemPrompt);
        if (!$result) {
            return null;
        }
        
        // Save output to file
        $timestamp = date('Y-m-d_H-i-s');
        $outputFile = $outputDir . "/openai_response_{$timestamp}.txt";
        file_put_contents($outputFile, "====== OpenAI Response ======\n\n" . $result['content'] . "\n\n");
        
        if (isset($result['usage'])) {
            file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
            file_put_contents($outputFile, "Prompt tokens: " . $result['usage']['prompt_tokens'] . "\n", FILE_APPEND);
            file_put_contents($outputFile, "Completion tokens: " . $result['usage']['completion_tokens'] . "\n", FILE_APPEND);
            file_put_contents($outputFile, "Total tokens: " . $result['usage']['total_tokens'] . "\n", FILE_APPEND);
        }
        
        echo "Response saved to: " . $outputFile . "\n";
        
        return $result;
    }
    
    public function callApiWithoutEcho(string $prompt, string $systemPrompt): ?array {
        $retryCount = 0;
        $success = false;
        $response = null;
        $responseData = null;
        $httpCode = 0;
        
        $data = [
            'model' => 'gpt-4o',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $prompt]
            ],
            'temperature' => 0.2,
            'max_tokens' => 1000
        ];
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        while ($retryCount <= $this->maxRetries && !$success) {
            if ($retryCount > 0) {
                $sleepTime = pow(2, $retryCount - 1);
                echo "Retrying OpenAI request ($retryCount/$this->maxRetries) after {$sleepTime}s delay...\n";
                sleep($sleepTime);
            }
            
            $ch = curl_init('https://api.openai.com/v1/chat/completions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            
            if ($httpCode === 200) {
                $responseData = json_decode($response, true);
                if (isset($responseData['choices'][0]['message']['content'])) {
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
            echo "Failed to get response from OpenAI after $this->maxRetries retries.\n";
            return null;
        }
        
        return [
            'content' => $responseData['choices'][0]['message']['content'],
            'tokens_in' => $responseData['usage']['prompt_tokens'] ?? 0,
            'tokens_out' => $responseData['usage']['completion_tokens'] ?? 0
        ];
    }
} 