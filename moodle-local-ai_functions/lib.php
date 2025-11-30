<?php
/**
 * FILE: moodle/local/ai_functions/lib.php
 * FIXED: Stream parameter now read from $payload['stream']
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Call an AI endpoint with streaming controlled by payload.
 * 
 * @param string $agentconfigkey The agent_key (e.g., 'CISRCHEM')
 * @param string $functionname The function to call (e.g., 'ask_agent')
 * @param array|stdClass $payload The data to send - if $payload['stream']=true, enables streaming
 * @return string|void The JSON response or streams SSE events
 */
function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload) {
    global $DB;

    // FIXED: Extract stream parameter from payload
    $stream = false;
    if (is_array($payload) && isset($payload['stream'])) {
        $stream = (bool)$payload['stream'];
        // Don't send 'stream' flag in the API payload itself, let lib.php add it
        unset($payload['stream']);
    }

    // --- DEBUGGING: Dummy Logic (comment out for production) ---
    /*
    $dummy_responses = [
        'ask_agent' => 'This \\[\\Delta\\] is a \\[\\frac{1}{2}\\] dummy RAG response $\\alpha$. The \'ask_agent\' function \\(\\frac{1}{2}\\) was called correctly.',
        'mcq' => 'Here are 5 dummy MCQs.',
        'youtube_summarize' => 'This is a dummy YouTube summary.',
        'websearch' => 'This is a dummy web search result.'
    ];

    if (isset($dummy_responses[$functionname])) {
        if ($stream) {
            // Simulate streaming
            $words = explode(' ', $dummy_responses[$functionname]);
            foreach ($words as $word) {
                echo "event: chunk\n";
                echo "data: " . json_encode(['content' => $word . ' ']) . "\n\n";
                if (ob_get_level() > 0) ob_flush();
                flush();
                usleep(100000);
            }
            echo "event: metadata\n";
            echo "data: " . json_encode(['finish_reason' => 'stop', 'model' => 'dummy']) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            echo "event: done\n";
            echo "data: {}\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            return;
        } else {
            $debug_payload = json_encode($payload, JSON_PRETTY_PRINT);
            $response_text = $dummy_responses[$functionname] . "<br/><pre>Payload:\n" . $debug_payload . "</pre>";
            sleep(1);
            return json_encode(['response' => $response_text]);
        }
    }
    */
    // --- End of Dummy Logic ---

    // Fetch agent configuration
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    
    if (!$agent) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Agent '$agentconfigkey' not found"]) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            return;
        }
        http_response_code(404);
        return json_encode(['error' => "Agent '$agentconfigkey' not found"]);
    }

    $config = json_decode($agent->config_data, true);
    
    if (!$config || !isset($config[$functionname])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Function '$functionname' not found"]) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            return;
        }
        return json_encode(['error' => "Function '$functionname' not found"]);
    }

    $function_config = $config[$functionname];

    if (!isset($function_config['endpoint']) || !isset($function_config['api_key'])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Missing endpoint or api_key']) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
            return;
        }
        return json_encode(['error' => 'Missing endpoint or api_key in config']);
    }

    $endpoint = $function_config['endpoint'];
    $api_key = $function_config['api_key'];

    // FIXED: Add 'stream' to payload for API only if streaming is enabled
    if ($stream && is_array($payload)) {
        $payload['stream'] = true;
    }

    // --- Real cURL Request ---
    $ch = curl_init();
    
    if ($stream) {
        // === STREAMING MODE with keepalive ===
        echo ": connected\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
        
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $api_key
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function($curl, $data) {
                static $last_keepalive = 0;
                
                $now = time();
                if ($now - $last_keepalive > 15) {
                    echo ": keepalive\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                    $last_keepalive = $now;
                }
                
                $length = strlen($data);
                echo $data;
                if (ob_get_level() > 0) ob_flush();
                flush();
                
                $last_keepalive = $now;
                return $length;
            },
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_BUFFERSIZE => 128
        ]);
        
        curl_exec($ch);
        
        if (curl_errno($ch)) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => curl_error($ch)]) . "\n\n";
            if (ob_get_level() > 0) ob_flush();
            flush();
        }
        
        curl_close($ch);
        
        echo "event: done\n";
        echo "data: {}\n\n";
        if (ob_get_level() > 0) ob_flush();
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
            return json_encode(['error' => 'Network Error', 'message' => $curl_error]);
        }

        if ($http_code !== 200) {
            http_response_code($http_code);
            return json_encode(['error' => 'Endpoint Error', 'http_code' => $http_code, 'response' => $response_body]);
        }

        return $response_body;
    }
}
