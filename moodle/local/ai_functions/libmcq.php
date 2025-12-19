<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Main entry: used by ask_agent_ajax.php and mcq_widget_ajax.php.
 *
 * @param string $agentconfigkey Agent key in mdl_local_ai_functions_agents.agent_key
 * @param string $functionname   Function key inside config_data JSON (e.g. "ask_agent", "mcq")
 * @param array  $payload        Request payload (includes 'stream' flag)
 * @return mixed For stream=true: void (SSE). For stream=false: string (LLM text)
 */
function local_ai_functions_call_endpoint(string $agentconfigkey, string $functionname, array $payload) {
    global $DB, $ai_functions_uses_responses_api;

    $stream = !empty($payload['stream']);

    // 1. Read agent row from DB (your existing design).
    $agent = $DB->get_record('local_ai_functions_agents', ['agent_key' => $agentconfigkey]);
    if (!$agent) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Agent not found: {$agentconfigkey}"]) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => "Agent not found: {$agentconfigkey}"]);
    }

    // 2. Parse config_data JSON and pick function.
    $config = json_decode($agent->config_data, true);
    if (!$config || !isset($config[$functionname])) {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => "Function not configured: {$functionname}"]) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => "Function not found: {$functionname}"]);
    }

    $function_config = $config[$functionname];
    $endpoint   = $function_config['endpoint']   ?? '';
    $api_key    = $function_config['api_key']    ?? '';
    $api_version= $function_config['api_version']?? '2024-05-01-preview';
    $model      = $function_config['model']      ?? 'Phi-4';

    if ($endpoint === '' || $api_key === '') {
        if ($stream) {
            echo "event: error\n";
            echo "data: " . json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']) . "\n\n";
            flush();
            return;
        }
        return json_encode(['error' => 'Invalid agent configuration (endpoint/api_key missing)']);
    }

    // Ensure model goes into payload if not already present.
    if (!isset($payload['model'])) {
        $payload['model'] = $model;
    }

    // 3. Determine API style: Responses API (gpt‑5‑mini) vs Chat Completions (Phi‑4).
    //    - Responses API: /openai/responses or model name containing "gpt-5".
    $uses_responses_api =
        (strpos($endpoint, '/openai/responses') !== false) ||
        (stripos($model, 'gpt-5') !== false);

    $full_url = $endpoint . '?api-version=' . urlencode($api_version);
	//$full_url = $endpoint;
        // NON-STREAMING MODE (mcq_widget_ajax.php).
        return local_ai_functions_handle_non_streaming($full_url, $payload, $api_key, $uses_responses_api);

}

/* -------------------------------------------------------------------------
 *  NON-STREAMING HANDLER  (used by mcq_widget_ajax.php)
 * ------------------------------------------------------------------------- */

function local_ai_functions_handle_non_streaming(string $full_url, array $payload, string $api_key, bool $uses_responses_api): string {
    $ch = curl_init();

    curl_setopt_array($ch, [
        CURLOPT_URL            => $full_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $api_key,
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 240,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $response  = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_err  = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return json_encode(['error' => 'cURL error: ' . $curl_err]);
    }

    if ($http_code !== 200) {
        return json_encode([
            'error'      => 'API HTTP error',
            'http_code'  => $http_code,
            'response'   => substr($response, 0, 500),
        ]);
    }
/*
    $data = json_decode($response, true);
    if (!is_array($data)) {
        // Not JSON → return raw for the caller to inspect.
        return $response;
    }

    $content = local_ai_functions_extract_content($data, $uses_responses_api);
    if ($content === null) {
        // Fallback to full JSON string so your existing parser can still try.
        return $response;
    }

    return $content;
*/
	return $response;
}

/**
 * Extract assistant text from non-streaming JSON, for both API types.
 */
function local_ai_functions_extract_content(array $data, bool $uses_responses_api): ?string {
    if ($uses_responses_api) {
        // GPT‑5‑mini / Responses API shapes. [web:4][web:22][web:27]
        if (isset($data['output'][0]['content'][0]['text'])) {
            return $data['output'][0]['content'][0]['text'];
        }
        if (isset($data['output'][0]['text'])) {
            return $data['output'][0]['text'];
        }
        if (isset($data['output']['text'])) {
            return $data['output']['text'];
        }
        return null;
    } else {
        // Phi‑4 / Chat Completions JSON. [web:11][web:26]
        if (isset($data['choices'][0]['message']['content'])) {
            return $data['choices'][0]['message']['content'];
        }
        return null;
    }
}