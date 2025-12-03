<?php
/**
 * AJAX endpoint to return syllabus JSON data
 * 
 * @package    block_ai_assistant
 * @copyright  2024 Your Name
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../config.php');

// Require login
require_login();

// Set JSON header BEFORE any output
header('Content-Type: application/json');

// Disable error display (errors would break JSON)
ini_set('display_errors', '0');
error_reporting(0);

try {
    // Get parameters
    $mainsubject = optional_param('mainsubject', '', PARAM_ALPHANUMEXT);
    $sesskey = optional_param('sesskey', '', PARAM_RAW);
    
    // Validate session key
    if ($sesskey !== sesskey()) {
        throw new Exception('Invalid session key');
    }
    
    // Path to syllabus JSON file
    // Try CSIRCHEM.json first (your new file)
    $syllabusPath = __DIR__ . '/../syllabus/CSIRCHEM.json';
    
    // Fallback to chemistry.json if CSIRCHEM doesn't exist
    if (!file_exists($syllabusPath)) {
        $syllabusPath = __DIR__ . '/../syllabus/chemistry.json';
    }
    
    // Check if file exists
    if (!file_exists($syllabusPath)) {
        throw new Exception('Syllabus file not found at: ' . $syllabusPath);
    }
    
    // Read file
    $jsonContent = file_get_contents($syllabusPath);
    
    if ($jsonContent === false) {
        throw new Exception('Failed to read syllabus file');
    }
    
    // Decode to validate JSON
    $syllabusData = json_decode($jsonContent, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON in syllabus file: ' . json_last_error_msg());
    }
    
    // Return the raw JSON (already validated)
    echo $jsonContent;
    
} catch (Exception $e) {
    // Log error
    debugging('Syllabus AJAX Error: ' . $e->getMessage(), DEBUG_DEVELOPER);
    
    // Return error as JSON
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage()
    ]);
}
