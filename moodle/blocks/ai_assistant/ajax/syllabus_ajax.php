<?php
/**
 * AJAX endpoint to return syllabus JSON data
 *
 * @package    block_ai_assistant
 */

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../config.php');
global $DB, $CFG;

require_login();
require_sesskey();

header('Content-Type: application/json');

try {
    // 1) Prefer explicit mainsubject from the AJAX caller
    $mainsubject = optional_param('mainsubject', '', PARAM_ALPHANUMEXT);

    // 2) If not provided, allow block instance lookup (per-block config)
    if (empty($mainsubject)) {
        $blockid = optional_param('blockid', 0, PARAM_INT);
        if ($blockid) {
            $bi = $DB->get_record('block_instances', ['id' => $blockid], '*', IGNORE_MISSING);
            if ($bi && !empty($bi->configdata)) {
                $decoded = base64_decode($bi->configdata);
                if ($decoded !== false) {
                    $config = @unserialize($decoded);
                    if (!empty($config->mainsubjectkey)) {
                        $mainsubject = clean_param($config->mainsubjectkey, PARAM_ALPHANUMEXT);
                    }
                }
            }
        }
    }

    // 3) If still empty, fall back to site-level admin setting, then to default
    if (empty($mainsubject)) {
        $mainsubject = get_config('block_ai_assistant', 'mainsubjectkey') ?: 'CSIRCHEM100';
        $mainsubject = clean_param($mainsubject, PARAM_ALPHANUMEXT);
    }

    // Build filesystem path (use dirroot, not wwwroot)
    $syllabusPath = $CFG->dirroot . '/blocks/ai_assistant/syllabus/' . $mainsubject . '.json';

    // Optional debug log
    error_log('get_syllabus_ajax: mainsubject=' . $mainsubject . ' path=' . $syllabusPath);

    if (!file_exists($syllabusPath)) {
        // Fallback to a generic file if you want
        $fallback = $CFG->dirroot . '/blocks/ai_assistant/syllabus/chemistry.json';
        if (file_exists($fallback)) {
            $syllabusPath = $fallback;
        } else {
            throw new Exception('Syllabus file not found at: ' . $syllabusPath);
        }
    }

    $jsonContent = file_get_contents($syllabusPath);
    if ($jsonContent === false) {
        throw new Exception('Failed to read syllabus file: ' . $syllabusPath);
    }

    // Validate JSON
    json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in syllabus file: ' . json_last_error_msg());
    }

    echo $jsonContent;
} catch (Exception $e) {
    debugging('Syllabus AJAX Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}