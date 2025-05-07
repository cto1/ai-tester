#!/usr/bin/env php
<?php
/**
 * AI Provider Comparison Script
 * 
 * This script sends the same prompt to multiple AI providers (DeepSeek, OpenAI, Claude, and Mistral)
 * to extract information from financial statements through OCR and analysis.
 * 
 * Usage: 
 *   php ai_comparison.php                  - Run all AI providers
 *   php ai_comparison.php deepseek         - Run only DeepSeek
 *   php ai_comparison.php openai           - Run only OpenAI
 *   php ai_comparison.php claude           - Run only Claude
 *   php ai_comparison.php mistral          - Run only Mistral
 *   php ai_comparison.php mistral-ocr      - Run only Mistral OCR API (with URL)
 *   php ai_comparison.php mistral-ocr [filepath]  - Run Mistral OCR with local file
 *   php ai_comparison.php --use-ocr-for-all      - Run OCR first and use results for all providers
 *   php ai_comparison.php --use-md-file [mdfile]  - Use existing markdown file for all providers
 *   php ai_comparison.php --bank-analysis [provider] [mdfile]  - Run structured bank statement analysis with specified provider
 *   php ai_comparison.php --questions-file [file] - Use custom questions for bank analysis
 *   php ai_comparison.php --bank-analysis [provider] [mdfile] --questions-file [file] - Run custom questions analysis
 *   php ai_comparison.php deepseek openai  - Run DeepSeek and OpenAI
 * 
 * Flexible command ordering is supported. For example, these are equivalent:
 *   php ai_comparison.php --bank-analysis openai [mdfile]
 *   php ai_comparison.php openai --bank-analysis [mdfile]
 */

// Load environment variables from .env file if it exists
if (file_exists(__DIR__ . '/.env')) {
    $env = file_get_contents(__DIR__ . '/.env');
    $lines = explode("\n", $env);
    foreach ($lines as $line) {
        if (strlen(trim($line)) && strpos($line, '=') !== false && strpos($line, '#') !== 0) {
            list($key, $value) = explode('=', $line, 2);
            $_ENV[trim($key)] = trim($value);
            putenv(trim($key) . '=' . trim($value));
        }
    }
}

// Configuration - API keys from environment variables
$deepseekApiKey = getenv('DEEPSEEK_API_KEY');
$openaiApiKey = getenv('OPENAI_API_KEY');
$claudeApiKey = getenv('ANTHROPIC_API_KEY');
$mistralApiKey = getenv('MISTRAL_API_KEY');

// Output directory for saving responses
$outputDir = __DIR__ . '/ai_responses';
if (!file_exists($outputDir)) {
    mkdir($outputDir, 0755, true);
}

// PDF URL
$pdfUrl = 'https://fca124d70cadff4ca3b4a736c9c9e727.r2.cloudflarestorage.com/docuneat-r2-bucket/vault/org_67d48bd608f24/statement_sample1_1744573913_0.pdf?X-Amz-Content-Sha256=UNSIGNED-PAYLOAD&X-Amz-Algorithm=AWS4-HMAC-SHA256&X-Amz-Credential=ff44a24acb05653d98c5d43898978c85%2F20250506%2Fauto%2Fs3%2Faws4_request&X-Amz-Date=20250506T112013Z&X-Amz-SignedHeaders=host&X-Amz-Expires=3600&X-Amz-Signature=121ea8a7780b3ae0ae76dbf974026e5c84f759652e740947df5b36ee123163fd';
// Prompt
$prompt = "Please analyze this financial statement text. What's the balance?";
$systemPrompt = "You are a helpful AI assistant that analyzes financial documents and provides accurate information from them.";

// Parse command line arguments to determine which AI providers to run
$providersToRun = [];
$mistralOcrFilePath = null;
$useOcrForAll = false; // Flag to indicate if OCR results should be used for all models
$existingMdFile = null; // Path to existing markdown file
$bankAnalysis = false; // Flag to run bank statement analysis
$bankAnalysisProvider = null; // Provider to use for bank analysis
$customQuestions = null; // Custom questions file path

// Get and validate command line arguments
if ($argc === 1 || in_array($argv[1], ['-h', '--help'])) {
    echo "AI Provider Comparison Tool\n";
    echo "==========================\n";
    echo "Usage: php ai_comparison.php [provider1] [provider2] ...\n";
    echo "Available providers: deepseek, openai, claude, mistral, mistral-ocr\n\n";
    
    echo "Special options:\n";
    echo "  mistral-ocr [file path]   - Process a document with Mistral OCR\n";
    echo "  --use-ocr-for-all         - Use OCR text for all models\n";
    echo "  --use-md-file [file path] - Use content from existing markdown file\n";
    echo "  --bank-analysis [provider] [file path] - Run bank statement analysis with specified provider and markdown file\n";
    echo "  --questions-file [file path] - Use custom questions for bank analysis instead of default bank statement questions\n\n";
    
    echo "Example for OCR processing:\n";
    echo "  php ai_comparison.php mistral-ocr /path/to/document.pdf\n\n";
    
    echo "Example for bank statement analysis:\n";
    echo "  php ai_comparison.php --bank-analysis mistral /path/to/ocr_results.md\n";
    echo "  php ai_comparison.php mistral --bank-analysis /path/to/ocr_results.md\n\n";
    
    echo "Example with custom questions:\n";
    echo "  php ai_comparison.php --bank-analysis openai /path/to/ocr_results.md --questions-file /path/to/questions.json\n";
    echo "  php ai_comparison.php openai --bank-analysis /path/to/ocr_results.md --questions-file /path/to/questions.json\n\n";
    
    echo "Custom questions file format (JSON):\n";
    echo "{\n";
    echo "  \"business_name\": \"What is the business name on the account?\",\n";
    echo "  \"account_balance\": \"What is the final balance on the account?\",\n";
    echo "  \"custom_question_1\": \"Any specific question you want to ask about the document...\"\n";
    echo "}\n";
    echo "A sample questions file is available at: public_html/api/sample_questions.json\n";
    exit(0);
}

if ($argc > 1) {
    for ($i = 1; $i < $argc; $i++) {
        $arg = strtolower($argv[$i]);
        
        if ($arg === '--use-ocr-for-all') {
            $useOcrForAll = true;
            continue;
        }
        
        if ($arg === '--use-md-file' && isset($argv[$i + 1])) {
            $existingMdFile = $argv[$i + 1];
            $i++; // Skip the next argument as we've used it
            continue;
        }
        
        if ($arg === '--questions-file' && isset($argv[$i + 1])) {
            $customQuestions = $argv[$i + 1];
            $i++; // Skip the next argument as we've used it
            continue;
        }
        
        if ($arg === '--bank-analysis') {
            $bankAnalysis = true;
            
            // If next argument is a provider
            if (isset($argv[$i + 1]) && in_array($argv[$i + 1], ['deepseek', 'openai', 'claude', 'mistral'])) {
                $bankAnalysisProvider = $argv[$i + 1];
                $i++; // Skip the next argument
                
                // Get the markdown file path if available
                if (isset($argv[$i + 1]) && !in_array($argv[$i + 1], ['deepseek', 'openai', 'claude', 'mistral', 'mistral-ocr', '--use-ocr-for-all', '--use-md-file', '--bank-analysis', '--questions-file'])) {
                    $existingMdFile = $argv[$i + 1];
                    $i++; // Skip the next argument
                }
            } 
            // If bank analysis provider was specified earlier
            elseif (in_array('deepseek', $providersToRun) || in_array('openai', $providersToRun) || 
                   in_array('claude', $providersToRun) || in_array('mistral', $providersToRun)) {
                
                // Find the first valid provider in the list
                foreach (['deepseek', 'openai', 'claude', 'mistral'] as $possibleProvider) {
                    if (in_array($possibleProvider, $providersToRun)) {
                        $bankAnalysisProvider = $possibleProvider;
                        break;
                    }
                }
                
                // Get the markdown file path if available
                if (isset($argv[$i + 1]) && !in_array($argv[$i + 1], ['deepseek', 'openai', 'claude', 'mistral', 'mistral-ocr', '--use-ocr-for-all', '--use-md-file', '--bank-analysis', '--questions-file'])) {
                    $existingMdFile = $argv[$i + 1];
                    $i++; // Skip the next argument
                }
            }
            else {
                echo "Error: Missing provider for bank analysis. Use --bank-analysis [provider] [mdfile]\n";
                echo "Where provider is one of: deepseek, openai, claude, mistral\n";
                exit(1);
            }
            
            // Add the provider to the list if it's not already there
            if ($bankAnalysisProvider && !in_array($bankAnalysisProvider, $providersToRun)) {
                $providersToRun[] = $bankAnalysisProvider;
            }
            
            continue;
        }
        
        if ($arg === 'mistral-ocr' && isset($argv[$i + 1]) && 
            !in_array($argv[$i + 1], ['deepseek', 'openai', 'claude', 'mistral', 'mistral-ocr', '--use-ocr-for-all', '--use-md-file', '--bank-analysis', '--questions-file'])) {
            $providersToRun[] = $arg;
            $mistralOcrFilePath = $argv[$i + 1];
            $i++; // Skip the next argument as we've used it
        } elseif (in_array($arg, ['deepseek', 'openai', 'claude', 'mistral', 'mistral-ocr'])) {
            $providersToRun[] = $arg;
        }
    }
}

// If no specific providers were specified, run all of them
if (empty($providersToRun)) {
    $providersToRun = ['deepseek', 'openai', 'claude', 'mistral', 'mistral-ocr'];
}

// Check for required API keys and remove unavailable providers
$availableProviders = [];

if (in_array('deepseek', $providersToRun)) {
    if (!$deepseekApiKey) {
        echo "Warning: DEEPSEEK_API_KEY not found in environment variables. Skipping DeepSeek.\n";
    } else {
        $availableProviders[] = 'deepseek';
    }
}

if (in_array('openai', $providersToRun)) {
    if (!$openaiApiKey) {
        echo "Warning: OPENAI_API_KEY not found in environment variables. Skipping OpenAI.\n";
    } else {
        $availableProviders[] = 'openai';
    }
}

if (in_array('claude', $providersToRun)) {
    if (!$claudeApiKey) {
        echo "Warning: ANTHROPIC_API_KEY not found in environment variables. Skipping Claude.\n";
    } else {
        $availableProviders[] = 'claude';
    }
}

if (in_array('mistral', $providersToRun) || in_array('mistral-ocr', $providersToRun)) {
    if (!$mistralApiKey) {
        echo "Warning: MISTRAL_API_KEY not found in environment variables. Skipping Mistral and Mistral OCR.\n";
    } else {
        if (in_array('mistral', $providersToRun)) {
            $availableProviders[] = 'mistral';
        }
        if (in_array('mistral-ocr', $providersToRun)) {
            $availableProviders[] = 'mistral-ocr';
        }
    }
}

// Update the providers to run with only available ones
$providersToRun = $availableProviders;

if (empty($providersToRun) && !$existingMdFile) {
    echo "Error: No AI providers available to run. Please check your API keys.\n";
    exit(1);
}

// Display which providers will be run
echo "Running the following AI providers: " . implode(', ', $providersToRun) . "\n";
echo "Responses will be saved to: " . $outputDir . "\n";

// Execute calls to selected AI providers
$ocrExtractedText = null;
$ocrTimestamp = null;
$mdFilePath = null;

// If an existing markdown file was specified, use its content
if ($existingMdFile) {
    if (file_exists($existingMdFile)) {
        $mdContent = file_get_contents($existingMdFile);
        if (!empty($mdContent)) {
            echo "Using content from existing markdown file: $existingMdFile\n";
            $prompt = "Please analyze this financial statement text. What's the balance?\n\n" . $mdContent;
            $mdFilePath = $existingMdFile;
        } else {
            echo "Warning: Specified markdown file is empty.\n";
        }
    } else {
        echo "Error: Specified markdown file does not exist: $existingMdFile\n";
        exit(1);
    }
}
// Run Mistral OCR first if it's in the list or if we need to use OCR for all
elseif (in_array('mistral-ocr', $providersToRun) || $useOcrForAll) {
    // Use local file if provided, otherwise use URL
    if ($mistralOcrFilePath) {
        $ocrResult = callMistralOCR($mistralApiKey, $mistralOcrFilePath, $outputDir, true);
    } else {
        $ocrResult = callMistralOCR($mistralApiKey, $pdfUrl, $outputDir, false);
    }
    
    // If OCR was successful and we have text
    if ($ocrResult && isset($ocrResult['extracted_text']) && !empty($ocrResult['extracted_text'])) {
        $ocrExtractedText = $ocrResult['extracted_text'];
        $ocrTimestamp = $ocrResult['timestamp'];
        $mdFilePath = $outputDir . "/mistral_ocr_text_{$ocrTimestamp}.md";
        
        echo "\nOCR processing complete. Results will be used for all AI providers.\n";
        
        // Update the prompt to use the extracted text instead of the PDF URL
        $prompt = "Please analyze this financial statement text. What's the balance?\n\n" . $ocrExtractedText;
    }
}

// If we need to use OCR for all providers but OCR failed, exit
if ($useOcrForAll && !$ocrExtractedText) {
    echo "Error: OCR processing failed and --use-ocr-for-all flag was specified. Exiting.\n";
    exit(1);
}

// Now run the other AI providers
if (in_array('deepseek', $providersToRun)) {
    callDeepseekAI($deepseekApiKey, $prompt, $systemPrompt, $outputDir);
}

if (in_array('openai', $providersToRun)) {
    callOpenAI($openaiApiKey, $prompt, $systemPrompt, $outputDir);
}

if (in_array('claude', $providersToRun)) {
    callClaude($claudeApiKey, $prompt, $systemPrompt, $outputDir);
}

if (in_array('mistral', $providersToRun)) {
    callMistral($mistralApiKey, $prompt, $systemPrompt, $outputDir);
}

echo "\nComparison completed. All responses saved to: " . $outputDir . "\n";
if ($mdFilePath && file_exists($mdFilePath)) {
    echo "Markdown content from OCR is available at: " . $mdFilePath . "\n";
}

// Bank statement analysis questions
$bankStatementQuestions = [
    'business_name' => "What is the business name on the account? Answer only with the business name.",
    'other_accounts' => "Are there any other bank accounts listed on the statement for this business account? If yes, list only account number and sort code. If not, leave empty.",
    'person_name' => "Is there a name of the person associated to the business account listed? If yes, the answer should only be the name of the person. If not, leave empty.",
    'period_covered' => "What is the period covered by the bank statement?",
    'unpaid_transactions' => "In how many transactions in this statement description mentions unpaid or returned? Answer with a table with columns Number of transactions and total value. If there are none, answer with 0.",
    'hmrc_transactions' => "Create a table where you can categorise transactions where HMRC is mentioned. Answer with a table with following columns: Date, value, category (for example VAT, PAYE, NDDS).",
    'credit_from_owner' => "First find out if there is a name of the person associated to this business account. If yes, take that name and find any credit transactions from this person. Answer with a table with columns Number of transactions and total value.",
    'debit_to_owner' => "First find out if there is a name of the person associated to this business account. If yes, take that name and find any debit transactions from this person. Answer with a table with columns Number of transactions and total value.",
    'loan_debit' => "Create a table where you can show all DEBIT transactions where following keywords are mentioned: loan, FC, Iwoca, etc. Answer with a table with following columns: Date, value, Lender",
    'loan_credit' => "Create a table where you can show all CREDIT transactions where following keywords are mentioned: loan, FC, Iwoca, etc. Answer with a table with following columns: Date, value, Lender",
    'first_dd' => "Create a table where you can show all DEBIT transactions indicating first payment of a direct debit. Answer with a table with following columns: Date, value, Payee",
    'overdraft_limit' => "What is the arranged overdraft limit for this business account? Answer with a pound value.",
    'overdraft_exceeded' => "On how many days was the overdraft limit exceeded? Answer only with a number.",
    'avg_daily_balance' => "Calculate average daily balance for this statement using this formula: the total of all the end of day balances for the period covered by the bank statement divided by the number of end of day balances on the bank statement. Answer with a pound value only.",
    'total_credit' => "Calculate total credit transactions excluding the ones mentioning loan, FC, Iwoca or account transfers. Answer with a pound value only.",
    'total_debit' => "First find out if there is a name of the person associated to this business account. If yes, take that name and find any debit transactions from this person. Then calculate total debit transactions excluding the ones associated to the person owning this business account or account transfers. Answer with a pound value only.",
    'recurring_credit' => "Find out if there are any recurring credit transactions. Answer with a table where each vendor will be one row and value will be total for the vendor for all transactions with the columns Vendor and Value.",
    'recurring_debit' => "Find out if there are any recurring debit transactions. Answer with a table where each vendor will be one row and value will be total for the vendor for all transactions with the columns Vendor and Value."
];

// Function to run bank statement analysis
function runBankStatementAnalysis($provider, $apiKey, $statementContent, $outputDir, $questions) {
    $timestamp = date('Y-m-d_H-i-s');
    $outputFile = $outputDir . "/bank_analysis_{$provider}_{$timestamp}.json";
    $results = [];
    $totalTokensIn = 0;
    $totalTokensOut = 0;
    $totalLatency = 0;
    $startTime = microtime(true);
    
    // Get the model name for each provider
    $modelName = "";
    switch ($provider) {
        case 'deepseek':
            $modelName = "deepseek-chat";
            break;
        case 'openai':
            $modelName = "gpt-4o";
            break;
        case 'claude':
            $modelName = "claude-3-opus-20240229";
            break;
        case 'mistral':
            $modelName = "mistral-large-latest";
            break;
    }
    
    echo "\n====== Bank Statement Analysis with {$provider} ({$modelName}) ======\n";
    echo "Running analysis with " . count($questions) . " questions...\n";
    
    // Filter out documentation and section marker keys (those starting with _)
    $questionsToProcess = array_filter($questions, function($key) {
        return substr($key, 0, 1) !== '_';
    }, ARRAY_FILTER_USE_KEY);
    
    echo "Processing " . count($questionsToProcess) . " actual questions (skipping " . 
         (count($questions) - count($questionsToProcess)) . " documentation/section markers)...\n";
    
    $questionNumber = 1;
    foreach ($questionsToProcess as $key => $question) {
        echo "Processing question {$questionNumber}/" . count($questionsToProcess) . ": " . substr($question, 0, 50) . "...\n";
        
        $prompt = "Below is the text extracted from a bank statement using OCR. Please analyze it and answer the following question:\n\n";
        $prompt .= "QUESTION: {$question}\n\n";
        $prompt .= "BANK STATEMENT TEXT:\n{$statementContent}\n\n";
        $prompt .= "Your answer:";
        
        $systemPrompt = "You are a financial analyst specializing in bank statement analysis. Provide accurate, clear, and concise responses to questions about bank statements. Only answer the specific question asked without additional commentary.";
        
        $answer = null;
        $tokensIn = 0;
        $tokensOut = 0;
        $requestStartTime = microtime(true);
        
        switch ($provider) {
            case 'deepseek':
                $result = callDeepseekAIWithoutEcho($apiKey, $prompt, $systemPrompt);
                if ($result) {
                    $answer = $result['content'];
                    $tokensIn = $result['tokens_in'] ?? 0;
                    $tokensOut = $result['tokens_out'] ?? 0;
                }
                break;
            case 'openai':
                $result = callOpenAIWithoutEcho($apiKey, $prompt, $systemPrompt);
                if ($result) {
                    $answer = $result['content'];
                    $tokensIn = $result['tokens_in'] ?? 0;
                    $tokensOut = $result['tokens_out'] ?? 0;
                }
                break;
            case 'claude':
                $result = callClaudeWithoutEcho($apiKey, $prompt, $systemPrompt);
                if ($result) {
                    $answer = $result['content'];
                    $tokensIn = $result['tokens_in'] ?? 0;
                    $tokensOut = $result['tokens_out'] ?? 0;
                }
                break;
            case 'mistral':
                $result = callMistralWithoutEcho($apiKey, $prompt, $systemPrompt);
                if ($result) {
                    $answer = $result['content'];
                    $tokensIn = $result['tokens_in'] ?? 0;
                    $tokensOut = $result['tokens_out'] ?? 0;
                }
                break;
        }
        
        $requestEndTime = microtime(true);
        $latency = round(($requestEndTime - $requestStartTime) * 1000); // in milliseconds
        $totalLatency += $latency;
        $totalTokensIn += $tokensIn;
        $totalTokensOut += $tokensOut;
        
        if ($answer) {
            $results[$key] = [
                'question' => $question,
                'answer' => $answer,
                'latency_ms' => $latency,
                'tokens_in' => $tokensIn,
                'tokens_out' => $tokensOut
            ];
            echo "Answer received (" . strlen($answer) . " chars) in {$latency}ms - Tokens: {$tokensIn} in, {$tokensOut} out\n";
        } else {
            $results[$key] = [
                'question' => $question,
                'answer' => "ERROR: Failed to get response from {$provider}.",
                'latency_ms' => $latency,
                'tokens_in' => 0,
                'tokens_out' => 0
            ];
            echo "Failed to get answer from {$provider} after {$latency}ms\n";
        }
        
        // Add a small delay to avoid rate limits
        sleep(1);
        $questionNumber++;
    }
    
    // Add any documentation and section markers from the original questions to the results
    foreach ($questions as $key => $value) {
        if (substr($key, 0, 1) === '_') {
            $results[$key] = $value;
        }
    }
    
    $endTime = microtime(true);
    $totalTime = round(($endTime - $startTime) * 1000); // in milliseconds
    
    // Add summary statistics
    $results['_summary'] = [
        'provider' => $provider,
        'model' => $modelName,
        'total_questions' => count($questionsToProcess),
        'total_latency_ms' => $totalLatency,
        'average_latency_ms' => round($totalLatency / count($questionsToProcess)),
        'total_time_ms' => $totalTime,
        'total_tokens_in' => $totalTokensIn,
        'total_tokens_out' => $totalTokensOut,
        'total_tokens' => $totalTokensIn + $totalTokensOut,
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // Save results to JSON file
    file_put_contents($outputFile, json_encode($results, JSON_PRETTY_PRINT));
    echo "Analysis results saved to: {$outputFile}\n";
    
    // Also create a markdown report
    $mdOutputFile = $outputDir . "/bank_analysis_{$provider}_{$timestamp}.md";
    $mdContent = "# Bank Statement Analysis with {$provider} ({$modelName})\n\n";
    $mdContent .= "Analysis timestamp: " . date('Y-m-d H:i:s') . "\n\n";
    
    // Add summary section
    $mdContent .= "## Summary\n\n";
    $mdContent .= "- **Provider:** {$provider}\n";
    $mdContent .= "- **Model:** {$modelName}\n";
    $mdContent .= "- **Total Questions:** " . count($questionsToProcess) . "\n";
    $mdContent .= "- **Total Processing Time:** " . round($totalTime/1000, 2) . " seconds\n";
    $mdContent .= "- **Average Response Time:** " . round($totalLatency / count($questionsToProcess)/1000, 2) . " seconds per question\n";
    $mdContent .= "- **Total Tokens:** " . number_format($totalTokensIn + $totalTokensOut) . " (" . number_format($totalTokensIn) . " input, " . number_format($totalTokensOut) . " output)\n";
    $mdContent .= "\n---\n\n";
    
    // Add sections based on the section markers in the original questions
    $currentSection = "";
    $questionNum = 1;

    foreach ($questions as $key => $value) {
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
    
    // Print summary stats
    echo "\n====== Analysis Summary ======\n";
    echo "Provider: {$provider} ({$modelName})\n";
    echo "Total processing time: " . round($totalTime/1000, 2) . " seconds\n";
    echo "Average response time: " . round($totalLatency / count($questionsToProcess)/1000, 2) . " seconds per question\n";
    echo "Total tokens: " . number_format($totalTokensIn + $totalTokensOut) . " (" . 
         number_format($totalTokensIn) . " input, " . 
         number_format($totalTokensOut) . " output)\n";
    
    return $results;
}

// Update for callDeepseekAIWithoutEcho
function callDeepseekAIWithoutEcho($apiKey, $prompt, $systemPrompt) {
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
    $data = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 1000
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying DeepSeek request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['choices'][0]['message']['content'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from DeepSeek after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $tokensIn = 0;
    $tokensOut = 0;
    
    if (isset($responseData['usage'])) {
        $tokensIn = $responseData['usage']['prompt_tokens'] ?? 0;
        $tokensOut = $responseData['usage']['completion_tokens'] ?? 0;
    }
    
    return [
        'content' => $responseData['choices'][0]['message']['content'],
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut
    ];
}

// Update for callClaudeWithoutEcho
function callClaudeWithoutEcho($apiKey, $prompt, $systemPrompt) {
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
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
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying Claude request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['content'][0]['text'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from Claude after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $tokensIn = 0;
    $tokensOut = 0;
    
    if (isset($responseData['usage'])) {
        $tokensIn = $responseData['usage']['input_tokens'] ?? 0;
        $tokensOut = $responseData['usage']['output_tokens'] ?? 0;
    }
    
    return [
        'content' => $responseData['content'][0]['text'],
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut
    ];
}

// Update for callMistralWithoutEcho
function callMistralWithoutEcho($apiKey, $prompt, $systemPrompt) {
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
    $data = [
        'model' => 'mistral-large-latest',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 1000
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying Mistral request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['choices'][0]['message']['content'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from Mistral after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $tokensIn = 0;
    $tokensOut = 0;
    
    if (isset($responseData['usage'])) {
        $tokensIn = $responseData['usage']['prompt_tokens'] ?? 0;
        $tokensOut = $responseData['usage']['completion_tokens'] ?? 0;
    }
    
    return [
        'content' => $responseData['choices'][0]['message']['content'],
        'tokens_in' => $tokensIn,
        'tokens_out' => $tokensOut
    ];
}

// If bank analysis is requested, perform it
if ($bankAnalysis) {
    if (!$existingMdFile) {
        echo "Error: Bank statement analysis requires a markdown file with OCR results.\n";
        echo "Use: php ai_comparison.php --bank-analysis [provider] [mdfile]\n";
        exit(1);
    }
    
    if (!file_exists($existingMdFile)) {
        echo "Error: Specified markdown file does not exist: $existingMdFile\n";
        exit(1);
    }
    
    $mdContent = file_get_contents($existingMdFile);
    if (empty($mdContent)) {
        echo "Warning: Specified markdown file is empty.\n";
        exit(1);
    }
    
    echo "Using content from existing markdown file: $existingMdFile\n";
    
    // Get the right API key
    $apiKey = null;
    switch ($bankAnalysisProvider) {
        case 'deepseek':
            $apiKey = $deepseekApiKey;
            break;
        case 'openai':
            $apiKey = $openaiApiKey;
            break;
        case 'claude':
            $apiKey = $claudeApiKey;
            break;
        case 'mistral':
            $apiKey = $mistralApiKey;
            break;
    }
    
    if (!$apiKey) {
        echo "Error: API key not found for provider: $bankAnalysisProvider\n";
        exit(1);
    }
    
    // Use custom questions if provided
    $questionsToUse = $bankStatementQuestions;
    
    if ($customQuestions) {
        if (!file_exists($customQuestions)) {
            echo "Error: Custom questions file does not exist: $customQuestions\n";
            exit(1);
        }
        
        try {
            $customQuestionsData = json_decode(file_get_contents($customQuestions), true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo "Error: Custom questions file is not valid JSON: " . json_last_error_msg() . "\n";
                exit(1);
            }
            
            if (!is_array($customQuestionsData) || empty($customQuestionsData)) {
                echo "Error: Custom questions file should contain a JSON object with question keys and text.\n";
                exit(1);
            }
            
            $questionsToUse = $customQuestionsData;
            echo "Using " . count($questionsToUse) . " custom questions from file: $customQuestions\n";
        } catch (Exception $e) {
            echo "Error reading custom questions file: " . $e->getMessage() . "\n";
            exit(1);
        }
    } else {
        echo "Using " . count($questionsToUse) . " default bank statement questions.\n";
    }
    
    // Run the bank statement analysis
    $analysis = runBankStatementAnalysis($bankAnalysisProvider, $apiKey, $mdContent, $outputDir, $questionsToUse);
    exit(0);
}

// Function to call DeepSeek AI API
function callDeepseekAI($apiKey, $prompt, $systemPrompt, $outputDir) {
    echo "\n====== DeepSeek AI ======\n";
    echo "Sending request to DeepSeek AI...\n";
    
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
    $data = [
        'model' => 'deepseek-chat',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 500
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying DeepSeek request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.deepseek.com/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['choices'][0]['message']['content'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from DeepSeek after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $result = $responseData['choices'][0]['message']['content'];
    
    echo "\nResponse:\n";
    echo $result . "\n";
    
    if (isset($responseData['usage'])) {
        echo "\nToken Usage:\n";
        echo "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n";
        echo "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n";
        echo "Total tokens: " . $responseData['usage']['total_tokens'] . "\n";
    }
    
    // Save output to file
    $timestamp = date('Y-m-d_H-i-s');
    $outputFile = $outputDir . "/deepseek_response_{$timestamp}.txt";
    file_put_contents($outputFile, "====== DeepSeek AI Response ======\n\n" . $result . "\n\n");
    
    if (isset($responseData['usage'])) {
        file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
        file_put_contents($outputFile, "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Total tokens: " . $responseData['usage']['total_tokens'] . "\n", FILE_APPEND);
    }
    
    echo "Response saved to: " . $outputFile . "\n";
    
    return $result;
}

// Function to call OpenAI API
function callOpenAI($apiKey, $prompt, $systemPrompt, $outputDir) {
    echo "\n====== OpenAI ======\n";
    echo "Sending request to OpenAI...\n";
    
    $data = [
        'model' => 'gpt-4o',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 500
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
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
    
    if (!isset($responseData['choices'][0]['message']['content'])) {
        echo "Unexpected response format. Full response: " . json_encode($responseData, JSON_PRETTY_PRINT) . "\n";
        return null;
    }
    
    $result = $responseData['choices'][0]['message']['content'];
    
    echo "\nResponse:\n";
    echo $result . "\n";
    
    if (isset($responseData['usage'])) {
        echo "\nToken Usage:\n";
        echo "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n";
        echo "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n";
        echo "Total tokens: " . $responseData['usage']['total_tokens'] . "\n";
    }
    
    // Save output to file
    $timestamp = date('Y-m-d_H-i-s');
    $outputFile = $outputDir . "/openai_response_{$timestamp}.txt";
    file_put_contents($outputFile, "====== OpenAI Response ======\n\n" . $result . "\n\n");
    
    if (isset($responseData['usage'])) {
        file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
        file_put_contents($outputFile, "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Total tokens: " . $responseData['usage']['total_tokens'] . "\n", FILE_APPEND);
    }
    
    echo "Response saved to: " . $outputFile . "\n";
    
    return $result;
}

// Function to call Claude (Anthropic) API
function callClaude($apiKey, $prompt, $systemPrompt, $outputDir) {
    echo "\n====== Claude (Anthropic) ======\n";
    echo "Sending request to Claude (Anthropic)...\n";
    
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
    $data = [
        'model' => 'claude-3-opus-20240229',
        'system' => $systemPrompt, // System prompt as top-level parameter
        'messages' => [
            // Only user message here, no system role
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 500
    ];
    
    $headers = [
        'x-api-key: ' . $apiKey,
        'anthropic-version: 2023-06-01',
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying Claude request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['content'][0]['text'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from Claude after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $result = $responseData['content'][0]['text'];
    
    echo "\nResponse:\n";
    echo $result . "\n";
    
    if (isset($responseData['usage'])) {
        echo "\nToken Usage:\n";
        echo "Input tokens: " . $responseData['usage']['input_tokens'] . "\n";
        echo "Output tokens: " . $responseData['usage']['output_tokens'] . "\n";
    }
    
    // Save output to file
    $timestamp = date('Y-m-d_H-i-s');
    $outputFile = $outputDir . "/claude_response_{$timestamp}.txt";
    file_put_contents($outputFile, "====== Claude (Anthropic) Response ======\n\n" . $result . "\n\n");
    
    if (isset($responseData['usage'])) {
        file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
        file_put_contents($outputFile, "Input tokens: " . $responseData['usage']['input_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Output tokens: " . $responseData['usage']['output_tokens'] . "\n", FILE_APPEND);
    }
    
    echo "Response saved to: " . $outputFile . "\n";
    
    return $result;
}

// Function to call Mistral API with chat capabilities
function callMistral($apiKey, $prompt, $systemPrompt, $outputDir) {
    echo "\n====== Mistral AI ======\n";
    echo "Sending request to Mistral AI...\n";
    
    $maxRetries = 3;
    $retryCount = 0;
    $success = false;
    $response = null;
    $responseData = null;
    $httpCode = 0;
    
    $data = [
        'model' => 'mistral-large-latest',
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 500
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    // Implement exponential backoff retry logic
    while ($retryCount <= $maxRetries && !$success) {
        // If this is a retry, wait with exponential backoff
        if ($retryCount > 0) {
            $sleepTime = pow(2, $retryCount - 1); // 1, 2, 4 seconds...
            echo "Retrying Mistral request ($retryCount/$maxRetries) after {$sleepTime}s delay...\n";
            sleep($sleepTime);
        }
        
        $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        // Check if request was successful
        if ($httpCode === 200) {
            $responseData = json_decode($response, true);
            
            if (isset($responseData['choices'][0]['message']['content'])) {
                $success = true;
                break;
            }
        }
        
        // Output error information on retry
        if ($retryCount < $maxRetries) {
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
    
    // If all retries failed
    if (!$success) {
        echo "Failed to get response from Mistral after $maxRetries retries.\n";
        if (!empty($error)) {
            echo "Last error: " . $error . "\n";
        }
        if (!empty($response)) {
            echo "Last response: " . $response . "\n";
        }
        echo "Last HTTP code: " . $httpCode . "\n";
        return null;
    }
    
    $result = $responseData['choices'][0]['message']['content'];
    
    echo "\nResponse:\n";
    echo $result . "\n";
    
    if (isset($responseData['usage'])) {
        echo "\nToken Usage:\n";
        echo "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n";
        echo "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n";
        echo "Total tokens: " . $responseData['usage']['total_tokens'] . "\n";
    }
    
    // Save output to file
    $timestamp = date('Y-m-d_H-i-s');
    $outputFile = $outputDir . "/mistral_response_{$timestamp}.txt";
    file_put_contents($outputFile, "====== Mistral AI Response ======\n\n" . $result . "\n\n");
    
    if (isset($responseData['usage'])) {
        file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
        file_put_contents($outputFile, "Prompt tokens: " . $responseData['usage']['prompt_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Completion tokens: " . $responseData['usage']['completion_tokens'] . "\n", FILE_APPEND);
        file_put_contents($outputFile, "Total tokens: " . $responseData['usage']['total_tokens'] . "\n", FILE_APPEND);
    }
    
    echo "Response saved to: " . $outputFile . "\n";
    
    return $result;
}

// Function to upload a file to Mistral and get a signed URL
function uploadToMistral($apiKey, $filePath) {
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
        'purpose' => 'ocr' // Purpose should be 'ocr' for OCR processing
    ];
    
    $headers = [
        'Authorization: Bearer ' . $apiKey
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
        echo "HTTP Code: " . $httpCode . "\n";
        echo "Response: " . $response . "\n";
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
    echo "Getting signed URL for file...\n";
    
    $ch = curl_init("https://api.mistral.ai/v1/files/{$fileId}/url");
    
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($httpCode !== 200) {
        $error = curl_error($ch);
        curl_close($ch);
        echo "HTTP Code: " . $httpCode . "\n";
        echo "Response: " . $response . "\n";
        echo "Error getting signed URL: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
        return null;
    }
    
    curl_close($ch);
    
    $urlResponseData = json_decode($response, true);
    
    if (!isset($urlResponseData['url'])) {
        echo "Unexpected URL response format. Full response: " . json_encode($urlResponseData, JSON_PRETTY_PRINT) . "\n";
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

// Function to delete a file from Mistral
function deleteFileFromMistral($apiKey, $fileId) {
    echo "\n====== Deleting Mistral File ======\n";
    echo "Deleting file ID: $fileId...\n";
    
    $headers = [
        'Authorization: Bearer ' . $apiKey,
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
        echo "HTTP Code: " . $httpCode . "\n";
        echo "Response: " . $response . "\n";
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

// Function to call Mistral OCR API with either a URL or local file
function callMistralOCR($apiKey, $pdfSource, $outputDir, $isLocalFile = false) {
    echo "\n====== Mistral OCR API ======\n";
    
    $fileUrl = null;
    $fileId = null;
    $result = null;
    
    try {
        // If we're processing a local file, upload it to Mistral first
        if ($isLocalFile) {
            echo "Uploading local file to Mistral first...\n";
            $uploadResult = uploadToMistral($apiKey, $pdfSource);
            
            if (!$uploadResult) {
                echo "Failed to upload file to Mistral. Cannot proceed with OCR.\n";
                return null;
            }
            
            // Use the signed URL from the upload result
            $fileUrl = $uploadResult['url'];
            $fileId = $uploadResult['file_id']; // Save file ID for later deletion
            echo "Using uploaded file URL for OCR: " . $fileUrl . "\n";
        } else {
            $fileUrl = $pdfSource;
        }
        
        echo "Sending request to Mistral OCR API...\n";
        
        // Follow the Python example for OCR request format
        $data = [
            'model' => 'mistral-ocr-latest',
            'document' => [
                'type' => 'document_url',
                'document_url' => $fileUrl
            ],
            'include_image_base64' => true
        ];
        
        // Debug the request
        echo "OCR Request: " . json_encode($data, JSON_PRETTY_PRINT) . "\n";
        
        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init('https://api.mistral.ai/v1/ocr');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120); // Longer timeout for OCR
        
        $response = curl_exec($ch);
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
        
        // Extract text from OCR response like in the Python example
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
                
                // Write markdown content to file (like in Python example)
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
            
            // Display some debug information
            echo "Response structure: " . json_encode(array_keys($responseData)) . "\n";
            if (isset($responseData['pages'][0])) {
                echo "First page keys: " . json_encode(array_keys($responseData['pages'][0])) . "\n";
            }
        
            if ($extractedText !== "Could not extract text from OCR results") {
                echo "\nExtracted text from PDF via OCR:\n";
                echo "---------------------------------\n";
                echo substr($extractedText, 0, 500) . "...\n"; // Show first 500 chars
                
                // Now ask Mistral about the balance using the extracted text
                $ocrPrompt = "I have extracted the following text from a financial statement using OCR. Please analyze it and tell me what the balance is:\n\n" . $extractedText;
                
                $data = [
                    'model' => 'mistral-medium',
                    'messages' => [
                        ['role' => 'system', 'content' => "You are a helpful assistant that analyzes financial documents and provides accurate information from them."],
                        ['role' => 'user', 'content' => $ocrPrompt]
                    ],
                    'temperature' => 0.2,
                    'max_tokens' => 500
                ];
                
                $headers = [
                    'Authorization: Bearer ' . $apiKey,
                    'Content-Type: application/json'
                ];
                
                $ch = curl_init('https://api.mistral.ai/v1/chat/completions');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
                
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($httpCode !== 200) {
                    $error = curl_error($ch);
                    curl_close($ch);
                    echo "Error analyzing OCR results: " . ($error ?: 'HTTP Code ' . $httpCode) . "\n";
                } else {
                    curl_close($ch);
                    
                    $analysisData = json_decode($response, true);
                    
                    if (isset($analysisData['choices'][0]['message']['content'])) {
                        $analysisResult = $analysisData['choices'][0]['message']['content'];
                        
                        echo "\nAnalysis of OCR results:\n";
                        echo $analysisResult . "\n";
                        
                        // Save the analysis to a file
                        $outputFile = $outputDir . "/mistral_ocr_analysis_{$timestamp}.txt";
                        file_put_contents($outputFile, "====== Mistral OCR Analysis ======\n\n" . $analysisResult . "\n\n");
                        
                        if (isset($analysisData['usage'])) {
                            file_put_contents($outputFile, "Token Usage:\n", FILE_APPEND);
                            file_put_contents($outputFile, "Prompt tokens: " . $analysisData['usage']['prompt_tokens'] . "\n", FILE_APPEND);
                            file_put_contents($outputFile, "Completion tokens: " . $analysisData['usage']['completion_tokens'] . "\n", FILE_APPEND);
                            file_put_contents($outputFile, "Total tokens: " . $analysisData['usage']['total_tokens'] . "\n", FILE_APPEND);
                        }
                        
                        echo "OCR analysis saved to: " . $outputFile . "\n";
                        
                        $result = [
                            'extracted_text' => $extractedText,
                            'analysis' => $analysisResult,
                            'timestamp' => $timestamp
                        ];
                    }
                }
            } else {
                echo "No text was extracted from the OCR results.\n";
            }
        } else {
            echo "No page content found in OCR response.\n";
        }
    } 
    finally {
        // This code will execute regardless of success or failure
        if ($isLocalFile && $fileId) {
            // Delete the file from Mistral
            echo "Cleaning up uploaded file...\n";
            $deleteResult = deleteFileFromMistral($apiKey, $fileId);
            if ($deleteResult) {
                echo "Successfully cleaned up the uploaded file.\n";
            } else {
                echo "Failed to delete the uploaded file.\n";
            }
        }
    }
    
    return $result;
} 