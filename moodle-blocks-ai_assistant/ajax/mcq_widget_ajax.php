<?php
/**
 * MCQ Widget AJAX Endpoint (Flashcard JSON Response)
 * FILE: blocks/ai_assistant/ajax/mcq_widget_ajax.php
 * PURPOSE: Generate MCQs via non-streaming, parse text to JSON, store JSON only
 * VERSION: Production - Non-Streaming with JSON Storage
 */

define('AJAX_SCRIPT', true);

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once(__DIR__ . '/../../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/lib.php');

require_login();

// Sesskey validation
$sesskey_param = optional_param('sesskey', '', PARAM_RAW);
if (!confirm_sesskey($sesskey_param)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid session key',
        'code' => 'INVALID_SESSKEY'
    ]);
    die();
}

header('Content-Type: application/json');
header('Cache-Control: no-cache');

while (ob_get_level() > 0) {
    ob_end_clean();
}

set_time_limit(240);
ini_set('max_execution_time', 240);

try {
    global $DB, $USER;
    
    // ==================== GET PARAMETERS ====================
    
    $agentkey = required_param('agent_config_key', PARAM_ALPHANUMEXT);
    $level = required_param('level', PARAM_ALPHA);
    $agenttext = required_param('agent_text', PARAM_RAW);
    $target = optional_param('target', 'CSIR Chemical Sciences Exam', PARAM_TEXT);
    $subject = optional_param('subject', 'Chemistry', PARAM_TEXT);
    $topic = optional_param('topic', '', PARAM_TEXT);
    $lesson = optional_param('lesson', '', PARAM_TEXT);
    $tags = optional_param('tags', '', PARAM_RAW);
    $courseid = required_param('courseid', PARAM_INT);

    $valid_levels = ['basic', 'intermediate', 'advanced'];
    if (!in_array($level, $valid_levels)) {
        throw new Exception('Invalid level: ' . $level);
    }

    // ==================== DETERMINE QUESTION COUNT ====================
    
    switch ($level) {
        case 'basic':
            $question_count = 10;
            break;
        case 'intermediate':
            $question_count = 10;
            break;
        case 'advanced':
            $question_count = 5;
            break;
        default:
            $question_count = 5;
    }

    // ==================== LOAD EXISTING MCQ PROMPT ====================
    
    $prompt_file = $CFG->dirroot . '/blocks/ai_assistant/prompts/mcq_instruction.txt';
    
    if (!file_exists($prompt_file)) {
        throw new Exception('MCQ prompt template not found');
    }
    
    $prompt_template = file_get_contents($prompt_file);
    
    // Replace placeholders
    $system_prompt = str_replace(
        ['{QUESTION_COUNT}', '{TARGET_EXAM}', '{SUBJECT}', '{TOPIC}', '{LESSON}', '{LEVEL}', '{AGENT_TEXT}', '{TAGS}'],
        [$question_count, $target, $subject, $topic, $lesson, strtolower($level), $agenttext, $tags],
        $prompt_template
    );

    // ==================== BUILD PAYLOAD (NON-STREAMING) ====================
    
    $payload = [
        'messages' => [
            ['role' => 'system', 'content' => $system_prompt],
            ['role' => 'user', 'content' => "Generate {$question_count} MCQs on: {$agenttext}"]
        ],
        'stream' => false, // ✅ NON-STREAMING for direct response
        'max_tokens' => $question_count * 300,
        'temperature' => 0.4,
        'top_p' => 0.9
    ];

    // ==================== CALL LLM API ====================
    
    error_log('MCQ Widget - Starting non-streaming API call for ' . $question_count . ' questions');
    
    $start_time = microtime(true);
    
    // Call API in non-streaming mode
    $response_text = local_ai_functions_call_endpoint($agentkey, 'mcq', $payload);
    
    $api_duration = round((microtime(true) - $start_time) * 1000);
    
    error_log('MCQ Widget - API call completed in ' . $api_duration . 'ms');
    
    if (!$response_text) {
        throw new Exception('Empty response from LLM API');
    }
    
    // Log response preview
    error_log('MCQ Widget - Response preview: ' . substr($response_text, 0, 200));

    // ==================== PARSE API RESPONSE ====================
    
    $response_data = json_decode($response_text, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        error_log('MCQ Widget - Response is not JSON: ' . json_last_error_msg());
        throw new Exception('API response is not valid JSON: ' . json_last_error_msg());
    }
    
    // ==================== EXTRACT TEXT CONTENT ====================
    
    $full_text = '';
    
    // Azure OpenAI format: choices[0].message.content
    if (isset($response_data['choices'][0]['message']['content'])) {
        $full_text = $response_data['choices'][0]['message']['content'];
        error_log('MCQ Widget - Extracted from choices[0].message.content');
    }
    // Direct text response
    elseif (isset($response_data['content'])) {
        $full_text = $response_data['content'];
        error_log('MCQ Widget - Extracted from content field');
    }
    // Error response
    elseif (isset($response_data['error'])) {
        throw new Exception('API Error: ' . $response_data['error']);
    }
    else {
        error_log('MCQ Widget - Unknown response format: ' . json_encode(array_keys($response_data)));
        throw new Exception('Unknown API response format');
    }
    
    if (empty($full_text)) {
        throw new Exception('No text content in API response');
    }
    
    error_log('MCQ Widget - Extracted text length: ' . strlen($full_text) . ' chars');

    // ==================== PARSE TEXT TO JSON ====================
    
    $mcq_data = parse_mcq_text_to_json($full_text);
    
    if (!$mcq_data || !isset($mcq_data['questions']) || count($mcq_data['questions']) === 0) {
        error_log('MCQ Widget - Parser failed. Text preview: ' . substr($full_text, 0, 500));
        throw new Exception('Failed to parse MCQ text into structured format. Please check logs.');
    }

    $questions_parsed = count($mcq_data['questions']);
    error_log('MCQ Widget - Successfully parsed ' . $questions_parsed . ' questions');

    // ==================== ADD METADATA ====================
    
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

    // ==================== STORE JSON IN DATABASE ====================
    
    $usertext = strtoupper($level) . " MCQ: " . $agenttext;
    $json_response = json_encode($mcq_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    $history_record = new stdClass();
    $history_record->userid = $USER->id;
    $history_record->courseid = $courseid;
    $history_record->usertext = $usertext;
    $history_record->botresponse = $json_response;
    $history_record->functioncalled = 'mcq_widget';
    $history_record->subject = $subject;
    $history_record->topic = $topic;
    $history_record->lesson = $lesson;
    $history_record->timecreated = time();
    $history_record->timemodified = time();
    
    $history_id = $DB->insert_record('block_ai_assistant_history', $history_record);
    
    error_log('MCQ Widget - Stored to database (history_id: ' . $history_id . ')');
    
    // Add history_id to metadata
    $mcq_data['metadata']['history_id'] = $history_id;

    // ==================== RETURN SUCCESS ====================
    
    echo json_encode([
        'status' => 'success',
        'data' => $mcq_data
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    error_log('MCQ Widget Error: ' . $e->getMessage());
    error_log('MCQ Widget Error Location: ' . $e->getFile() . ':' . $e->getLine());
    
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
    $questions = [];
    
    // Remove "**DONE**" marker if present
    $text = str_replace('**DONE**', '', $text);
    $text = trim($text);
    
    // Split by horizontal line separator (---) or question pattern
    $parts = preg_split('/(^|\n)(?=Q\d+\.)/m', $text);
    
    foreach ($parts as $part) {
        $part = trim($part);
        if (empty($part)) continue;
        
        // Extract question number
        if (!preg_match('/^Q(\d+)\.\s*/i', $part, $q_num_match)) {
            continue;
        }
        
        $question_num = (int)$q_num_match[1];
        
        // Remove the Q1. prefix
        $part = preg_replace('/^Q\d+\.\s*/i', '', $part);
        
        // Split by separator (---)
        $sections = preg_split('/\n---+\n/s', $part);
        $question_section = trim($sections[0]);
        
        // Extract question text (everything before options A.)
        if (!preg_match('/^(.+?)(?=\s*\n\s*A\.)/s', $question_section, $q_text_match)) {
            error_log("MCQ Parser - Could not extract question text for Q{$question_num}");
            continue;
        }
        
        $question_text = trim($q_text_match[1]);
        
        // Extract options A. B. C. D.
        $options = [];
        $option_pattern = '/([A-D])\.\s*(.+?)(?=\s*\n\s*[A-D]\.|$|\*\*Answer)/s';
        
        if (preg_match_all($option_pattern, $question_section, $opt_matches, PREG_SET_ORDER)) {
            foreach ($opt_matches as $opt) {
                $option_text = trim($opt[2]);
                // Clean up option text
                $option_text = preg_replace('/\s+/', ' ', $option_text);
                $options[] = $option_text;
            }
        }
        
        // Extract correct answer: **Answer: A**
        $correct = '';
        if (preg_match('/\*\*Answer:\s*([A-D])\*\*/i', $question_section, $ans_match)) {
            $correct = strtoupper(trim($ans_match[1]));
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
            error_log("MCQ Parser - Warning: Q{$question_num} has no explanation");
        }
        
        if ($is_valid) {
            $questions[] = [
                'question' => $question_text,
                'options' => array_slice($options, 0, 4),
                'correct' => $correct,
                'explanation' => $explanation
            ];
            
            error_log("MCQ Parser - Successfully parsed Q{$question_num}");
        } else {
            error_log("MCQ Parser - Skipping invalid Q{$question_num}: " . implode(', ', $errors));
        }
    }
    
    return ['questions' => $questions];
}
