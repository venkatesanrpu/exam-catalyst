<?php
// FILE: moodle/blocks/ai_assistant/ask_agent_ajax.php
// UPDATE: Now accepts the full four-level hierarchy (subject, lesson, topic, tags)
// for the most precise RAG context.

define('AJAX_SCRIPT', true);

require_once('../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();
require_sesskey();
header('Content-Type: application/json');

$agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
$usertext = required_param('agent_text', PARAM_RAW);

// --- THIS IS THE CORRECTED LOGIC ---
// Get all four levels of the hierarchy. They are all optional because a general
// textbox query will not have them.
$subject = optional_param('subject', '', PARAM_ALPHANUMEXT);
$lesson  = optional_param('lesson', '', PARAM_TEXT);
$topic   = optional_param('topic', '', PARAM_TEXT);
$tags_string = optional_param('tags', '', PARAM_RAW);
// --- END OF CORRECTION ---

$tags_array = [];
if (!empty($tags_string)) {
    $tags_array = explode(',', $tags_string);
}

$payload = [
    'query' => $usertext,
    'context' => [
        'subject' => $subject,
        'lesson'  => $lesson,
        'topic'   => $topic,
        'tags'    => $tags_array
    ]
];

echo local_ai_functions_call_endpoint($agentkey, 'ask_agent', $payload);