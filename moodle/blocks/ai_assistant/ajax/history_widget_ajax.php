<?php
// AJAX endpoint for History Widget.
// Fetches conversation history for subject/topic/lesson OR general unfiltered conversations.
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

global $DB, $USER;

// Always return JSON.
header('Content-Type: application/json; charset=utf-8');

try {
    // ---- Read and decode JSON body ----
    $rawdata = file_get_contents('php://input');
    if ($rawdata === false) {
        throw new Exception('Failed to read request body');
    }

    try {
        $data = json_decode($rawdata, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new Exception('Invalid JSON: ' . $e->getMessage());
    }

    if (!is_array($data)) {
        throw new Exception('Invalid request payload');
    }

    // ---- Extract & validate parameters ----
    $sesskey = clean_param($data['sesskey'] ?? '', PARAM_RAW_TRIMMED);
    $courseid = (int)($data['courseid'] ?? 0);

    // subject/topic are typically keys; allow underscores and hyphens.
    $subject = clean_param($data['subject'] ?? '', PARAM_ALPHANUMEXT);
    $topic   = clean_param($data['topic'] ?? '', PARAM_ALPHANUMEXT);

    // lesson may be a display string; allow normal text.
    $lesson  = clean_param($data['lesson'] ?? '', PARAM_TEXT);

    $page    = (int)($data['page'] ?? 1);
    $perpage = (int)($data['perpage'] ?? 20);

    $isgeneral = !empty($data['general']);

    if (empty($courseid)) {
        throw new Exception('Course ID is required');
    }

    // Course-aware login check.
    require_login($courseid);

    // JSON body contains sesskey, so validate it explicitly.
    if (empty($sesskey) || !confirm_sesskey($sesskey)) {
        throw new Exception('Invalid session key');
    }

    // Subject is required only for non-general requests.
    if (!$isgeneral && empty($subject)) {
        throw new Exception('Subject is required - please select a subject to view history');
    }

    // Verify course exists and access context.
    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $context = context_course::instance($courseid);

    // Optional extra guard: only enrolled users can view history.
    // (Keep if this matches your intended access model.)
    if (!is_enrolled($context, $USER, '', true)) {
        throw new Exception('You are not enrolled in this course');
    }

    // Pagination bounds.
    $page = max(1, $page);
    $perpage = max(1, min(50, $perpage)); // Max 50 per page.
    $offset = ($page - 1) * $perpage;

    // ---- Build SQL + params (filters are optional) ----
    $params = [
        'userid' => $USER->id,
        'courseid' => $courseid,
    ];

    $basesql = "FROM {block_ai_assistant_history}
                WHERE userid = :userid
                  AND courseid = :courseid";

    if ($isgeneral) {
        // General = direct-chat/uncategorized history.
        $basesql .= " AND (subject IS NULL OR subject = '' OR subject = :generalsubject)";
        $params['generalsubject'] = 'general';
    } else {
        // Non-general: filter by whatever is provided.
        // Subject is required here, topic/lesson optional (empty = no filter).
        $basesql .= " AND subject = :subject";
        $params['subject'] = $subject;

        if (!empty($topic)) {
            $basesql .= " AND topic = :topic";
            $params['topic'] = $topic;
        }

        if (!empty($lesson)) {
            $basesql .= " AND lesson = :lesson";
            $params['lesson'] = $lesson;
        }
    }

    $sql = "SELECT id, usertext, botresponse, timecreated, functioncalled, subject, topic, lesson
            {$basesql}
            ORDER BY timecreated DESC";

    $countsql = "SELECT COUNT(1)
                 {$basesql}";

    // ---- Query ----
    $totalcount = (int)$DB->count_records_sql($countsql, $params);
    $records = $DB->get_records_sql($sql, $params, $offset, $perpage);

    // ---- Format results ----
    $conversations = [];
    foreach ($records as $record) {
        $formattedtime = userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig'));

        $botresponse = (string)$record->botresponse;

        // Remove <think>...</think> blocks if present (LLM internal traces).
        $botresponse = preg_replace('/<think\b[^>]*>.*?<\/think>/is', '', $botresponse);
        $botresponse = trim($botresponse);

        $conversations[] = [
            'id' => (int)$record->id,
            'usertext' => (string)$record->usertext,
            'botresponse' => $botresponse,
            'formattedtime' => $formattedtime,
            'timecreated' => (int)$record->timecreated,
            'functioncalled' => (string)$record->functioncalled,
            'subject' => (string)$record->subject,
            'topic' => (string)$record->topic,
            'lesson' => (string)$record->lesson,
        ];
    }

    $totalpages = (int)ceil($totalcount / $perpage);

    echo json_encode([
        'status' => 'success',
        'conversations' => $conversations,
        'pagination' => [
            'currentpage' => $page,
            'perpage' => $perpage,
            'totalcount' => $totalcount,
            'totalpages' => $totalpages,
            'hasnext' => $page < $totalpages,
            'hasprevious' => $page > 1,
        ],
        // Remove this debug block in production if not needed.
        'debug' => [
            'isGeneral' => $isgeneral,
            'filters' => [
                'subject' => $subject,
                'topic' => $topic,
                'lesson' => $lesson,
            ],
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    debugging('History Widget AJAX Error: ' . $e->getMessage(), DEBUG_DEVELOPER);

    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
