<?php
// DEPRECATED: kept for backward compatibility.
// Prefer: /blocks/ai_assistant/ajax/syllabus_ajax.php?blockid=...&sesskey=...

define('AJAX_SCRIPT', true);
require_once(__DIR__ . '/../../../../config.php');

require_login();
require_sesskey();

require(__DIR__ . '/syllabus_ajax.php'); // Reuse the new implementation.
