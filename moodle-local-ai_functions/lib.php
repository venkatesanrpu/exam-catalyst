<?php
/**
 * FILE: moodle/local/ai_functions/lib.php
 * FIXED: Handles both streaming SSE and complete JSON responses
 */

defined('MOODLE_INTERNAL') || die();

function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload) {
    global $DB;

    // Extract stream parameter from payload
    $stream = false;
    if (is_array($payload) && isset($payload['stream'])) {
        $stream = (bool)$payload['stream'];
        unset($payload['stream']); // Remove from payload, will add back if needed
    }

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
            flush();
            return;
        }
        return json_encode(['error' => 'Missing endpoint or api_key']);
    }

    $endpoint = $function_config['endpoint'];
    $api_key = $function_config['api_key'];

    // Add stream parameter to API payload if streaming
    if ($stream && is_array($payload)) {
        $payload['stream'] = true;
    }

    // --- Make API Request ---
    $ch = curl_init();
    
    if ($stream) {
        // === STREAMING MODE (SSE) ===
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
            CURLOPT_RETURNTRANSFER => true,  // Buffer to detect format
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true
        ]);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($http_code !== 200) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "API returned HTTP $http_code", 'response' => $response]) . "\n\n";
            flush();
            echo "event: done\n";
            echo "data: {}\n\n";
            flush();
            return;
        }
        
        // Check if response is complete JSON (not SSE stream)
        $jsonResponse = json_decode($response, true);
        
        if ($jsonResponse && isset($jsonResponse['choices'][0]['message']['content'])) {
            // API returned complete JSON - convert to SSE chunks
            $content = $jsonResponse['choices'][0]['message']['content'];
            
            // Split into words for streaming effect
            $words = preg_split('/(\s+)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            
            foreach ($words as $word) {
                if (!empty($word)) {
                    echo "event: chunk\n";
                    echo "data: " . json_encode(['content' => $word]) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                    usleep(15000); // 15ms delay for smooth streaming
                }
            }
            
            // Send metadata
            echo "event: metadata\n";
            echo "data: " . json_encode([
                'finish_reason' => $jsonResponse['choices'][0]['finish_reason'] ?? 'stop',
                'model' => $jsonResponse['model'] ?? 'unknown',
                'total_tokens' => $jsonResponse['usage']['total_tokens'] ?? 0
            ]) . "\n\n";
            flush();
            
        } else {
            // Response is already SSE format or error
            echo $response;
            flush();
        }
        
        // Send done signal
        echo "event: done\n";
        echo "data: {}\n\n";
        flush();
        
    } else {
        // === NON-STREAMING MODE (Regular JSON) ===
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
            return json_encode(['error' => 'API Error', 'http_code' => $http_code, 'response' => $response_body]);
        }

        return $response_body;
    }
}
