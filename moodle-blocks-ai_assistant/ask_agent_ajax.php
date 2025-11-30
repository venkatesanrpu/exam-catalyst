<?php
define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();
require_sesskey();

// Get parameters
$agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
$usertext = required_param('agent_text', PARAM_RAW);
$target = optional_param('target', '', PARAM_TEXT);
$subject = optional_param('subject', '', PARAM_TEXT);
$lesson = optional_param('lesson', '', PARAM_TEXT);
$topic = optional_param('topic', '', PARAM_TEXT);
$tagsstring = optional_param('tags', '', PARAM_RAW);

$tagsarray = [];
if (!empty($tagsstring)) {
    $tagsarray = array_map('trim', explode(',', $tagsstring));
}

$usercontext = [
    'target' => $target,
    'subject' => $subject,
    'lesson' => $lesson,
    'topic' => $topic,
    'tags' => $tagsarray,
    'query' => $usertext
];

$payload = [
    'model' => 'kimi-k2-0905',
    'messages' => [
        [
            'role' => 'system',
            'content' => 'You are an expert tutor and assistant for CSIR examinations. Produce exam-focused study notes with headings, sub-headings, key equations, derivations, pitfalls, and 2 analytical questions at higher Bloom\'s taxonomy with hints. Use Markdown formatting for structure and LaTeX (using \\[...\\] for display math and \\(...\\) for inline) for equations.'
        ],
        [
            'role' => 'user',
            'content' => json_encode($usercontext, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        ]
    ],
    'temperature' => 0.35,
    'max_tokens' => 150,
    'top_p' => 1.0,
    'frequency_penalty' => 0.15,
    'presence_penalty' => 0.15,
    'stream' => false  // ← CHANGE THIS TO false FOR NON-STREAMING
];

// FIXED: Determine headers based on payload['stream']
if (isset($payload['stream']) && $payload['stream']) {
    // Streaming mode
    set_time_limit(180);
    ignore_user_abort(true);
    
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    
    if (ob_get_level()) {
        ob_end_clean();
    }
} else {
    // Non-streaming mode
    header('Content-Type: application/json');
}

// Call lib.php - it reads $payload['stream'] automatically
$response = local_ai_functions_call_endpoint($agentkey, 'ask_agent', $payload);

// For non-streaming, echo the response
if (!isset($payload['stream']) || !$payload['stream']) {
    echo $response;
}
