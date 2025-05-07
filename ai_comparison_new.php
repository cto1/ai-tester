<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/providers/AiProviderInterface.php';
require_once __DIR__ . '/providers/OpenaiProvider.php';
require_once __DIR__ . '/providers/ClaudeProvider.php';
require_once __DIR__ . '/providers/MistralProvider.php';
require_once __DIR__ . '/providers/MistralOcrProvider.php';
require_once __DIR__ . '/providers/DeepseekProvider.php';
require_once __DIR__ . '/utils/ResponseFormatter.php';
require_once __DIR__ . '/config/BankStatementQuestions.php';

try {
    // Load environment variables
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    
    // Required environment variables
    $dotenv->required([
        'OPENAI_API_KEY',
        'CLAUDE_API_KEY',
        'MISTRAL_API_KEY',
        'DEEPSEEK_API_KEY'
    ]);
    
    // Configuration
    $config = [
        'openai' => [
            'api_key' => $_ENV['OPENAI_API_KEY'],
            'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4-turbo-preview'
        ],
        'claude' => [
            'api_key' => $_ENV['CLAUDE_API_KEY'],
            'model' => $_ENV['CLAUDE_MODEL'] ?? 'claude-3-opus-20240229'
        ],
        'mistral' => [
            'api_key' => $_ENV['MISTRAL_API_KEY'],
            'model' => $_ENV['MISTRAL_MODEL'] ?? 'mistral-large-latest'
        ],
        'deepseek' => [
            'api_key' => $_ENV['DEEPSEEK_API_KEY'],
            'model' => $_ENV['DEEPSEEK_MODEL'] ?? 'deepseek-chat'
        ]
    ];
    
    // Initialize providers
    $allProviders = [
        'openai' => new OpenaiProvider($config['openai']['api_key']),
        'claude' => new ClaudeProvider($config['claude']['api_key']),
        'mistral' => new MistralProvider($config['mistral']['api_key']),
        'deepseek' => new DeepseekProvider($config['deepseek']['api_key'])
    ];
    
    // Initialize OCR provider
    $ocrProvider = new MistralOcrProvider($config['mistral']['api_key']);
    
    // Initialize response formatter
    $formatter = new ResponseFormatter();
    
    // Get the input file or text
    $input = $_POST['input'] ?? '';
    if (empty($input)) {
        throw new RuntimeException("No input provided. Please provide either a file path or text input.");
    }

    // Get selected providers
    $selectedProviders = $_POST['providers'] ?? array_keys($allProviders);
    if (!is_array($selectedProviders)) {
        $selectedProviders = [$selectedProviders];
    }
    
    // Check if input is a file path and read its contents
    if (file_exists($input) && !in_array('mistral-ocr', $selectedProviders)) {
        $fileContents = file_get_contents($input);
        if ($fileContents === false) {
            throw new RuntimeException("Failed to read file: {$input}");
        }
        $input = $fileContents;
    }
    
    // Get providers to use
    $providers = array_intersect_key($allProviders, array_flip($selectedProviders));
    
    // Create base output directory
    $baseOutputDir = __DIR__ . "/test_results";
    if (!file_exists($baseOutputDir)) {
        if (!mkdir($baseOutputDir, 0755, true)) {
            throw new RuntimeException("Failed to create base output directory: {$baseOutputDir}");
        }
    }

    // Create timestamped output directory
    $timestamp = date('Y-m-d_H-i-s');
    $outputDir = $baseOutputDir . "/run_{$timestamp}";
    if (!file_exists($outputDir)) {
        if (!mkdir($outputDir, 0755, true)) {
            throw new RuntimeException("Failed to create output directory: {$outputDir}");
        }
    }
    
    // Handle OCR processing
    $useOcrForAll = $_POST['use_ocr_for_all'] ?? false;
    $isFile = file_exists($input);
    $extractedText = null;
    
    if (in_array('mistral-ocr', $selectedProviders)) {
        echo "Processing file with OCR...\n";
        $ocrResult = $ocrProvider->callApi($input, '', $outputDir);
        if ($ocrResult && isset($ocrResult['extracted_text'])) {
            $extractedText = $ocrResult['extracted_text'];
            if (isset($ocrResult['latency_ms'])) {
                echo "OCR Latency: " . $ocrResult['latency_ms'] . " ms\n";
            }
            if ($useOcrForAll) {
                $input = $extractedText;
            }
            // Add OCR result to results array
            $results['mistral-ocr'] = [
                'content' => $extractedText,
                'tokens_in' => 0,
                'tokens_out' => 0,
                'latency_ms' => $ocrResult['latency_ms'] ?? 0
            ];
        } else {
            throw new RuntimeException("Failed to extract text from file using OCR: {$input}");
        }
    }
    
    // Handle bank statement analysis
    $bankAnalysis = $_POST['bank_analysis'] ?? false;
    $bankAnalysisProvider = $_POST['bank_analysis_provider'] ?? null;
    $questionsFile = $_POST['questions_file'] ?? null;
    
    $results = [];
    
    // Add OCR results if we have them
    if (isset($results['mistral-ocr'])) {
        // This assignment was redundant as it was already done above, 
        // but ensuring latency is part of it if it was initialized here.
        // However, the primary $results['mistral-ocr'] is now set within the OCR processing block.
        // $results['mistral-ocr'] = [
        //     'content' => $extractedText,
        //     'tokens_in' => 0,
        //     'tokens_out' => 0,
        //     'latency_ms' => $results['mistral-ocr']['latency_ms'] ?? ($ocrResult['latency_ms'] ?? 0) // Carry over latency
        // ];
    }
    
    if ($bankAnalysis) {
        if (!$bankAnalysisProvider || !isset($providers[$bankAnalysisProvider])) {
            throw new RuntimeException("Invalid or missing provider for bank analysis");
        }
        
        // Get questions from file or default config
        if ($questionsFile) {
            $questionsJson = file_get_contents($questionsFile);
            if ($questionsJson === false) {
                throw new RuntimeException("Failed to read questions file: {$questionsFile}");
            }
            $questions = json_decode($questionsJson, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("Invalid JSON in questions file: " . json_last_error_msg());
            }
        } else {
            $questions = BankStatementQuestions::getQuestions();
        }
        
        if (empty($questions)) {
            throw new RuntimeException("No questions found for bank analysis");
        }
        
        // Prepare the prompt with questions
        $prompt = $input . "\n\nPlease analyze the above text and answer the following questions:\n\n";
        $questionNum = 1;
        foreach ($questions as $key => $question) {
            if (substr($key, 0, 9) === '_section_') {
                $prompt .= "\n" . $question . "\n\n";
            } else {
                $prompt .= $questionNum . ". " . $question . "\n";
                $questionNum++;
            }
        }
        
        // Call the API with the selected provider
        echo "\nProcessing bank analysis with {$bankAnalysisProvider}...\n";
        $result = $providers[$bankAnalysisProvider]->callApi($prompt, '', $outputDir);
        if ($result) {
            echo "{$bankAnalysisProvider} (Bank Analysis) Latency: " . ($result['latency_ms'] ?? 'N/A') . " ms\n";
            echo "{$bankAnalysisProvider} (Bank Analysis) Tokens In: " . ($result['tokens_in'] ?? 'N/A') . "\n";
            echo "{$bankAnalysisProvider} (Bank Analysis) Tokens Out: " . ($result['tokens_out'] ?? 'N/A') . "\n";
            $results[$bankAnalysisProvider] = $result;
        } else {
            throw new RuntimeException("Failed to get analysis from {$bankAnalysisProvider}");
        }
    } else {
        // Process with each selected provider
        foreach ($providers as $name => $provider) {
            if ($name === 'mistral-ocr') continue; // Skip OCR provider for text analysis
            
            echo "\nProcessing with {$name}...\n";
            $result = $provider->callApi($input, '', $outputDir);
            if ($result) {
                echo "{$name} Latency: " . ($result['latency_ms'] ?? 'N/A') . " ms\n";
                echo "{$name} Tokens In: " . ($result['tokens_in'] ?? 'N/A') . "\n";
                echo "{$name} Tokens Out: " . ($result['tokens_out'] ?? 'N/A') . "\n";
                $results[$name] = $result;
            }
        }
    }
    
    // Format and save results
    if (!empty($results)) {
        $formatter->formatResults($results, $outputDir, $timestamp);
        echo "\nResults have been saved to: {$outputDir}\n";
    } else {
        throw new RuntimeException("No results were obtained from any provider.");
    }
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    exit(1);
} 