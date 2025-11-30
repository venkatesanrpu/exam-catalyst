<?php
defined('MOODLE_INTERNAL') || die();

function local_ai_functions_call_endpoint($agentconfigkey, $functionname, $payload) {
    global $DB;

    $stream = isset($payload['stream']) ? (bool)$payload['stream'] : false;

    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    
    if (!$agent) {
        if ($stream) {
            echo "event: error\ndata: " . json_encode(['error' => "Agent not found"]) . "\n\n";
            flush();
        }
        return json_encode(['error' => "Agent not found"]);
    }

    $config = json_decode($agent->config_data, true);
    
    if (!$config || !isset($config[$functionname])) {
        if ($stream) {
            echo "event: error\ndata: " . json_encode(['error' => "Function not configured"]) . "\n\n";
            flush();
        }
        return json_encode(['error' => "Function not found"]);
    }

    $function_config = $config[$functionname];
    $endpoint = $function_config['endpoint'];
    $api_key = $function_config['api_key'];
    $api_version = $function_config['api_version'] ?? '2024-05-01-preview';
    $model = $function_config['model'] ?? 'Phi-4';
    
    // Ensure model in payload
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }
    
    $full_url = $endpoint . '?api-version=' . urlencode($api_version);
    $ch = curl_init();
    
    if ($stream) {
    // STREAMING MODE
    echo ": connected\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $full_url,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_WRITEFUNCTION => function($curl, $data) {
            static $buffer = '';
            $length = strlen($data);
            $buffer .= $data;
            
            while (($pos = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $pos + 2);
                $buffer = substr($buffer, $pos + 2);
                
                if (trim($event) === '' || strpos($event, ':') === 0) continue;
                
                if (preg_match('/^data: (.+)$/m', $event, $matches)) {
                    $json_data = trim($matches[1]);
                    
                    if ($json_data === '[DONE]') {
                        echo "event: done\n";
                        echo "data: {}\n\n";
                        if (ob_get_level() > 0) ob_flush();
                        flush();
                        continue;
                    }
                    
                    $chunk = json_decode($json_data, true);
                    if ($chunk && isset($chunk['choices'][0]['delta']['content'])) {
                        $content = $chunk['choices'][0]['delta']['content'];
                        
                        if ($content !== '') {
                            echo "event: chunk\n";
                            echo "data: " . json_encode(['content' => $content]) . "\n\n";
                            if (ob_get_level() > 0) ob_flush();
                            flush();
                        }
                    }
                    
                    if (isset($chunk['choices'][0]['finish_reason']) && 
                        $chunk['choices'][0]['finish_reason'] !== null) {
                        echo "event: metadata\n";
                        echo "data: " . json_encode([
                            'finish_reason' => $chunk['choices'][0]['finish_reason']
                        ]) . "\n\n";
                        flush();
                    }
                }
            }
            
            return $length;
        },
        CURLOPT_TIMEOUT => 0,              // CHANGED: No timeout (infinite)
        CURLOPT_CONNECTTIMEOUT => 30,      // Keep 30s connection timeout
        CURLOPT_LOW_SPEED_LIMIT => 1,      // NEW: Min 1 byte/second
        CURLOPT_LOW_SPEED_TIME => 60,      // NEW: Abort if speed < 1 byte/s for 60s
        CURLOPT_SSL_VERIFYPEER => true
    ]);
    
    $curl_result = curl_exec($ch);
    $curl_error = curl_error($ch);
    
    if ($curl_error) {
        echo "event: error\n";
        echo "data: " . json_encode(['error' => $curl_error]) . "\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();
    }
    
    curl_close($ch);
    
    // Always send final done event
    echo "event: done\n";
    echo "data: {}\n\n";
    if (ob_get_level() > 0) ob_flush();
    flush();
} else {
        // NON-STREAMING MODE
        curl_setopt_array($ch, [
            CURLOPT_URL => $full_url,
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

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ($http_code === 200) ? $response : json_encode(['error' => 'API Error', 'http_code' => $http_code]);
    }
}
