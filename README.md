# AI Provider Comparison Tool

This tool allows you to compare responses from multiple AI providers (DeepSeek, OpenAI, Claude, and Mistral) for analyzing financial statements and other documents.

## Prerequisites

1. Make sure you have PHP installed
2. Install dependencies:
   ```bash
   composer install
   ```
3. Create a `.env` file in the `public_html/api` directory with your API keys:
   ```
   OPENAI_API_KEY=your_openai_key
   CLAUDE_API_KEY=your_claude_key
   MISTRAL_API_KEY=your_mistral_key
   DEEPSEEK_API_KEY=your_deepseek_key
   ```

## Basic Usage

### Show Help
```bash
php test.php
# or
php test.php --help
# or
php test.php -h
```

### Run with All Providers
```bash
php test.php
```
This will prompt you to enter text or a file path.

### Run with Specific Providers
```bash
# Run only OpenAI
php test.php openai

# Run OpenAI and Claude
php test.php openai claude

# Run only Mistral
php test.php mistral

# Run only DeepSeek
php test.php deepseek
```

## File Processing

### Process a File with OCR
```bash
# Process a PDF or image file with Mistral OCR
php test.php mistral-ocr /path/to/your/file.pdf
```

### Use OCR Results for All Providers
```bash
# First extract text with OCR, then use that text for all providers
php test.php --use-ocr-for-all /path/to/your/file.pdf
```

### Use Existing Markdown File
```bash
# Use content from an existing markdown file
php test.php --use-md-file /path/to/your/file.md
```

## Bank Statement Analysis

### Basic Bank Analysis
```bash
# Run bank statement analysis with OpenAI
php test.php --bank-analysis openai /path/to/your/file.md

# Run bank statement analysis with Claude
php test.php --bank-analysis claude /path/to/your/file.md

# Run bank statement analysis with Mistral
php test.php --bank-analysis mistral /path/to/your/file.md

# Run bank statement analysis with DeepSeek
php test.php --bank-analysis deepseek /path/to/your/file.md
```

### Custom Questions for Bank Analysis
Create a JSON file (e.g., `questions.json`) with your custom questions:
```json
{
  "business_name": "What is the business name on the account?",
  "account_balance": "What is the final balance on the account?",
  "custom_question_1": "Any specific question you want to ask about the document..."
}
```

Then use it with the analysis:
```bash
# Run bank analysis with custom questions
php test.php --bank-analysis openai /path/to/your/file.md --questions-file /path/to/questions.json
```

## Combined Examples

### OCR + Bank Analysis
```bash
# Process PDF with OCR and then run bank analysis
php test.php mistral-ocr /path/to/statement.pdf --bank-analysis openai
```

### Custom Questions + OCR
```bash
# Process PDF with OCR and use custom questions
php test.php mistral-ocr /path/to/statement.pdf --bank-analysis openai --questions-file /path/to/questions.json
```

### Multiple Providers with OCR
```bash
# Process PDF with OCR and run multiple providers
php test.php mistral-ocr /path/to/statement.pdf openai claude
```

## Output

All results are saved in a timestamped directory under `public_html/api/output_*`. The output includes:
- JSON responses from each provider
- Formatted markdown results
- OCR results (if applicable)

## Notes

- The order of arguments is flexible. For example, these are equivalent:
  ```bash
  php test.php --bank-analysis openai /path/to/file.md
  php test.php openai --bank-analysis /path/to/file.md
  ```
- If no input is provided, the script will prompt you to enter text or a file path
- For file inputs, the script will automatically detect if OCR is needed
- Custom questions files must be in valid JSON format 