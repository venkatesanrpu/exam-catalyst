<?php
// FILE: moodle/blocks/ai_assistant/ask_agent_ajax.php
// STREAMING VERSION: Calls lib.php with stream parameter

define('AJAX_SCRIPT', true);
require_once('../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();
require_sesskey();

// CRITICAL: Set SSE headers BEFORE any output
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

// Disable output buffering
if (ob_get_level()) ob_end_clean();

// Get parameters
$agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
$usertext = required_param('agent_text', PARAM_RAW);

// Get context parameters
$target = optional_param('target', '', PARAM_TEXT);
$subject = optional_param('subject', '', PARAM_TEXT);
$lesson = optional_param('lesson', '', PARAM_TEXT);
$topic = optional_param('topic', '', PARAM_TEXT);
$tagsstring = optional_param('tags', '', PARAM_RAW);

// Convert tags to array
$tagsarray = [];
if (!empty($tagsstring)) {
    $tagsarray = array_map('trim', explode(',', $tagsstring));
}

// --- Build Payload for Kimi K2 ---
$usercontext = [
    'target' => $target,
    'subject' => $subject,
    'lesson' => $lesson,
    'topic' => $topic,
    'tags' => $tagsarray,
    'query' => $usertext
];

$payload = [
    'model' => 'kimi-k2-thinking',
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
    'max_tokens' => 2048,
    'top_p' => 1.0,
    'frequency_penalty' => 0.15,
    'presence_penalty' => 0.15
    //'stream' => true // Note: 'stream' => true is added by lib.php when $stream parameter is true
];

// Call the centralized function with streaming enabled
local_ai_functions_call_endpoint($agentkey, 'ask_agent', $payload, true);
