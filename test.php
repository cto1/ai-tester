<?php

/**
 * AI Provider Comparison Script
 * 
 * This script sends the same prompt to multiple AI providers (DeepSeek, OpenAI, Claude, and Mistral)
 * to extract information from financial statements through OCR and analysis.
 * 
 * Usage: 
 *   php test.php                  - Run all AI providers
 *   php test.php deepseek         - Run only DeepSeek
 *   php test.php openai           - Run only OpenAI
 *   php test.php claude           - Run only Claude
 *   php test.php mistral          - Run only Mistral
 *   php test.php mistral-ocr      - Run only Mistral OCR API (with URL)
 *   php test.php mistral-ocr [filepath]  - Run Mistral OCR with local file
 *   php test.php --use-ocr-for-all      - Run OCR first and use results for all providers
 *   php test.php --use-md-file [mdfile]  - Use existing markdown file for all providers
 *   php test.php --bank-analysis [provider] [mdfile]  - Run structured bank statement analysis with specified provider
 *   php test.php --questions-file [file] - Use custom questions for bank analysis
 *   php test.php --bank-analysis [provider] [mdfile] --questions-file [file] - Run custom questions analysis
 *   php test.php deepseek openai  - Run DeepSeek and OpenAI
 * 
 * Flexible command ordering is supported. For example, these are equivalent:
 *   php test.php --bank-analysis openai [mdfile]
 *   php test.php openai --bank-analysis [mdfile]
 */

// Show help if no arguments or help flag is provided
if ($argc === 1 || in_array($argv[1], ['-h', '--help'])) {
    echo "AI Provider Comparison Tool\n";
    echo "==========================\n";
    echo "Usage: php test.php [provider1] [provider2] ...\n";
    echo "Available providers: deepseek, openai, claude, mistral, mistral-ocr, gemini\n\n";
    
    echo "Special options:\n";
    echo "  mistral-ocr [file path]   - Process a document with Mistral OCR\n";
    echo "  --use-ocr-for-all         - Use OCR text for all models\n";
    echo "  --use-md-file [file path] - Use content from existing markdown file\n";
    echo "  --bank-analysis [provider] [file path] - Run bank statement analysis with specified provider and markdown file\n";
    echo "  --questions-file [file path] - Use custom questions for bank analysis instead of default bank statement questions\n\n";
    
    echo "Example for OCR processing:\n";
    echo "  php test.php mistral-ocr /path/to/document.pdf\n\n";
    
    echo "Example for bank statement analysis:\n";
    echo "  php test.php --bank-analysis mistral /path/to/ocr_results.md\n";
    echo "  php test.php mistral --bank-analysis /path/to/ocr_results.md\n\n";
    
    echo "Example with custom questions:\n";
    echo "  php test.php --bank-analysis openai /path/to/ocr_results.md --questions-file /path/to/questions.json\n";
    echo "  php test.php openai --bank-analysis /path/to/ocr_results.md --questions-file /path/to/questions.json\n\n";
    
    echo "Custom questions file format (JSON):\n";
    echo "{\n";
    echo "  \"business_name\": \"What is the business name on the account?\",\n";
    echo "  \"account_balance\": \"What is the final balance on the account?\",\n";
    echo "  \"custom_question_1\": \"Any specific question you want to ask about the document...\"\n";
    echo "}\n";
    echo "A sample questions file is available at: public_html/api/sample_questions.json\n";
    exit(0);
}

// Parse command line arguments
$providers = [];
$useOcrForAll = false;
$mdFile = null;
$bankAnalysis = false;
$bankAnalysisProvider = null;
$questionsFile = null;
$input = null;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    
    switch ($arg) {
        case '--use-ocr-for-all':
            $useOcrForAll = true;
            break;
            
        case '--use-md-file':
            if (isset($argv[$i + 1])) {
                $mdFile = $argv[++$i];
                if (!file_exists($mdFile)) {
                    die("Error: Markdown file not found: $mdFile\n");
                }
            } else {
                die("Error: --use-md-file requires a file path\n");
            }
            break;
            
        case '--bank-analysis':
            $bankAnalysis = true;
            if (isset($argv[$i + 1])) {
                $bankAnalysisProvider = $argv[++$i];
                if (!in_array($bankAnalysisProvider, ['deepseek', 'openai', 'claude', 'mistral', 'gemini'])) {
                    die("Error: Invalid provider for bank analysis: $bankAnalysisProvider\n");
                }
            } else {
                die("Error: --bank-analysis requires a provider name\n");
            }
            break;
            
        case '--questions-file':
            if (isset($argv[$i + 1])) {
                $questionsFile = $argv[++$i];
                if (!file_exists($questionsFile)) {
                    die("Error: Questions file not found: $questionsFile\n");
                }
            } else {
                die("Error: --questions-file requires a file path\n");
            }
            break;
            
        case 'mistral-ocr':
            if (isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
                $input = $argv[++$i];
                if (!file_exists($input)) {
                    die("Error: File not found: $input\n");
                }
            }
            $providers[] = 'mistral-ocr';
            break;
            
        default:
            if (in_array($arg, ['deepseek', 'openai', 'claude', 'mistral', 'gemini'])) {
                $providers[] = $arg;
            } else if (!str_starts_with($arg, '--')) {
                // If it's not a known provider and not an option, it might be a file path
                $input = $arg;
            }
    }
}

// If no providers specified, use all
if (empty($providers)) {
    $providers = ['deepseek', 'openai', 'claude', 'mistral', 'gemini'];
}

// If no input provided and not using markdown file, prompt for input
if ($input === null && $mdFile === null) {
    echo "Enter text or file path: ";
    $input = trim(fgets(STDIN));
}

// Set up the input for the main script
if ($mdFile !== null) {
    $_POST['input'] = file_get_contents($mdFile);
} else if (in_array('mistral-ocr', $providers) && $input !== null && file_exists($input)) {
    // For OCR, pass the file path directly
    $_POST['input'] = $input;
} else {
    $_POST['input'] = $input;
}

// Set additional parameters
$_POST['providers'] = $providers;
$_POST['use_ocr_for_all'] = $useOcrForAll;
$_POST['bank_analysis'] = $bankAnalysis;
$_POST['bank_analysis_provider'] = $bankAnalysisProvider;
$_POST['questions_file'] = $questionsFile;

// Include the main script
require_once __DIR__ . '/ai_comparison_new.php'; 