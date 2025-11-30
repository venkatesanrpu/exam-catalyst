<?php
// FILE: moodle/local/ai_functions/lib.php
// UPDATE: The dummy logic has been moved to the top of the function.
// This bypasses the validation checks for now to guarantee a response for testing.

defined('MOODLE_INTERNAL') || die();

/**
 * The central function to call an Azure Function endpoint.
 * It handles all security, configuration lookup, and cURL execution.
 *
 * @param string $agentconfigkey The agent_key of the configured agent (e.g., 'chemistry_ai').
 * @param string $functionname The specific function to call (e.g., 'ask_agent').
 * @param array|stdClass $payload The data to be JSON-encoded and sent to the Azure Function.
 * @return string The JSON response from the Azure Function, or a JSON-encoded error.
 */
function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload) {
    global $DB;

    // --- DEBUGGING: Dummy Logic moved to the top ---
    // This section now runs FIRST to ensure a response is always sent if the file is reached.
    $dummy_responses = [
        'ask_agent'         => "This \[\Delta\] is a \[\\frac{1}{2}\] dummy RAG response $\alpha$. The 'ask_agent' function \(\\frac{1}{2}\)was called correctly.",
        'mcq'               => "Here are 5 dummy MCQs. The 'mcq' function was called correctly.",
        'youtube_summarize' => "This is a dummy YouTube summary. The 'youtube_summarize' function was called correctly.",
        'websearch'         => "This is a dummy web $$\Gamma$$ search result. The 'websearch' function was called correctly."
    ];

    if (isset($dummy_responses[$functionname])) {
        // We are adding the received payload to the response for debugging purposes.
        $debug_payload = json_encode($payload, JSON_PRETTY_PRINT);
        $response_text = $dummy_responses[$functionname] . "<br><pre>Payload Received:\n" . $debug_payload . "</pre>";
        sleep(1); // Simulate network latency
        return json_encode(['response' => $response_text]);
    }
    // --- End of Dummy Logic ---


    // --- Security & Validation Stage (will be bypassed if a dummy response exists) ---
    $agent = $DB->get_record('local_ai_functions', ['agent_key' => $agentconfigkey]);

    if (!$agent) {
        http_response_code(404);
        return json_encode(['response' => "[Configuration Error] Agent '{$agentconfigkey}' not found in the database."]);
    }

    $base_endpoint = $agent->endpoint;
    $config = json_decode($agent->config_data);

    if (!$config || !isset($config->{$functionname})) {
        http_response_code(404);
        return json_encode(['response' => "[Configuration Error] A key for the function '{$functionname}' was not found in the config_data for the '{$agentconfigkey}' agent."]);
    }

    $secret = $config->{$functionname};
    $final_endpoint = rtrim($base_endpoint, '/') . '/' . $functionname;


    // --- Real cURL Request Logic (for production) ---
    /*
    $ch = curl_init();
    // ... cURL implementation ...
    return $response_body;
    */

    // Fallback error if no dummy or real response is generated.
    return json_encode(['response' => 'Error: The function call was valid, but no response was generated.']);
}

