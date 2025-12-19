<?php
define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/libagent.php');

require_login();
require_sesskey();

// before headers and curl init
session_write_close();
ignore_user_abort(true);
set_time_limit(0);

// Disable output buffering
while (ob_get_level() > 0) {
    ob_end_clean();
}
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
ob_implicit_flush(true);

    // 3. SSE headers.
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo ": connected\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();

echo ": connected\n\n";
flush();

// Increased timeouts
set_time_limit(360);
ini_set('max_execution_time', 360);
ignore_user_abort(false);


try {
    $agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
	if ($agentkey === '') {
    $agentkey = required_param('agentconfigkey', PARAM_ALPHANUMEXT);
	}
    $usertext = optional_param('agent_text', '', PARAM_RAW);
	if ($usertext === '') {
    $usertext = required_param('agenttext', PARAM_RAW);
	}
    $target = optional_param('target', 'CSIR Chemical Sciences Exam', PARAM_TEXT);
    $subject = optional_param('subject', 'Chemistry', PARAM_TEXT);
    $lesson = optional_param('lesson', '', PARAM_TEXT);
    $topic = optional_param('topic', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_RAW);

    // Build context block
    $context = [];
    $context[] = "**Exam**: {$target}";
    $context[] = "**Subject**: {$subject}";
    if (!empty($lesson)) $context[] = "**Lesson**: {$lesson}";
    if (!empty($topic)) $context[] = "**Topic**: {$topic}";
    if (!empty($tags)) $context[] = "**Keywords**: {$tags}";
    $context[] = "**Student Query**: {$usertext}";
    
    $context_string = implode("\n", $context);

    // Load prompt template from file
    $prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/ask_agent_instruction.txt';
    
    if (!file_exists($prompt_file)) {
        throw new Exception('Prompt template file not found: ' . $prompt_file);
    }
    
    $prompt_template = file_get_contents($prompt_file);
    
    // Replace placeholders
    $system_prompt = str_replace(
        ['{TARGET_EXAM}', '{SUBJECT}', '{TOPIC}', '{LESSON}', '{TAGS}', '{CONTEXT_BLOCK}'],
        [$target, $subject, $topic, $lesson, $tags, $context_string],
        $prompt_template
    );

    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Generate study notes for: {$usertext}"]
        ],
        'stream' => true,
        'max_tokens' => 4096,      // Increased for comprehensive notes
        'temperature' => 0.4,
        'top_p' => 0.9,
        'presence_penalty' => 0.6,
        'frequency_penalty' => 0.8
    ];

/* gpt-5-mini based payload 
    $payload = [
        'input' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Generate study notes for: {$usertext}"]
        ],
        'stream' => true,
        'max_output_tokens' => 3052,      // Increased for comprehensive notes
        'reasoning' => [
			'effort' => 'low', // or 'low' | 'medium' | 'high'
		],
    ];

*/
    local_ai_functions_call_endpoint($agentkey, 'ask_agent', $payload);
    
	error_log("Ask Agent - Streaming completed");
	
} catch (Exception $e) {
    echo "event: error\n";
    echo "data: " . json_encode(['error' => $e->getMessage()]) . "\n\n";
    flush();
}

