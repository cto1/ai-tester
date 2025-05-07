<?php

require_once __DIR__ . '/AiProviderInterface.php';
require_once __DIR__ . '/../utils/FileUploader.php';

class MistralOcrProvider implements AiProviderInterface {
    private $apiKey;
    private $maxRetries = 3;
    private $fileUploader;
    
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
        $this->fileUploader = new FileUploader($apiKey);
    }
    
    public function callApi(string $prompt, string $systemPrompt, string $outputDir): ?array {
        echo "\n====== Mistral OCR API ======\n";
        
        $fileUrl = null;
        $fileId = null;
        $result = null;
        $latencyMs = 0; // Initialize latency
        
        try {
            // If we're processing a local file, upload it to Mistral first
            if (file_exists($prompt)) {
                echo "Uploading local file to Mistral first...\n";
                $uploadResult = $this->fileUploader->uploadToMistral($prompt);
                
                if (!$uploadResult) {
                    echo "Failed to upload file to Mistral. Cannot proceed with OCR.\n";
                    return null;
                }
                
                // Use the signed URL from the upload result
                $fileUrl = $uploadResult['url'];
                $fileId = $uploadResult['file_id']; // Save file ID for later deletion
                echo "Using uploaded file URL for OCR: " . $fileUrl . "\n";
            } else {
                echo "Error: File not found at path: $prompt\n";
                return null;
            }
            
            echo "Sending request to Mistral OCR API...\n";
            
            $data = [
                'model' => 'mistral-ocr-latest',
                'document' => [
                    'type' => 'document_url',
                    'document_url' => $fileUrl
                ],
                'include_image_base64' => true
            ];
            
            $headers = [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json'
            ];
            
            $ch = curl_init('https://api.mistral.ai/v1/ocr');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Longer timeout for OCR
            
            $startTime = microtime(true); // Start timer
            $response = curl_exec($ch);
            $endTime = microtime(true); // End timer
            $latencyMs = round(($endTime - $startTime) * 1000); // Calculate latency in ms

            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode !== 200) {
                $error = curl_error($ch);
                curl_close($ch);
                echo "HTTP Code: " . $httpCode . "\n";
                echo "Response: " . $response . "\n";
                echo "Error: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
                return null;
            }
            
            curl_close($ch);
            
            $responseData = json_decode($response, true);
            
            // Save the full OCR response
            $timestamp = date('Y-m-d_H-i-s');
            $outputFile = $outputDir . "/mistral_ocr_response_{$timestamp}.json";
            file_put_contents($outputFile, json_encode($responseData, JSON_PRETTY_PRINT));
            
            echo "OCR response saved to: " . $outputFile . "\n";
            
            // Extract text from OCR response
            $extractedText = "Could not extract text from OCR results";
            
            if (isset($responseData['pages']) && !empty($responseData['pages'])) {
                $extractedText = "";
                
                // Create a file to save the markdown content
                $mdOutputFile = $outputDir . "/mistral_ocr_text_{$timestamp}.md";
                $mdFile = fopen($mdOutputFile, 'w');
                
                foreach ($responseData['pages'] as $page) {
                    // Extract content
                    if (isset($page['content'])) {
                        $extractedText .= $page['content'] . "\n";
                    }
                    
                    // Write markdown content to file
                    if (isset($page['markdown'])) {
                        fwrite($mdFile, $page['markdown'] . "\n");
                        // If no content was extracted but markdown is available, use it
                        if (empty($extractedText) && !empty($page['markdown'])) {
                            $extractedText = $page['markdown'];
                        }
                    }
                    
                    // Save images if they exist
                    if (isset($page['images']) && !empty($page['images'])) {
                        $imageDir = $outputDir . "/images_{$timestamp}";
                        if (!file_exists($imageDir)) {
                            mkdir($imageDir, 0755, true);
                        }
                        
                        foreach ($page['images'] as $index => $image) {
                            if (isset($image['image_base64'])) {
                                // Extract and save image
                                $imageData = explode(',', $image['image_base64'], 2);
                                $imageContent = base64_decode(end($imageData));
                                $imagePath = $imageDir . "/image_{$index}.png";
                                file_put_contents($imagePath, $imageContent);
                                echo "Saved image: $imagePath\n";
                            }
                        }
                    }
                }
                
                fclose($mdFile);
                
                // Verify the markdown file has content, if not, try to write it directly from the response
                if (filesize($mdOutputFile) == 0 && isset($responseData['pages'][0]['markdown'])) {
                    file_put_contents($mdOutputFile, $responseData['pages'][0]['markdown']);
                    echo "Wrote markdown directly from response.\n";
                }
                
                echo "Markdown content saved to: $mdOutputFile\n";
                
                if ($extractedText !== "Could not extract text from OCR results") {
                    echo "\nExtracted text from PDF via OCR:\n";
                    echo "---------------------------------\n";
                    echo substr($extractedText, 0, 500) . "...\n"; // Show first 500 chars
                    
                    $result = [
                        'extracted_text' => $extractedText,
                        'timestamp' => $timestamp,
                        'latency_ms' => $latencyMs // Add latency to the result
                    ];
                }
            }
        } 
        finally {
            // Clean up uploaded file if it was a local file
            if ($fileId) {
                echo "Cleaning up uploaded file...\n";
                $deleteResult = $this->fileUploader->deleteFileFromMistral($fileId);
                if ($deleteResult) {
                    echo "Successfully cleaned up the uploaded file.\n";
                } else {
                    echo "Failed to delete the uploaded file.\n";
                }
            }
        }
        
        return $result;
    }
    
    public function callApiWithoutEcho(string $prompt, string $systemPrompt): ?array {
        // For OCR, we don't need a separate without-echo version
        return null;
    }
} 