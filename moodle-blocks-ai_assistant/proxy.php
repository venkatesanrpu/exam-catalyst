<?php
// FILE: /blocks/ai_assistant/proxy.php (NEW LOCATION)
// PURPOSE: Securely calls the configured Azure Function App endpoints.
// UPDATE: The path to config.php has been updated for its new location.

// The config file is now in the Moodle root, two directories up.
require_once('../../config.php');

global $DB;

// Basic security checks
require_login();
require_sesskey();
header('Content-Type: application/json');

// Get parameters from the frontend AJAX call
$agentconfigkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
$functionname = required_param('function_name', PARAM_ALPHANUMEXT);
$usertext = required_param('user_text', PARAM_RAW);

// 1. Fetch the unified agent's configuration from the LOCAL plugin's table.
$agent = $DB->get_record('local_ai_functions', ['agent_key' => $agentconfigkey]);

if (!$agent) {
    http_response_code(404);
    echo json_encode(['response' => "[DEBUG] Agent configuration error: An agent with the key '{$agentconfigkey}' was not found in the 'local_ai_functions' table."]);
    exit;
}

// 2. Extract the base endpoint and the JSON blob of keys
$base_endpoint = $agent->endpoint;
$config = json_decode($agent->config_data);

if (!$config || !isset($config->{$functionname})) {
    http_response_code(404);
    echo json_encode(['response' => "[DEBUG] Function key error: The key for the function '{$functionname}' was not found in the config_data for agent '{$agentconfigkey}'."]);
    exit;
}

// 3. Get the specific key and construct the final URL
$secret = $config->{$functionname};
$final_endpoint = rtrim($base_endpoint, '/') . '/' . $functionname;


// --- DUMMY LOGIC SECTION ---
$dummy_responses = [
    'ask_agent' => "This is a dummy RAG response about '{$usertext}'. It confirms the 'ask_agent' function was called successfully.",
    'mcq' => "Here are 5 dummy MCQs about '{$usertext}'. This confirms the 'mcq' function was called successfully.",
    'youtube_summarize' => "This is a dummy summary of the YouTube video with ID '{$usertext}'. This confirms the 'youtube_summarize' function was called.",
    'websearch' => "This is a dummy web search result for '{$usertext}'. This confirms the 'websearch' function was called."
];

if (isset($dummy_responses[$functionname])) {
    sleep(1);
    echo json_encode(['response' => $dummy_responses[$functionname]]);
} else {
    http_response_code(404);
    echo json_encode(['response' => "[DEBUG] Dummy data error: No dummy response was found for the function '{$functionname}'."]);
}
exit;
