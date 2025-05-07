<?php

class ResponseFormatter {
    public static function formatBankAnalysisResults(array $results, string $provider, string $modelName, string $outputDir): void {
        $timestamp = date('Y-m-d_H-i-s');
        
        // Format and save JSON results
        $jsonOutputFile = $outputDir . "/bank_analysis_{$provider}_{$timestamp}.json";
        file_put_contents($jsonOutputFile, json_encode($results, JSON_PRETTY_PRINT));
        
        // Format and save Markdown report
        $mdOutputFile = $outputDir . "/bank_analysis_{$provider}_{$timestamp}.md";
        $mdContent = "# Bank Statement Analysis with {$provider} ({$modelName})\n\n";
        $mdContent .= "Analysis timestamp: " . date('Y-m-d H:i:s') . "\n\n";
        
        // Add summary section
        $mdContent .= "## Summary\n\n";
        $mdContent .= "- **Provider:** {$provider}\n";
        $mdContent .= "- **Model:** {$modelName}\n";
        $mdContent .= "- **Total Questions:** " . count($results) . "\n";
        $mdContent .= "- **Total Processing Time:** " . round($results['_summary']['total_time_ms']/1000, 2) . " seconds\n";
        $mdContent .= "- **Average Response Time:** " . round($results['_summary']['average_latency_ms']/1000, 2) . " seconds per question\n";
        $mdContent .= "- **Total Tokens:** " . number_format($results['_summary']['total_tokens']) . " (" . 
                     number_format($results['_summary']['total_tokens_in']) . " input, " . 
                     number_format($results['_summary']['total_tokens_out']) . " output)\n";
        $mdContent .= "\n---\n\n";
        
        // Add sections based on the section markers in the original questions
        $currentSection = "";
        $questionNum = 1;

        foreach ($results as $key => $value) {
            // Check if this is a section marker
            if (substr($key, 0, 9) === '_section_') {
                $currentSection = $value;
                $mdContent .= "## " . $currentSection . "\n\n";
            }
            // If it's a regular question and we have results for it
            elseif (substr($key, 0, 1) !== '_' && isset($results[$key])) {
                $result = $results[$key];
                
                $mdContent .= "### " . ucfirst(str_replace('_', ' ', $key)) . "\n\n";
                $mdContent .= "**Question #" . $questionNum++ . ":** " . $result['question'] . "\n\n";
                $mdContent .= "**Answer:** " . $result['answer'] . "\n\n";
                $mdContent .= "**Stats:** " . $result['latency_ms'] . "ms response time, " . 
                             $result['tokens_in'] . " input tokens, " . 
                             $result['tokens_out'] . " output tokens\n\n";
                $mdContent .= "---\n\n";
            }
        }
        
        file_put_contents($mdOutputFile, $mdContent);
        echo "Analysis report saved to: {$mdOutputFile}\n";
    }

    public function formatResults(array $results, string $outputDir, string $timestamp): void {
        foreach ($results as $provider => $result) {
            if (!$result) continue;

            $outputFile = $outputDir . "/{$provider}_response_{$timestamp}.txt";
            
            $content = "====== {$provider} Response ======\n\n";
            $content .= $result['content'] . "\n\n";
            
            // Add Latency if available
            if (isset($result['latency_ms'])) {
                $content .= "Latency: " . $result['latency_ms'] . " ms\n";
            }

            if (isset($result['tokens_in']) || isset($result['tokens_out'])) {
                $content .= "Token Usage:\n";
                if (isset($result['tokens_in'])) {
                    $content .= "Input tokens: " . $result['tokens_in'] . "\n";
                }
                if (isset($result['tokens_out'])) {
                    $content .= "Output tokens: " . $result['tokens_out'] . "\n";
                }
                // Add total tokens if both are present for non-OCR providers (OCR provider already has 'tokens_in' and 'tokens_out' as 0)
                if ($provider !== 'mistral-ocr' && isset($result['tokens_in']) && isset($result['tokens_out'])) {
                    $totalTokens = $result['tokens_in'] + $result['tokens_out'];
                    $content .= "Total tokens: " . $totalTokens . "\n";
                }
            }
            
            $content .= "\n"; // Add a newline for separation before next potential section

            file_put_contents($outputFile, $content);
            echo "Response saved to: {$outputFile}\n";
        }
    }
} 