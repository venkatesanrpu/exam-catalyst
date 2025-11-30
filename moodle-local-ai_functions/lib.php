<?php
/**
 * FILE: moodle/local/ai_functions/lib.php
 * UPDATED: Added streaming support with $stream parameter
 */

defined('MOODLE_INTERNAL') || die();

/**
 * The central function to call an AI endpoint with optional streaming support.
 * 
 * @param string $agentconfigkey The agent_key of the configured agent (e.g., 'CISRCHEM').
 * @param string $functionname The specific function to call (e.g., 'ask_agent', 'mcq', 'websearch', 'youtube_summarize').
 * @param array|stdClass $payload The data to be JSON-encoded and sent to the endpoint.
 * @param bool $stream Whether to enable SSE streaming (default: false).
 * @return string|void The JSON response from the endpoint, or streams SSE events if $stream is true.
 */
function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload, $stream = false) {
    global $DB;

    // --- DEBUGGING: Dummy Logic (remove in production) ---
    $dummy_responses = [
        'ask_agent' => 'This \\[\\Delta\\] is a \\[\\frac{1}{2}\\] dummy RAG response $\\alpha$. The \'ask_agent\' function \\(\\frac{1}{2}\\) was called correctly.',
        'mcq' => 'Here are 5 dummy MCQs. The mcq function was called correctly.',
        'youtube_summarize' => 'This is a dummy YouTube summary. The youtube_summarize function was called correctly.',
        'websearch' => 'This is a dummy web search result. The websearch function was called correctly.'
    ];

    if (isset($dummy_responses[$functionname])) {
        if ($stream) {
            // Simulate streaming response for testing
            $words = explode(' ', $dummy_responses[$functionname]);
            foreach ($words as $word) {
                echo "event: chunk\n";
                echo "data: " . json_encode(['content' => $word . ' ']) . "\n\n";
                flush();
                usleep(100000); // 100ms delay per word for visual effect
            }
            
            // Send metadata
            echo "event: metadata\n";
            echo "data: " . json_encode([
                'finish_reason' => 'stop',
                'model' => 'dummy-model',
                'completion_id' => 'dummy-' . uniqid()
            ]) . "\n\n";
            flush();
            
            // Send done signal
            echo "event: done\n";
            echo "data: {}\n\n";
            flush();
            return; // Don't return a value, just stream
        } else {
            $debug_payload = json_encode($payload, JSON_PRETTY_PRINT);
            $response_text = $dummy_responses[$functionname] . "<br/><pre>Payload Received:\n" . $debug_payload . "</pre>";
            sleep(1); // Simulate network latency
            return json_encode(['response' => $response_text]);
        }
    }
    // --- End of Dummy Logic ---

    // FIXED: Use agent_key column name
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    
    if (!$agent) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Agent '$agentconfigkey' not found in database"]) . "\n\n";
            flush();
            return;
        }
        http_response_code(404);
        return json_encode([
            'error' => 'Configuration Error',
            'message' => "Agent '$agentconfigkey' not found in the database."
        ]);
    }

    // FIXED: Use config_data column name
    $config = json_decode($agent->config_data, true);
    
    if (!$config) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Invalid JSON in config_data']) . "\n\n";
            flush();
            return;
        }
        http_response_code(500);
        return json_encode([
            'error' => 'Configuration Error',
            'message' => "Invalid JSON in config_data for agent '$agentconfigkey'."
        ]);
    }

    // Check if the requested function exists in config
    if (!isset($config[$functionname])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Function '$functionname' not found in config"]) . "\n\n";
            flush();
            return;
        }
        http_response_code(404);
        return json_encode([
            'error' => 'Configuration Error',
            'message' => "Function '$functionname' not found in config_data for agent '$agentconfigkey'."
        ]);
    }

    $function_config = $config[$functionname];

    // Validate function configuration has required fields
    if (!isset($function_config['endpoint']) || !isset($function_config['api_key'])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Missing endpoint or api_key in config']) . "\n\n";
            flush();
            return;
        }
        http_response_code(500);
        return json_encode([
            'error' => 'Configuration Error',
            'message' => "Function '$functionname' is missing 'endpoint' or 'api_key' in config_data."
        ]);
    }

    $endpoint = $function_config['endpoint'];
    $api_key = $function_config['api_key'];

    // Add 'stream' parameter to payload if streaming is enabled
    if ($stream && is_array($payload)) {
        $payload['stream'] = true;
    }

    // --- Real cURL Request Logic ---
    $ch = curl_init();
    
    if ($stream) {
        // === STREAMING MODE ===
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false, // Don't buffer, stream directly
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                // This callback receives chunks as they arrive from the API
                $length = strlen($data);
                
                // Forward the raw SSE data directly to the client
                echo $data;
                flush();
                
                return $length; // Must return the number of bytes processed
            },
            CURLOPT_TIMEOUT => 120, // Longer timeout for streaming
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $result = curl_exec($ch);
        
        if ($result === false) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => curl_error($ch)]) . "\n\n";
            flush();
        }
        
        curl_close($ch);
        
        // Send final done event to ensure client closes connection
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
        
    } else {
        // === NON-STREAMING MODE ===
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response_body = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            http_response_code(500);
            return json_encode([
                'error' => 'Network Error',
                'message' => "cURL Error: $curl_error"
            ]);
        }

        if ($http_code !== 200) {
            http_response_code($http_code);
            return json_encode([
                'error' => 'Endpoint Error',
                'message' => "Endpoint returned HTTP $http_code",
                'response' => $response_body
            ]);
        }

        return $response_body;
    }
}
