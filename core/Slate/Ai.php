<?php
declare(strict_types=1);

namespace Slate;

/**
 * Provider-agnostic AI client with presets.
 * Two wire formats: "anthropic" (Messages API) and "openai" (Chat Completions, used by most providers).
 */
final class Ai
{
    public const PRESETS = [
        'anthropic' => ['label' => 'Anthropic Claude', 'format' => 'anthropic', 'base' => 'https://api.anthropic.com/v1', 'model' => 'claude-sonnet-5-5',
            'models' => ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5'], 'keys' => 'https://console.anthropic.com/settings/keys'],
        'openai' => ['label' => 'OpenAI', 'format' => 'openai', 'base' => 'https://api.openai.com/v1', 'model' => 'gpt-5-mini',
            'models' => ['gpt-5', 'gpt-5-mini', 'gpt-5-nano'], 'keys' => 'https://platform.openai.com/api-keys'],
        'openrouter' => ['label' => 'OpenRouter (any model)', 'format' => 'openai', 'base' => 'https://openrouter.ai/api/v1', 'model' => 'openrouter/auto',
            'models' => ['openrouter/auto', 'anthropic/claude-sonnet-5.5', 'openai/gpt-5-mini', 'google/gemini-2.5-flash'], 'keys' => 'https://openrouter.ai/keys'],
        'gemini' => ['label' => 'Google Gemini', 'format' => 'openai', 'base' => 'https://generativelanguage.googleapis.com/v1beta/openai', 'model' => 'gemini-2.5-flash',
            'models' => ['gemini-2.5-pro', 'gemini-2.5-flash'], 'keys' => 'https://aistudio.google.com/apikey'],
        'groq' => ['label' => 'Groq', 'format' => 'openai', 'base' => 'https://api.groq.com/openai/v1', 'model' => 'llama-3.3-70b-versatile',
            'models' => ['llama-3.3-70b-versatile'], 'keys' => 'https://console.groq.com/keys'],
        'mistral' => ['label' => 'Mistral', 'format' => 'openai', 'base' => 'https://api.mistral.ai/v1', 'model' => 'mistral-large-latest',
            'models' => ['mistral-large-latest', 'mistral-small-latest'], 'keys' => 'https://console.mistral.ai/api-keys'],
        'deepseek' => ['label' => 'DeepSeek', 'format' => 'openai', 'base' => 'https://api.deepseek.com/v1', 'model' => 'deepseek-chat',
            'models' => ['deepseek-chat', 'deepseek-reasoner'], 'keys' => 'https://platform.deepseek.com/api_keys'],
        'ollama' => ['label' => 'Ollama (self-hosted)', 'format' => 'openai', 'base' => 'http://localhost:11434/v1', 'model' => 'llama3.2',
            'models' => ['llama3.2', 'qwen2.5'], 'keys' => ''],
        'custom' => ['label' => 'Custom (OpenAI-compatible)', 'format' => 'openai', 'base' => '', 'model' => '', 'models' => [], 'keys' => ''],
    ];

    /** Built-in content actions. {content}, {title}, {input} are replaced. */
    public const ACTIONS = [
        'seo_meta' => ['label' => 'Write SEO title & description', 'json' => true,
            'prompt' => "Write an SEO meta title (max 60 characters) and meta description (max 155 characters) for this page. Include the main topic naturally, be specific and compelling, no clickbait.\nReturn ONLY JSON: {\"title\":\"...\",\"description\":\"...\"}\n\nPage title: {title}\n\nContent:\n{content}"],
        'excerpt' => ['label' => 'Write excerpt',
            'prompt' => "Write a 1–2 sentence summary (max 200 characters) of this content for use as an excerpt. Plain text only.\n\nTitle: {title}\n\n{content}"],
        'improve' => ['label' => 'Improve writing', 'html' => true,
            'prompt' => "Improve the clarity, flow and grammar of this HTML content. Keep the meaning, facts, links, images and the HTML structure. Return ONLY the improved HTML.\n\n{content}"],
        'shorten' => ['label' => 'Make shorter', 'html' => true,
            'prompt' => "Make this HTML content about 40% shorter while keeping the key information and HTML structure. Return ONLY HTML.\n\n{content}"],
        'expand' => ['label' => 'Expand with more detail', 'html' => true,
            'prompt' => "Expand this HTML content with more helpful detail, examples and sub-headings (h2/h3) where useful. Do not invent statistics or facts you are unsure of. Return ONLY HTML.\n\n{content}"],
        'fix' => ['label' => 'Fix spelling & grammar', 'html' => true,
            'prompt' => "Fix spelling, grammar and punctuation in this HTML. Change nothing else. Return ONLY HTML.\n\n{content}"],
        'faq' => ['label' => 'Generate FAQ section', 'html' => true,
            'prompt' => "Based on this content, write 4–6 frequently asked questions with concise answers as HTML using <h3> for questions and <p> for answers. Return ONLY HTML.\n\nTitle: {title}\n\n{content}"],
        'draft' => ['label' => 'Write a draft from a brief', 'html' => true,
            'prompt' => "Write a complete, well-structured web page/article as HTML (use h2/h3, p, ul) based on this brief. Return ONLY HTML, no <html> or <body> tags.\n\nTitle: {title}\nBrief: {input}"],
        'title' => ['label' => 'Suggest titles',
            'prompt' => "Suggest 5 compelling, specific titles for this content. One per line, no numbering.\n\n{content}"],
        'alt' => ['label' => 'Write image alt text',
            'prompt' => "Write concise, descriptive alt text (max 125 characters) for an image on a web page. Image file name/title: \"{title}\". Context: {input}. Return ONLY the alt text."],
        'custom' => ['label' => 'Custom instruction', 'html' => true,
            'prompt' => "Apply this instruction to the HTML content below: {input}\nReturn ONLY the resulting HTML.\n\n{content}"],
    ];

    public static function enabled(): bool
    {
        return (bool)Options::get('ai_enabled') && (self::key() !== '' || Options::get('ai_provider') === 'ollama');
    }

    public static function preset(): array
    {
        return self::PRESETS[(string)Options::get('ai_provider', 'anthropic')] ?? self::PRESETS['custom'];
    }

    private static function key(): string
    {
        return Crypto::decrypt((string)Options::get('ai_api_key'));
    }

    /**
     * @return array{ok:bool, text?:string, error?:string}
     */
    public static function complete(string $prompt, ?string $system = null, array $opts = []): array
    {
        $preset = self::preset();
        $provider = (string)Options::get('ai_provider', 'anthropic');
        $base = rtrim((string)(Options::get('ai_base_url') ?: $preset['base']), '/');
        $model = (string)(Options::get('ai_model') ?: $preset['model']);
        $key = self::key();
        if ($base === '' || $model === '') {
            return ['ok' => false, 'error' => 'Set a base URL and model under Settings → AI.'];
        }
        $system = trim(($system ?? (string)Options::get('ai_system_prompt')) . "\nSite: " . Options::get('site_title') . ' — ' . Options::get('tagline'));
        $maxTokens = (int)($opts['max_tokens'] ?? Options::get('ai_max_tokens', 2000));
        $temperature = (float)($opts['temperature'] ?? Options::get('ai_temperature', 0.7));

        if ($preset['format'] === 'anthropic') {
            $r = Http::json('POST', $base . '/messages', ['x-api-key: ' . $key, 'anthropic-version: 2023-06-01'], [
                'model' => $model,
                'max_tokens' => $maxTokens,
                'system' => $system,
                'messages' => [['role' => 'user', 'content' => $prompt]],
                'temperature' => min(1.0, $temperature),
            ], 120);
            if ($r['error'] || $r['status'] >= 400) {
                return ['ok' => false, 'error' => self::errorMessage($r)];
            }
            $text = '';
            foreach ($r['json']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $text .= $block['text'];
                }
            }
            return ['ok' => true, 'text' => trim($text)];
        }

        $payload = [
            'model' => $model,
            'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]],
        ];
        if ($provider === 'openai') {
            // Current OpenAI models use max_completion_tokens and only support the default temperature.
            $payload['max_completion_tokens'] = $maxTokens;
        } else {
            $payload['max_tokens'] = $maxTokens;
            $payload['temperature'] = $temperature;
        }
        $headers = $key !== '' ? ['Authorization: Bearer ' . $key] : [];
        if ($provider === 'openrouter') {
            $headers[] = 'HTTP-Referer: ' . site_url();
            $headers[] = 'X-Title: ' . Options::get('site_title');
        }
        $r = Http::json('POST', $base . '/chat/completions', $headers, $payload, 120);
        if ($r['error'] || $r['status'] >= 400) {
            return ['ok' => false, 'error' => self::errorMessage($r)];
        }
        $text = $r['json']['choices'][0]['message']['content'] ?? '';
        if (is_array($text)) {
            $text = implode('', array_map(static fn($p) => $p['text'] ?? '', $text));
        }
        return ['ok' => true, 'text' => trim((string)$text)];
    }

    private static function errorMessage(array $r): string
    {
        if ($r['error']) {
            return 'Connection failed: ' . $r['error'];
        }
        $msg = $r['json']['error']['message'] ?? $r['json']['error'] ?? $r['json']['message'] ?? null;
        return 'Provider error (' . $r['status'] . '): ' . (is_string($msg) ? $msg : mb_substr($r['body'], 0, 300));
    }

    /** Run a named action. */
    public static function action(string $action, array $ctx): array
    {
        $def = self::ACTIONS[$action] ?? null;
        if (!$def) {
            return ['ok' => false, 'error' => 'Unknown AI action.'];
        }
        $content = (string)($ctx['content'] ?? '');
        if (mb_strlen($content) > 60000) {
            $content = mb_substr($content, 0, 60000);
        }
        $prompt = strtr($def['prompt'], [
            '{content}' => $content,
            '{title}' => (string)($ctx['title'] ?? ''),
            '{input}' => (string)($ctx['input'] ?? ''),
        ]);
        $r = self::complete($prompt);
        if (!$r['ok']) {
            return $r;
        }
        $text = preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $r['text']);
        if (!empty($def['json'])) {
            if (preg_match('/\{.*\}/s', (string)$text, $m) && ($data = json_decode($m[0], true))) {
                return ['ok' => true, 'data' => $data];
            }
            return ['ok' => false, 'error' => 'The AI returned an unexpected format. Try again.'];
        }
        if (!empty($def['html']) && !Auth::can('unfiltered_html')) {
            $text = Sanitizer::html((string)$text);
        }
        log_activity('ai_' . $action, (string)($ctx['title'] ?? ''));
        return ['ok' => true, 'text' => $text, 'html' => !empty($def['html'])];
    }
}
