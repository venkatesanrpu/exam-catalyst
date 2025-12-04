<?php
/**
 * AJAX endpoint for History Widget
 * Fetches conversation history for a specific lesson OR general (unfiltered) conversations
 * 
 * @package    block_ai_assistant
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

global $DB, $USER;

// Require login
require_login();

// Set JSON header
header('Content-Type: application/json');

try {
    // Get raw POST data
    $rawdata = file_get_contents('php://input');
    $data = json_decode($rawdata, true);
    
    // Validate JSON
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON: ' . json_last_error_msg());
    }
    
    // Extract parameters
    $sesskey = $data['sesskey'] ?? '';
    $courseid = $data['courseid'] ?? 0;
    $subject = $data['subject'] ?? '';
    $topic = $data['topic'] ?? '';
    $lesson = $data['lesson'] ?? '';
    $page = $data['page'] ?? 1;
    $perpage = $data['perpage'] ?? 20;
    
    // ✅ Check if this is a general (unfiltered) request
    $isGeneral = isset($data['general']) && $data['general'] === true;
    
    // Validate session key
    if ($sesskey !== sesskey()) {
        throw new Exception('Invalid session key');
    }
    
    // Validate required parameters
    if (!$courseid) {
        throw new Exception('Course ID is required');
    }
    
    // Require lesson only if NOT general category
    if (!$isGeneral && empty($lesson)) {
        throw new Exception('Lesson is required - please select a lesson to view history');
    }
    
    // Verify course access
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($courseid);
    
    // Check if user is enrolled
    if (!is_enrolled($context, $USER)) {
        throw new Exception('You are not enrolled in this course');
    }
    
    // Build SQL query
    $sql = "SELECT id, usertext, botresponse, timecreated, functioncalled, subject, topic, lesson
            FROM {block_ai_assistant_history}
            WHERE userid = :userid 
              AND courseid = :courseid";
    
    $params = [
        'userid' => $USER->id,
        'courseid' => $courseid
    ];
    
    // ✅ HANDLE GENERAL CATEGORY - Show conversations with empty/null subject
    if ($isGeneral) {
        // Find conversations with no subject filtering (direct chat queries)
        $sql .= " AND (subject IS NULL OR subject = '' OR subject = 'general')";
    } else {
        // Add specific filters for syllabus-based queries
        if (!empty($subject)) {
            $sql .= " AND subject = :subject";
            $params['subject'] = $subject;
        }
        
        if (!empty($topic)) {
            $sql .= " AND topic = :topic";
            $params['topic'] = $topic;
        }
        
        if (!empty($lesson)) {
            $sql .= " AND lesson = :lesson";
            $params['lesson'] = $lesson;
        }
    }
    
    // Order by newest first
    $sql .= " ORDER BY timecreated DESC";
    
    // Calculate offset
    $page = max(1, intval($page));
    $perpage = max(1, min(50, intval($perpage))); // Max 50 per page
    $offset = ($page - 1) * $perpage;
    
    // ==================== GET TOTAL COUNT ====================
    $countsql = "SELECT COUNT(id) 
                 FROM {block_ai_assistant_history}
                 WHERE userid = :userid 
                   AND courseid = :courseid";
    
    // ✅ Apply same filtering logic for count
    if ($isGeneral) {
        $countsql .= " AND (subject IS NULL OR subject = '' OR subject = 'general')";
    } else {
        if (!empty($subject)) {
            $countsql .= " AND subject = :subject";
        }
        if (!empty($topic)) {
            $countsql .= " AND topic = :topic";
        }
        if (!empty($lesson)) {
            $countsql .= " AND lesson = :lesson";
        }
    }
    
    $totalcount = $DB->count_records_sql($countsql, $params);
    
    // ==================== GET RECORDS ====================
    $records = $DB->get_records_sql($sql, $params, $offset, $perpage);
    
    // ==================== FORMAT CONVERSATIONS ====================
    $conversations = [];
    
    foreach ($records as $record) {
        // Format timestamp
        $formattedtime = userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig'));
        
        // Clean bot response
        $botresponse = $record->botresponse;
        
        // Remove <think> tags if present
        $botresponse = preg_replace('/<think>[\s\S]*?<\/think>/i', '', $botresponse);
        $botresponse = trim($botresponse);
        
        // Get subject/topic/lesson display names (they might be keys)
        $subjectDisplay = $record->subject;
        $topicDisplay = $record->topic;
        $lessonDisplay = $record->lesson;
        
        // Convert keys to readable names if needed (optional enhancement)
        // You could load syllabus and do key-to-name mapping here
        
        $conversations[] = [
            'id' => $record->id,
            'usertext' => $record->usertext,
            'botresponse' => $botresponse,
            'formattedtime' => $formattedtime,
            'timecreated' => $record->timecreated,
            'functioncalled' => $record->functioncalled,
            'subject' => $subjectDisplay,
            'topic' => $topicDisplay,
            'lesson' => $lessonDisplay
        ];
    }
    
    // ==================== CALCULATE PAGINATION ====================
    $totalpages = ceil($totalcount / $perpage);
    
    // ==================== RETURN SUCCESS RESPONSE ====================
    echo json_encode([
        'status' => 'success',
        'conversations' => $conversations,
        'pagination' => [
            'currentpage' => $page,
            'perpage' => $perpage,
            'totalcount' => $totalcount,
            'totalpages' => $totalpages,
            'hasnext' => $page < $totalpages,
            'hasprevious' => $page > 1
        ],
        'debug' => [
            'isGeneral' => $isGeneral,
            'filters' => [
                'subject' => $subject,
                'topic' => $topic,
                'lesson' => $lesson
            ]
        ]
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    // Log error
    debugging('History Widget AJAX Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    
    // Return error response
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
