<?php

class FileUploader {
    private $apiKey;
    
    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function uploadToMistral(string $filePath): ?array {
        echo "\n====== Mistral File Upload ======\n";
        echo "Uploading file to Mistral...\n";
        
        // Check if file exists
        if (!file_exists($filePath)) {
            echo "Error: File not found at path: $filePath\n";
            return null;
        }
        
        // Upload the file to Mistral
        $fileName = basename($filePath);
        $ch = curl_init('https://api.mistral.ai/v1/files');
        
        // Prepare file data for upload
        $cFile = new CURLFile($filePath, mime_content_type($filePath), $fileName);
        $data = [
            'file' => $cFile,
            'purpose' => 'ocr'
        ];
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey
        ];
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $error = curl_error($ch);
            curl_close($ch);
            // echo "HTTP Code: " . $httpCode . "\n";
            // echo "Response: " . $response . "\n";
            echo "Error uploading file: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
            return null;
        }
        
        curl_close($ch);
        
        $uploadResponseData = json_decode($response, true);
        
        if (!isset($uploadResponseData['id'])) {
            echo "Unexpected upload response format. Full response: " . json_encode($uploadResponseData, JSON_PRETTY_PRINT) . "\n";
            return null;
        }
        
        $fileId = $uploadResponseData['id'];
        echo "File uploaded successfully! File ID: " . $fileId . "\n";
        
        // Get the signed URL for the uploaded file
        // echo "Getting signed URL for file...\n";
        
        $ch = curl_init("https://api.mistral.ai/v1/files/{$fileId}/url");
        
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $error = curl_error($ch);
            curl_close($ch);
            // echo "HTTP Code: " . $httpCode . "\n";
            // echo "Response: " . $response . "\n";
            echo "Error getting signed URL: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
            return null;
        }
        
        curl_close($ch);
        
        $urlResponseData = json_decode($response, true);
        
        if (!isset($urlResponseData['url'])) {
            // echo "Unexpected URL response format. Full response: " . json_encode($urlResponseData, JSON_PRETTY_PRINT) . "\n";
            return null;
        }
        
        $signedUrl = $urlResponseData['url'];
        echo "Got signed URL successfully: " . $signedUrl . "\n";
        
        return [
            'file_id' => $fileId,
            'url' => $signedUrl,
            'file_name' => $fileName
        ];
    }
    
    public function deleteFileFromMistral(string $fileId): bool {
        echo "\n====== Deleting Mistral File ======\n";
        echo "Deleting file ID: $fileId...\n";
        
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init("https://api.mistral.ai/v1/files/{$fileId}");
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        if ($httpCode !== 200) {
            $error = curl_error($ch);
            curl_close($ch);
            // echo "HTTP Code: " . $httpCode . "\n";
            // echo "Response: " . $response . "\n";
            echo "Error deleting file: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
            return false;
        }
        
        curl_close($ch);
        
        $deleteResponseData = json_decode($response, true);
        
        if (isset($deleteResponseData['deleted']) && $deleteResponseData['deleted'] === true) {
            echo "File deleted successfully!\n";
            return true;
        } else {
            echo "Unexpected delete response: " . json_encode($deleteResponseData, JSON_PRETTY_PRINT) . "\n";
            return false;
        }
    }
} 