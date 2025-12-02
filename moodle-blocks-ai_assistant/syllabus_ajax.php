<?php
/**
 * Syllabus JSON Loader
 * FILE: blocks/ai_assistant/syllabus_ajax.php
 */

define('AJAX_SCRIPT', true);
require_once('../../config.php');

require_login();

header('Content-Type: application/json');

try {
    $mainsubject = optional_param('mainsubject', '', PARAM_ALPHANUMEXT);
    $sesskey = optional_param('sesskey', '', PARAM_ALPHANUM);
    
    // Verify sesskey
    if (!confirm_sesskey($sesskey)) {
        throw new Exception('Invalid session key');
    }
    
    if (empty($mainsubject)) {
        throw new Exception('Subject key required');
    }
    
    // Sanitize filename
    $mainsubject = clean_param($mainsubject, PARAM_ALPHANUMEXT);
    
    // Build path to JSON file
    $jsonPath = __DIR__ . '/syllabus/' . $mainsubject . '.json';
    
    // Security check - prevent directory traversal
    $realPath = realpath($jsonPath);
    $baseDir = realpath(__DIR__ . '/syllabus');
    
    if ($realPath === false || strpos($realPath, $baseDir) !== 0) {
        throw new Exception('Invalid syllabus path');
    }
    
    // Check if file exists
    if (!file_exists($jsonPath)) {
        throw new Exception("Syllabus file not found: {$mainsubject}.json");
    }
    
    // Read and parse JSON
    $jsonContent = file_get_contents($jsonPath);
    
    if ($jsonContent === false) {
        throw new Exception('Failed to read syllabus file');
    }
    
    $syllabusData = json_decode($jsonContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in syllabus file: ' . json_last_error_msg());
    }
    
    if (!is_array($syllabusData)) {
        throw new Exception('Syllabus data must be an array');
    }
    
    // Return the syllabus data
    echo json_encode($syllabusData);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ]);
}
