<?php
require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/providers/AiProviderInterface.php';
require_once __DIR__ . '/providers/OpenaiProvider.php';
require_once __DIR__ . '/providers/ClaudeProvider.php';
require_once __DIR__ . '/providers/MistralProvider.php';
require_once __DIR__ . '/providers/MistralOcrProvider.php';
require_once __DIR__ . '/providers/DeepseekProvider.php';
require_once __DIR__ . '/providers/GeminiProvider.php';
require_once __DIR__ . '/utils/ResponseFormatter.php';
require_once __DIR__ . '/config/BankStatementQuestions.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Initialize providers (using the same config as in ai_comparison_new.php)
$config = [
    'openai' => [
        'api_key' => $_ENV['OPENAI_API_KEY'] ?? '',
        'model' => $_ENV['OPENAI_MODEL'] ?? 'gpt-4-turbo-preview'
    ],
    'claude' => [
        'api_key' => $_ENV['CLAUDE_API_KEY'] ?? '',
        'model' => $_ENV['CLAUDE_MODEL'] ?? 'claude-3-opus-20240229'
    ],
    'mistral' => [
        'api_key' => $_ENV['MISTRAL_API_KEY'] ?? '',
        'model' => $_ENV['MISTRAL_MODEL'] ?? 'mistral-large-latest'
    ],
    'deepseek' => [
        'api_key' => $_ENV['DEEPSEEK_API_KEY'] ?? '',
        'model' => $_ENV['DEEPSEEK_MODEL'] ?? 'deepseek-chat'
    ],
    'gemini' => [
        'api_key' => $_ENV['GEMINI_API_KEY'] ?? '',
        'model' => $_ENV['GEMINI_MODEL'] ?? 'gemini-1.5-pro'
    ]
];

$allProviders = [
    'openai' => ['name' => 'OpenAI', 'model' => $config['openai']['model']],
    'claude' => ['name' => 'Claude', 'model' => $config['claude']['model']],
    'mistral' => ['name' => 'Mistral', 'model' => $config['mistral']['model']],
    'deepseek' => ['name' => 'DeepSeek', 'model' => $config['deepseek']['model']],
    'gemini' => ['name' => 'Gemini', 'model' => $config['gemini']['model']]
];

// Initialize OCR provider
$ocrProvider = new MistralOcrProvider($config['mistral']['api_key'] ?? '', $_ENV['MISTRAL_OCR_MODEL'] ?? 'mistral-ocr-latest');

// Initialize response formatter
$formatter = new ResponseFormatter();

// Handle file upload
$uploadedFile = null;
$extractedText = '';
$analysisResult = '';
$mode = isset($_GET['mode']) ? $_GET['mode'] : 'upload';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'upload':
                if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = __DIR__ . '/uploads/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    
                    $fileName = basename($_FILES['document']['name']);
                    $uploadedFile = $uploadDir . $fileName;
                    
                    if (move_uploaded_file($_FILES['document']['tmp_name'], $uploadedFile)) {
                        // File uploaded successfully
                        $mode = 'ocr';
                    }
                }
                break;
                
            case 'ocr':
                if (isset($_POST['file_path']) && file_exists($_POST['file_path'])) {
                    $uploadedFile = $_POST['file_path'];
                    $baseOutputDir = __DIR__ . "/test_results";
                    $timestamp = date('Y-m-d_H-i-s');
                    $outputDir = $baseOutputDir . "/run_{$timestamp}";
                    
                    if (!file_exists($outputDir)) {
                        mkdir($outputDir, 0755, true);
                    }
                    
                    try {
                        $ocrResult = $ocrProvider->callApi($uploadedFile, '', $outputDir);
                        if ($ocrResult && isset($ocrResult['extracted_text'])) {
                            $extractedText = $ocrResult['extracted_text'];
                            $mode = 'analysis';
                        }
                    } catch (Exception $e) {
                        $error = "OCR Error: " . $e->getMessage();
                    }
                }
                break;
                
            case 'analysis':
                if (isset($_POST['provider']) && isset($_POST['text'])) {
                    $providerName = $_POST['provider'];
                    $input = $_POST['text'];
                    $baseOutputDir = __DIR__ . "/test_results";
                    $timestamp = date('Y-m-d_H-i-s');
                    $outputDir = $baseOutputDir . "/run_{$timestamp}";
                    
                    if (!file_exists($outputDir)) {
                        mkdir($outputDir, 0755, true);
                    }
                    
                    $providerConfig = $config[$providerName] ?? null;
                    if ($providerConfig) {
                        $provider = null;
                        switch ($providerName) {
                            case 'openai':
                                $provider = new OpenaiProvider($providerConfig['api_key'], $providerConfig['model']);
                                break;
                            case 'claude':
                                $provider = new ClaudeProvider($providerConfig['api_key'], $providerConfig['model']);
                                break;
                            case 'mistral':
                                $provider = new MistralProvider($providerConfig['api_key'], $providerConfig['model']);
                                break;
                            case 'deepseek':
                                $provider = new DeepseekProvider($providerConfig['api_key'], $providerConfig['model']);
                                break;
                            case 'gemini':
                                $provider = new GeminiProvider($providerConfig['api_key'], $providerConfig['model']);
                                break;
                        }
                        
                        if ($provider) {
                            $analysisType = $_POST['analysis_type'] ?? 'chat';
                            
                            if ($analysisType === 'bank') {
                                // Get bank analysis questions
                                $questions = BankStatementQuestions::getQuestions();
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
                                
                                $result = $provider->callApi($prompt, '', $outputDir);
                            } else {
                                // Simple chat prompt
                                $chatPrompt = isset($_POST['chat_prompt']) ? $_POST['chat_prompt'] : "Please analyze this document.";
                                $prompt = $input . "\n\n" . $chatPrompt;
                                $result = $provider->callApi($prompt, '', $outputDir);
                            }
                            
                            if ($result && isset($result['content'])) {
                                $analysisResult = $result['content'];
                                $mode = 'results';
                            }
                        }
                    }
                }
                break;
        }
    }
}

// Get list of uploaded files
$uploadedFiles = [];
$uploadDir = __DIR__ . '/uploads/';
if (file_exists($uploadDir) && is_dir($uploadDir)) {
    $files = scandir($uploadDir);
    foreach ($files as $file) {
        if ($file !== '.' && $file !== '..') {
            $uploadedFiles[] = [
                'name' => $file,
                'path' => $uploadDir . $file,
                'size' => filesize($uploadDir . $file),
                'date' => date('Y-m-d H:i:s', filemtime($uploadDir . $file))
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OCR & Bank Statement Analysis</title>
    <link href="https://cdn.jsdelivr.net/npm/daisyui@4.6.1/dist/full.min.css" rel="stylesheet" type="text/css" />
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/themes/prism.min.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
</head>
<body class="min-h-screen">
    <div class="navbar bg-base-300 shadow-lg">
        <div class="flex-1">
            <a class="btn btn-ghost text-xl">OCR & Bank Statement Analysis</a>
        </div>
        <div class="flex-none">
            <ul class="menu menu-horizontal px-1">
                <li><a href="?mode=upload">Upload</a></li>
                <li><a href="?mode=ocr">OCR</a></li>
                <li><a href="?mode=analysis">Analysis</a></li>
            </ul>
        </div>
    </div>

    <div class="flex flex-col lg:flex-row min-h-[calc(100vh-4rem)]">
        <!-- Left panel: File upload and list -->
        <div class="w-full lg:w-1/4 bg-base-200 p-4 overflow-auto">
            <div class="mb-4">
                <h2 class="text-xl font-bold mb-2">Upload Document</h2>
                <form action="" method="post" enctype="multipart/form-data" class="form-control">
                    <input type="hidden" name="action" value="upload">
                    <input type="file" name="document" class="file-input file-input-bordered w-full mb-2" />
                    <button type="submit" class="btn btn-primary">Upload</button>
                </form>
            </div>
            
            <div class="divider"></div>
            
            <div>
                <h2 class="text-xl font-bold mb-2">Uploaded Files</h2>
                <?php if (empty($uploadedFiles)): ?>
                    <p class="text-gray-500">No files uploaded yet.</p>
                <?php else: ?>
                    <div class="overflow-y-auto max-h-[50vh]">
                        <?php foreach ($uploadedFiles as $file): ?>
                            <div class="card bg-base-100 shadow-sm mb-2">
                                <div class="card-body p-3">
                                    <h3 class="card-title text-sm"><?= htmlspecialchars($file['name']) ?></h3>
                                    <p class="text-xs"><?= date('Y-m-d', strtotime($file['date'])) ?> | <?= round($file['size'] / 1024, 2) ?> KB</p>
                                    <div class="card-actions justify-end">
                                        <form action="" method="post">
                                            <input type="hidden" name="action" value="ocr">
                                            <input type="hidden" name="file_path" value="<?= htmlspecialchars($file['path']) ?>">
                                            <button type="submit" class="btn btn-xs btn-primary">Process with OCR</button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Middle panel: Model selection and analysis options -->
        <div class="w-full lg:w-1/3 p-4 bg-base-100 border-x border-base-200">
            <h2 class="text-xl font-bold mb-4">Analysis Options</h2>
            
            <?php if (!empty($extractedText)): ?>
                <div class="mb-4">
                    <div class="alert alert-success">
                        <svg xmlns="http://www.w3.org/2000/svg" class="stroke-current shrink-0 h-6 w-6" fill="none" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <span>Text successfully extracted with OCR</span>
                    </div>
                </div>
            <?php endif; ?>
            
            <form action="" method="post" class="form-control gap-2">
                <input type="hidden" name="action" value="analysis">
                <input type="hidden" name="text" value="<?= htmlspecialchars($extractedText) ?>">
                
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Select Model</span>
                    </label>
                    <select name="provider" class="select select-bordered w-full">
                        <?php foreach ($allProviders as $key => $provider): ?>
                            <option value="<?= $key ?>"><?= $provider['name'] ?> (<?= $provider['model'] ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-control">
                    <label class="label">
                        <span class="label-text">Analysis Type</span>
                    </label>
                    <div class="tabs tabs-boxed mb-2">
                        <input type="radio" name="analysis_type" value="bank" id="tab-bank" class="tab" checked />
                        <label for="tab-bank" class="tab">Bank Analysis</label>
                        
                        <input type="radio" name="analysis_type" value="chat" id="tab-chat" class="tab" />
                        <label for="tab-chat" class="tab">Custom Chat</label>
                    </div>
                </div>
                
                <div id="chat-options" class="form-control hidden">
                    <label class="label">
                        <span class="label-text">Chat Prompt</span>
                    </label>
                    <textarea name="chat_prompt" class="textarea textarea-bordered h-24" placeholder="Enter your question about the document..."></textarea>
                </div>
                
                <button type="submit" class="btn btn-primary mt-4" <?= empty($extractedText) ? 'disabled' : '' ?>>
                    Run Analysis
                </button>
            </form>
        </div>
        
        <!-- Right panel: Results display -->
        <div class="w-full lg:w-5/12 p-4 bg-base-100">
            <h2 class="text-xl font-bold mb-4">Results</h2>
            
            <?php if (empty($analysisResult)): ?>
                <div class="alert">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" class="stroke-info shrink-0 w-6 h-6"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                    <span>Results will appear here after analysis is complete.</span>
                </div>
            <?php else: ?>
                <div class="card bg-base-200 shadow-lg">
                    <div class="card-body p-4">
                        <div id="markdown-result" class="prose max-w-none"></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // Handle tab switching for analysis type
        document.querySelectorAll('input[name="analysis_type"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const chatOptions = document.getElementById('chat-options');
                if (this.value === 'chat') {
                    chatOptions.classList.remove('hidden');
                } else {
                    chatOptions.classList.add('hidden');
                }
            });
        });
        
        // Render markdown for results
        document.addEventListener('DOMContentLoaded', function() {
            const markdownResult = document.getElementById('markdown-result');
            const analysisResult = <?= json_encode($analysisResult) ?>;
            
            if (markdownResult && analysisResult) {
                markdownResult.innerHTML = marked.parse(analysisResult);
            }
        });
    </script>
    
    <script src="https://cdn.jsdelivr.net/npm/prismjs@1.29.0/prism.min.js"></script>
</body>
</html> 