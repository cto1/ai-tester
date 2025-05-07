<?php

interface AiProviderInterface {
    /**
     * Call the AI provider's API and save the response to a file
     * 
     * @param string $prompt The user prompt
     * @param string $systemPrompt The system prompt
     * @param string $outputDir Directory to save the response
     * @return array|null Response data including content and token usage
     */
    public function callApi(string $prompt, string $systemPrompt, string $outputDir): ?array;

    /**
     * Call the AI provider's API without saving to file
     * 
     * @param string $prompt The user prompt
     * @param string $systemPrompt The system prompt
     * @return array|null Response data including content and token usage
     */
    public function callApiWithoutEcho(string $prompt, string $systemPrompt): ?array;
} 