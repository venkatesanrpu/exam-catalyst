<?php
// FILE: moodle/blocks/ai_assistant/ajax.php
// UPDATE: Changed to write to the 'lesson' database column instead of 'subject'.

define('AJAX_SCRIPT', true);
require_once('../../config.php');

global $DB, $USER;

$action   = required_param('action', PARAM_ALPHA);
$courseid = required_param('courseid', PARAM_INT);
$sesskey  = required_param('sesskey', PARAM_ALPHANUM);

require_login($courseid);
require_sesskey($sesskey);
header('Content-Type: application/json');

if ($action === 'create') {
    $conversation_json = required_param('conversation', PARAM_RAW);
    $conversation = json_decode($conversation_json);

    $record = new stdClass();
    $record->userid = $USER->id;
    $record->courseid = $courseid;
    $record->functioncalled = $conversation->functioncalled ?? 'unknown';
    $record->usertext = $conversation->usertext;
    $record->botresponse = '';

    // --- THIS IS THE FIX ---
    $record->lesson = $conversation->lesson ?? ''; // Changed from 'subject'
    // --- END OF FIX ---

    $record->topic = $conversation->topic ?? '';
    $record->timecreated = time();

    try {
        $historyid = $DB->insert_record('block_ai_assistant_history', $record);
        echo json_encode(['status' => 'success', 'historyid' => $historyid]);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }

} else if ($action === 'update') {
    // No changes needed in the update action
    $historyid = required_param('historyid', PARAM_INT);
    $botresponse = required_param('botresponse', PARAM_RAW);

    try {
        $DB->set_field('block_ai_assistant_history', 'botresponse', $botresponse, ['id' => $historyid, 'userid' => $USER->id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()]);
    }
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
}