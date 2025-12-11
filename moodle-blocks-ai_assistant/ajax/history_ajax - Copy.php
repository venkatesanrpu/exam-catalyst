<?php
/**
 * Chat History Handler with Database Storage
 * FILE: blocks/ai_assistant/history_ajax.php
 * Saves conversations to mdl_block_ai_assistant_history for student review
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');

global $DB, $USER;

require_login();

header('Content-Type: application/json');

try {
    // Get POST data
    $rawdata = file_get_contents('php://input');
    $data = json_decode($rawdata, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON payload');
    }
    
    // Validate sesskey from JSON body
    if (!isset($data['sesskey']) || $data['sesskey'] !== sesskey()) {
        throw new Exception('Invalid session key');
    }
    
    $action = $data['action'] ?? '';
    $courseid = $data['courseid'] ?? 0;
    
    if (!$courseid) {
        throw new Exception('Course ID required');
    }
    
    // Verify course access
    $context = context_course::instance($courseid);
    require_capability('moodle/course:view', $context);
    
    // Handle actions with DATABASE STORAGE
    switch ($action) {
        case 'create':
            $history = $data['history'] ?? null;
            
            if (!$history) {
                throw new Exception('History data required');
            }
            
            // Build database record
            $record = new stdClass();
            $record->courseid = $courseid;
            $record->userid = $USER->id;
            $record->usertext = $history['usertext'] ?? '';
            $record->botresponse = ''; // Initially empty, filled by update
            $record->functioncalled = $history['functioncalled'] ?? 'ask_agent';
            $record->subject = $history['subject'] ?? '';
            $record->topic = $history['topic'] ?? '';
            $record->lesson = $history['lesson'] ?? '';
            $record->metadata = null; // Will be filled during update
            $record->timecreated = time();
            $record->timemodified = time();
            
            // Insert into database
            $historyid = $DB->insert_record('block_ai_assistant_history', $record);
            
            if (!$historyid) {
                throw new Exception('Failed to insert history record');
            }
            
            echo json_encode([
                'status' => 'success',
                'historyid' => $historyid,
                'message' => 'History created'
            ]);
            break;
            
        case 'update':
            $historyid = $data['historyid'] ?? 0;
            $botresponse = $data['botresponse'] ?? '';
            $metadata = $data['metadata'] ?? null;
            
            if (!$historyid) {
                throw new Exception('History ID required');
            }
            
            // Get existing record to verify ownership
            $record = $DB->get_record('block_ai_assistant_history', [
                'id' => $historyid,
                'userid' => $USER->id
            ]);
            
            if (!$record) {
                throw new Exception('History record not found or access denied');
            }
            
            // Update record
            $record->botresponse = $botresponse;
            $record->timemodified = time();
            
            // Store metadata as JSON if provided
            if ($metadata) {
                $record->metadata = json_encode($metadata);
            }
            
            $success = $DB->update_record('block_ai_assistant_history', $record);
            
            if (!$success) {
                throw new Exception('Failed to update history record');
            }
            
            echo json_encode([
                'status' => 'success',
                'message' => 'History updated'
            ]);
            break;
            
        case 'list':
            // Retrieve history for the current user in this course
            $records = $DB->get_records('block_ai_assistant_history', 
                [
                    'courseid' => $courseid,
                    'userid' => $USER->id
                ],
                'timecreated DESC',
                '*',
                0,
                50 // Limit to last 50 conversations
            );
            
            $history = [];
            foreach ($records as $record) {
                $history[] = [
                    'id' => $record->id,
                    'usertext' => $record->usertext,
                    'botresponse' => $record->botresponse,
                    'functioncalled' => $record->functioncalled,
                    'subject' => $record->subject,
                    'topic' => $record->topic,
                    'lesson' => $record->lesson,
                    'metadata' => $record->metadata ? json_decode($record->metadata, true) : null,
                    'timecreated' => $record->timecreated,
                    'timemodified' => $record->timemodified
                ];
            }
            
            echo json_encode([
                'status' => 'success',
                'history' => $history,
                'count' => count($history)
            ]);
            break;
            
        default:
            throw new Exception('Invalid action: ' . $action);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
