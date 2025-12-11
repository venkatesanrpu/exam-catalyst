<?php
/**
 * MCQ Widget AJAX Endpoint (Flashcard JSON Response)
 * FILE: blocks/ai_assistant/ajax/mcq_widget_ajax.php
 * PURPOSE: Generate MCQs via non-streaming, parse text to JSON, store JSON only
 * VERSION: Production - Non-Streaming with JSON Storage
 */

define('AJAX_SCRIPT', true);


require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/libmcq.php');
//require_once($CFG->dirroot . '/local/ai_functions/lib_dummy.php');

require_login();
require_sesskey();


header('Content-Type: application/json');
header('Cache-Control: no-cache');
while (ob_get_level() > 0) {
    ob_end_clean();
}

set_time_limit(240);
ini_set('max_execution_time', 240);

try {
    global $DB, $USER, $CFG;
    
    // ==================== GET PARAMETERS ====================
    //error_log('MCQ Widget - Starting parameter collection');
    
    $agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
    $level = required_param('level', PARAM_ALPHA);
    $agenttext = required_param('agent_text', PARAM_RAW);
    $target = optional_param('target', '', PARAM_TEXT);
    $subject = optional_param('subject', 'Chemistry', PARAM_TEXT);
    $topic = optional_param('topic', '', PARAM_TEXT);
    $lesson = optional_param('lesson', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_RAW);
    $courseid = required_param('courseid', PARAM_ALPHANUMEXT);
	$mainsubjectkey = required_param('mainsubject', PARAM_TEXT);
	$questionCount = required_param('number', PARAM_INT);
    
    //error_log('MCQ Widget - Parameters: agent_key=' . $agentkey . ', level=' . $level . ', courseid=' . $courseid);
    //error_log('MCQ Widget - Agent text: ' . substr($agenttext, 0, 100));
    
    $valid_levels = ['basic', 'intermediate', 'advanced'];
    if (!in_array($level, $valid_levels)) {
        throw new Exception('Invalid level: ' . $level);
    }

    // ==================== DETERMINE QUESTION COUNT ====================
    switch ($level) {
        case 'basic':
            $question_count = $questionCount;
			
			$max_tokens = 3000;
            break;
        case 'intermediate':
            $question_count = $questionCount;
			
			$max_tokens = 4000;
            break;
        case 'advanced':
            $question_count = $questionCount;
			
			$max_tokens = 4000;
            break;
        default:
            $question_count = 5;
    }
    
    
    
    // ==================== LOAD EXISTING MCQ PROMPT ====================
	$prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/mcq_' .$level.'.txt';
	
    if (!file_exists($prompt_file)) {
        throw new Exception('MCQ prompt template not found');
    }
    
    $prompt_template = file_get_contents($prompt_file);
//error_log('MCQ Widget - Loaded prompt template (' . strlen($prompt_template) . ' chars)');
//error_log($prompt_template);
    
    // Replace placeholders
    $system_prompt = str_replace(
        ['{QUESTION_COUNT}', '{TARGET_EXAM}', '{SUBJECT}', '{TOPIC}', '{LESSON}', '{LEVEL}', '{AGENT_TEXT}', '{TAGS}'],
        [$question_count, $target, $subject, $topic, $lesson, strtolower($level), $agenttext, $tags],
        $prompt_template
    );
	//error_log('System Prompts: ' . $system_prompt);
	
    
    //error_log('MCQ Widget - System prompt prepared (' . strlen($system_prompt) . ' chars)');
    
    // ==================== BUILD PAYLOAD (NON-STREAMING) ====================
    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Generate {$question_count} MCQs on: {$agenttext}"]
        ],
        'stream' => false,
        'max_tokens' => $max_tokens,
        'temperature' => 0.6,
        'top_p' => 0.9,
		'frequency_penalty'=> 0.7,
        'presence_penalty'=> 0.8,
		'n'=>1
    ];
    //error_log('MCQ Widget - Payload built: max_tokens=' . ($max_tokens) . ', stream=false');
    
    // ==================== CALL LLM API ====================
    //error_log('MCQ Widget - Starting non-streaming API call for ' . $question_count . ' questions');
    $start_time = microtime(true);
    
    // ✅ Call API - now returns full JSON response
    $full_response = local_ai_functions_call_endpoint($agentkey, 'mcq', $payload);
    
    $api_duration = round((microtime(true) - $start_time) * 1000);
    //error_log('MCQ Widget - API call completed in ' . $api_duration . 'ms');
    
    if (!$full_response) {
        throw new Exception('Empty response from LLM API');
    }
    
    //error_log('MCQ Widget - Full response received (' . strlen($full_response) . ' chars)');
    //error_log('MCQ Widget - Full response preview: ' . substr($full_response, 0, 300));
    
    // ==================== PARSE FULL API RESPONSE ====================
    //error_log('MCQ Widget - Starting JSON decode of full response');
    $response_data = json_decode($full_response, true);
    //error_log('FULL RESPONSE: ' . $response_data);
    if (json_last_error() !== JSON_ERROR_NONE) {
        //error_log('MCQ Widget - JSON decode error: ' . json_last_error_msg());
        //error_log('MCQ Widget - Failed JSON string: ' . substr($full_response, 0, 500));
        throw new Exception('API response is not valid JSON: ' . json_last_error_msg());
    }
    
    //error_log('MCQ Widget - JSON decoded successfully');
    //error_log('MCQ Widget - Response data keys: ' . json_encode(array_keys($response_data)));
    
    // ==================== EXTRACT CONTENT TEXT ====================
    //error_log('MCQ Widget - Extracting content text from response');
    $response_text = '';
    
    if (isset($response_data['choices'][0]['message']['content'])) {
        $response_text = $response_data['choices'][0]['message']['content'];
        //error_log('MCQ ##### Widget - ✅ Extracted from choices[0].message.content');
    } elseif (isset($response_data['content'])) {
        $response_text = $response_data['content'];
        //error_log('MCQ >>>> Widget - ✅ Extracted from content field');
    } elseif (isset($response_data['error'])) {
        //error_log('MCQ %%%%%% Widget - ❌ API returned error: ' . json_encode($response_data['error']));
        throw new Exception('API Error: ' . json_encode($response_data['error']));
    } else {
        //error_log('MCQ xxxxx Widget - ❌ Unknown response format. Keys: ' . json_encode(array_keys($response_data)));
        throw new Exception('Unknown API response format');
    }
    
    if (empty($response_text)) {
        //error_log('MCQ Widget - ❌ Extracted text is empty');
        throw new Exception('No text content in API response');
    }
    
    //error_log('MCQ Widget - ✅ Content text extracted (' . strlen($response_text) . ' chars)');
    //error_log('MCQ Widget - Content text preview: ' . substr($response_text, 0, 200));
    
    // ==================== EXTRACT METADATA ====================
    //error_log('MCQ Widget - Extracting API metadata');
    
    $api_metadata = [
        'model' => $response_data['model'] ?? null,
        'id' => $response_data['id'] ?? null,
        'created' => $response_data['created'] ?? null,
        'object' => $response_data['object'] ?? null,
        'finish_reason' => $response_data['choices'][0]['finish_reason'] ?? null,
        'usage' => $response_data['usage'] ?? null,
        'system_fingerprint' => $response_data['system_fingerprint'] ?? null,
        'prompt_filter_results' => $response_data['prompt_filter_results'] ?? null,
        'content_filter_results' => $response_data['choices'][0]['content_filter_results'] ?? null
    ];
    
    //error_log('MCQ Widget - ✅ Metadata extracted:');
    //error_log('MCQ Widget -   Model: ' . ($api_metadata['model'] ?? 'N/A'));
    //error_log('MCQ Widget -   ID: ' . ($api_metadata['id'] ?? 'N/A'));
    //error_log('MCQ Widget -   Created: ' . ($api_metadata['created'] ?? 'N/A'));
    //error_log('MCQ Widget -   Finish reason: ' . ($api_metadata['finish_reason'] ?? 'N/A'));
    
    if (isset($api_metadata['usage'])) {
        //error_log('MCQ Widget -   Usage: ' . json_encode($api_metadata['usage']));
    } else {
        //error_log('MCQ Widget -   Usage: Not available');
    }
    
    // ==================== PARSE TEXT TO JSON ====================
    //error_log('MCQ Widget - Starting MCQ text parsing');
    $mcq_data = parse_mcq_text_to_json($response_text);
    //error_log('RESPONSE TEXT: ' . $response_text);
    if (!$mcq_data || !isset($mcq_data['questions']) || count($mcq_data['questions']) === 0) {
        //error_log('MCQ Widget - ❌ Parser failed');
        //error_log('MCQ Widget - Text sent to parser: ' . substr($response_text, 0, 500));
        throw new Exception('Failed to parse MCQ text into structured format. Please check logs.');
    }
    
    $questions_parsed = count($mcq_data['questions']);
    //error_log('MCQ Widget - ✅ Successfully parsed ' . $questions_parsed . ' questions');
    
    // ==================== ADD APPLICATION METADATA ====================
    //error_log('MCQ Widget - Adding application metadata');
    
    $mcq_data['metadata'] = [
        'level' => $level,
        'count' => $questions_parsed,
        'subject' => $subject,
        'topic' => $topic,
        'lesson' => $lesson,
        'target_exam' => $target,
        'agent_text' => $agenttext,
        'tags' => $tags,
        'is_dummy' => false,
        'generated_at' => time(),
        'api_duration_ms' => $api_duration,
        'format_version' => '1.0'
    ];
    
    //error_log('MCQ Widget - Application metadata added');
    
    // ==================== STORE IN DATABASE ====================
    //error_log('MCQ Widget - Preparing database record');
    
    $usertext = strtoupper($level) . " MCQ: " . $agenttext;
    $json_response = json_encode($mcq_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    $json_metadata = json_encode($api_metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    //error_log('MCQ Widget - JSON response size: ' . strlen($json_response) . ' chars');
    //error_log('MCQ Widget - JSON metadata size: ' . strlen($json_metadata) . ' chars');
    
    $history_record = new stdClass();
    $history_record->userid = $USER->id;
    $history_record->courseid = $courseid;
    $history_record->usertext = $usertext;
    $history_record->botresponse = $json_response;
    $history_record->metadata = $json_metadata;  // ✅ Store API metadata separately
    $history_record->functioncalled = 'mcq_widget';
    $history_record->subject = $subject;
    $history_record->topic = $topic;
    $history_record->lesson = $lesson;
    $history_record->timecreated = time();
    $history_record->timemodified = time();
    
    //error_log('MCQ Widget - Inserting record into database');
    $history_id = $DB->insert_record('block_ai_assistant_history', $history_record);
    
    if (!$history_id) {
        //error_log('MCQ Widget - ❌ Database insert failed');
        throw new Exception('Failed to store MCQ data in database');
    }
    
    //error_log('MCQ Widget - ✅ Stored to database (history_id: ' . $history_id . ')');
    
    // Add history_id to metadata
    $mcq_data['metadata']['history_id'] = $history_id;
    
    // ==================== RETURN SUCCESS ====================
    //error_log('MCQ Widget - Preparing success response');
    
    $success_response = [
        'status' => 'success',
        'data' => $mcq_data,
        'api_metadata' => $api_metadata  // ✅ Include metadata in response
    ];
    
    //error_log('MCQ Widget - ✅ SUCCESS - Returning response with ' . $questions_parsed . ' questions');
    //error_log('MCQ Widget - Response includes: data, api_metadata');
    
    echo json_encode($success_response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    //error_log('MCQ Widget - ❌ EXCEPTION CAUGHT');
    //error_log('MCQ Widget Error: ' . $e->getMessage());
    //error_log('MCQ Widget Error Location: ' . $e->getFile() . ':' . $e->getLine());
    //error_log('MCQ Widget Error Trace: ' . $e->getTraceAsString());
    
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'code' => 'MCQ_GENERATION_ERROR'
    ], JSON_PRETTY_PRINT);
}

// ==================== TEXT PARSER FUNCTION ====================
/**
 * Parse MCQ text format to JSON structure
 * Handles format: Q1. ... A. ... B. ... **Answer: A** **Explanation:** ...
 * 
 * @param string $text Raw MCQ text from LLM
 * @return array Structured MCQ data with questions array
 */
function parse_mcq_text_to_json($text) {
    //error_log('MCQ Parser - Starting parse operation');
    //error_log('MCQ Parser - Input text length: ' . strlen($text) . ' chars');
    
    $questions = [];
    
    // Remove "**DONE**" marker if present
    $text = str_replace('**DONE**', '', $text);
    $text = trim($text);
    
    //error_log('MCQ Parser - Text cleaned, starting pattern matching');
    
    // Split by horizontal line separator (---) or question pattern
    $parts = preg_split('/(^|\n)(?=Q\d+\.)/m', $text);
    //error_log('MCQ Parser - Split into ' . count($parts) . ' parts');
    
    foreach ($parts as $part_index => $part) {
        $part = trim($part);
        if (empty($part)) {
            //error_log("MCQ Parser - Part {$part_index}: Empty, skipping");
            continue;
        }
        
        //error_log("MCQ Parser - Part {$part_index}: Processing (" . strlen($part) . " chars)");
        
        // Extract question number
        if (!preg_match('/^Q(\d+)\.\s*/i', $part, $q_num_match)) {
            //error_log("MCQ Parser - Part {$part_index}: No question number found, skipping");
            continue;
        }
        
        $question_num = (int)$q_num_match[1];
        //error_log("MCQ Parser - Processing Q{$question_num}");
        
        // Remove the Q1. prefix
        $part = preg_replace('/^Q\d+\.\s*/i', '', $part);
        
        // Split by separator (---) 
        $sections = preg_split('/\n---+\n/s', $part);
        $question_section = trim($sections[0]);
        
        //error_log("MCQ Parser - Q{$question_num}: Question section length: " . strlen($question_section));
        
        // Extract question text (everything before options A.)
        if (!preg_match('/^(.+?)(?=\s*\n\s*A\.)/s', $question_section, $q_text_match)) {
            //error_log("MCQ Parser - Q{$question_num}: ❌ Could not extract question text");
            continue;
        }
        
        $question_text = trim($q_text_match[1]);
        //error_log("MCQ Parser - Q{$question_num}: Question text: " . substr($question_text, 0, 80));
        
        // Extract options A. B. C. D.
        $options = [];
        $option_pattern = '/([A-D])\.\s*(.+?)(?=\s*\n\s*[A-D]\.|$|\*\*Answer)/s';
        
        if (preg_match_all($option_pattern, $question_section, $opt_matches, PREG_SET_ORDER)) {
            //error_log("MCQ Parser - Q{$question_num}: Found " . count($opt_matches) . " option matches");
            
            foreach ($opt_matches as $opt_index => $opt) {
                $option_text = trim($opt[2]);
                // Clean up option text
                $option_text = preg_replace('/\s+/', ' ', $option_text);
                $options[] = $option_text;
                //error_log("MCQ Parser - Q{$question_num}: Option " . $opt[1] . ": " . substr($option_text, 0, 50));
            }
        } else {
            //error_log("MCQ Parser - Q{$question_num}: ❌ No options found");
        }
        
        // Extract correct answer: **Answer: A**
        $correct = '';
        if (preg_match('/\*\*Answer:\s*([A-D])\*\*/i', $question_section, $ans_match)) {
            $correct = strtoupper(trim($ans_match[1]));
            //error_log("MCQ Parser - Q{$question_num}: Correct answer: {$correct}");
        } else {
            //error_log("MCQ Parser - Q{$question_num}: ❌ Correct answer not found");
        }
        
        // Extract explanation: **Explanation:** ...
        $explanation = '';
        if (preg_match('/\*\*Explanation:\*\*\s*(.+?)$/s', $question_section, $exp_match)) {
            $explanation = trim($exp_match[1]);
            // Clean up explanation
            $explanation = preg_replace('/^\s*[-•]\s*/m', '', $explanation);
            $explanation = preg_replace('/\n{3,}/', "\n\n", $explanation);
            $explanation = rtrim($explanation, '-');
            $explanation = trim($explanation);
            //error_log("MCQ Parser - Q{$question_num}: Explanation length: " . strlen($explanation) . " chars");
        } else {
            //error_log("MCQ Parser - Q{$question_num}: ⚠️ No explanation found");
        }
        
        // Validate question structure
        $is_valid = true;
        $errors = [];
        
        if (empty($question_text)) {
            $errors[] = 'Missing question text';
            $is_valid = false;
        }
        
        if (count($options) < 4) {
            $errors[] = 'Less than 4 options (' . count($options) . ' found)';
            $is_valid = false;
        }
        
        if (empty($correct) || !preg_match('/^[A-D]$/', $correct)) {
            $errors[] = 'Invalid or missing correct answer';
            $is_valid = false;
        }
        
        if (empty($explanation)) {
            $explanation = 'No explanation provided.';
            //error_log("MCQ Parser - Q{$question_num}: ⚠️ Using default explanation");
        }
        
        if ($is_valid) {
            $questions[] = [
                'question' => $question_text,
                'options' => array_slice($options, 0, 4),
                'correct' => $correct,
                'explanation' => $explanation
            ];
            //error_log("MCQ Parser - Q{$question_num}: ✅ Successfully parsed and added");
        } else {
            //error_log("MCQ Parser - Q{$question_num}: ❌ Validation failed: " . implode(', ', $errors));
        }
    }
    
    //error_log('MCQ Parser - ✅ Parse complete: ' . count($questions) . ' valid questions extracted');
    
    return ['questions' => $questions];
}
?>
