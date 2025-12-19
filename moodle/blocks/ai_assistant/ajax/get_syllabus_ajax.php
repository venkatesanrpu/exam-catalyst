<?php
// FILE: moodle/blocks/ai_assistant/get_syllabus_ajax.php
// UPDATE: Now requires a 'mainsubject' parameter to return the correct syllabus file.

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

require_login();
require_sesskey();

// --- NEW LOGIC START ---
    $mainsubject = get_config('block_ai_assistant', 'mainsubjectkey') ?: 'chemistry';
    $mainsubject = clean_param($mainsubject, PARAM_ALPHANUMEXT);
// --- NEW LOGIC END ---

header('Content-Type: application/json');

// --- NEW LOGIC START ---
// Dynamically construct the path based on the mainsubject parameter
$syllabus_path = __DIR__ . '../syllabus/' . $mainsubject . '.json';
// --- NEW LOGIC END ---

if (!file_exists($syllabus_path)) {
    http_response_code(404);
    echo json_encode(['error' => "Syllabus file for '{$mainsubject}' not found."]);
    exit;
}

$syllabus_content = file_get_contents($syllabus_path);
echo $syllabus_content;