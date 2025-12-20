<?php
define('AJAX_SCRIPT', true);

require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

header('Content-Type: application/json; charset=utf-8');

try {
    $blockid = required_param('blockid', PARAM_INT);

    // Resolve the block context (per-block-instance storage).
    $context = context_block::instance($blockid);

    $fs = get_file_storage();

    // One file per block instance: component=block_ai_assistant, filearea=syllabus, itemid=0.
    $files = $fs->get_area_files(
        $context->id,
        'block_ai_assistant',
        'syllabus',
        0,
        'timemodified DESC',
        false
    );

    if (empty($files)) {
        throw new moodle_exception('nofile', 'error', '', null, 'No syllabus JSON uploaded for this course block.');
    }

    /** @var stored_file $file */
    $file = reset($files);
    $jsoncontent = $file->get_content();

    if ($jsoncontent === false || $jsoncontent === '') {
        throw new moodle_exception('invalidfile', 'error', '', null, 'Syllabus file is empty or unreadable.');
    }

    // Validate JSON before returning it (same safety principle you already use).
    json_decode($jsoncontent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new moodle_exception('invalidjson', 'error', '', null, 'Invalid JSON: ' . json_last_error_msg());
    }

    echo $jsoncontent;

} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'error' => true,
        'message' => $e->getMessage(),
    ]);
}
