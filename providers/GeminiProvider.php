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
                'maxOutputTokens' => 1000,
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
            
            // Always decode the response to check what we got back
            $responseData = json_decode($response, true);
            
            // Debug response
            echo "Gemini HTTP Code: " . $httpCode . "\n";
            
            if ($httpCode >= 200 && $httpCode < 300) {
                // Check for any Gemini-specific errors in the response
                if (isset($responseData['error'])) {
                    echo "Gemini API Error: " . $responseData['error']['message'] . "\n";
                    
                    // Check if the error is related to content filtering
                    if (isset($responseData['error']['details']) && !empty($responseData['error']['details'])) {
                        foreach ($responseData['error']['details'] as $detail) {
                            if (isset($detail['reason']) && $detail['reason'] === 'SAFETY') {
                                echo "Content filtered due to safety settings. Trying again with modified safety settings...\n";
                                break;
                            }
                        }
                    }
                }
                // If we at least got token usage back, we can consider this partial success
                else if (isset($responseData['usageMetadata']) && isset($responseData['usageMetadata']['promptTokenCount'])) {
                    // We got token usage but possibly not content
                    $success = true;
                    
                    // Print structure of response to help debug
                    if (!isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                        echo "Warning: Received token usage but no content from Gemini API. Response structure:\n";
                        print_r($responseData);
                    } else {
                        $success = true;
                        break;
                    }
                }
                // Full success case
                else if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
                    $success = true;
                    break;
                }
                else {
                    echo "Unexpected response structure from Gemini API:\n";
                    print_r($responseData);
                }
            }
            else {
                if (!empty($error)) {
                    echo "cURL Error: " . $error . "\n";
                }
                
                // Print full response for debugging
                echo "Response body: " . substr($response, 0, 1000) . (strlen($response) > 1000 ? "..." : "") . "\n";
            }
            
            $retryCount++;
        }
        
        if (!$success) {
            echo "Failed to get response from Gemini after $this->maxRetries retries.\n";
            return null;
        }
        
        // Default empty response in case we have token usage but no content
        $content = "No content received from Gemini API. This may be due to content filtering or an API limitation.";
        
        // Extract the response text if it exists
        if (isset($responseData['candidates'][0]['content']['parts'][0]['text'])) {
            $content = $responseData['candidates'][0]['content']['parts'][0]['text'];
        }
        
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