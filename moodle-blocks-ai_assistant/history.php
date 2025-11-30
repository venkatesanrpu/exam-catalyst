<?php
// FILE: moodle/blocks/ai_assistant/history.php
// FINAL FIX: Correctly preserves the 'subjectfilter' parameter in all generated URLs,
// preventing the "Undefined variable" warning on subsequent page loads.

require_once('../../config.php');

global $DB, $PAGE, $USER, $OUTPUT;

// --- Get Parameters ---
$courseid = required_param('courseid', PARAM_INT);
$mainsubject = required_param('mainsubject', PARAM_ALPHANUMEXT);
$subjectfilter = optional_param('subject', '', PARAM_ALPHANUMEXT); // This is the UI dropdown value
$lessonfilter = optional_param('lesson', '', PARAM_TEXT);
$topicfilter = optional_param('topic', '', PARAM_TEXT);
$page = optional_param('page', 1, PARAM_INT);
$embed = optional_param('embed', 0, PARAM_BOOL);

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);

// --- Security Checks ---
require_login($course);
$context = context_course::instance($courseid);
if (!is_enrolled($context, $USER)) {
    throw new require_enrolment_exception('You are not enrolled in this course.');
}

// --- Page Setup ---
$baseurl = new moodle_url('/blocks/ai_assistant/history.php');
$urlparams = ['courseid' => $courseid, 'mainsubject' => $mainsubject];
// --- THIS IS THE FIX ---
if ($subjectfilter) { $urlparams['subject'] = $subjectfilter; } // Preserve the UI subject filter
// --- END OF FIX ---
if ($lessonfilter) { $urlparams['lesson'] = $lessonfilter; }
if ($topicfilter) { $urlparams['topic'] = $topicfilter; }
if ($embed) { $urlparams['embed'] = 1; }
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('history_page_title', 'block_ai_assistant'));
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);
$PAGE->requires->css('/blocks/ai_assistant/styles.css');

if ($embed) {
    $PAGE->set_pagayout('block_ai_assistant', 'embedded');
}
$renderer = $PAGE->get_renderer('block_ai_assistant', 'page');

// --- Data Retrieval & Filtering ---
$base_sql = "FROM {block_ai_assistant_history} WHERE userid = :userid AND courseid = :courseid";
$params = ['userid' => $USER->id, 'courseid' => $courseid];
if (!empty($lessonfilter)) {
    $base_sql .= " AND lesson = :lesson";
    $params['lesson'] = $lessonfilter;
}
if (!empty($topicfilter)) {
    $base_sql .= " AND topic = :topic";
    $params['topic'] = $topicfilter;
}
$perpage = 10;
$totalcount = $DB->count_records_sql("SELECT COUNT(id) $base_sql", $params);
$totalpages = ($totalcount > 0) ? ceil($totalcount / $perpage) : 1;
$page = max(1, min($page, $totalpages));
$offset = ($page - 1) * $perpage;
$historyrecords = $DB->get_records_sql(
    "SELECT * $base_sql ORDER BY timecreated DESC",
    $params,
    $offset,
    $perpage
);

// --- Data Structuring for the Template (No changes) ---
$structured_history = [];
$dateformat = get_string('strftimedatetimeshort', 'block_ai_assistant');
foreach ($historyrecords as $record) {
    $record->formattedtime = userdate($record->timecreated, $dateformat);
    $lesson = empty($record->lesson) ? get_string('uncategorized', 'block_ai_assistant') : $record->lesson;
    $topic = empty($record->topic) ? get_string('general_inquiry', 'block_ai_assistant') : $record->topic;
    $lessonkey = md5($lesson);
    $topickey = md5($topic);
    if (!isset($structured_history[$lessonkey])) {
        $structured_history[$lessonkey] = ['name' => $lesson, 'topics' => []];
    }
    if (!isset($structured_history[$lessonkey]['topics'][$topickey])) {
        $structured_history[$lessonkey]['topics'][$topickey] = [
            'name' => $topic,
            'uniqid' => uniqid(),
            'conversations' => []
        ];
    }
    $structured_history[$lessonkey]['topics'][$topickey]['conversations'][] = $record;
}
$data_lessons = [];
foreach ($structured_history as $lesson) {
    $lesson['topics'] = array_values($lesson['topics']);
    $data_lessons[] = $lesson;
}

// --- Load and Enhance Syllabus for Filter Dropdowns (No changes) ---
$syllabus_path = __DIR__ . '/syllabus/' . $mainsubject . '.json';
$syllabus_data = file_exists($syllabus_path) ? json_decode(file_get_contents($syllabus_path), true) : [];
$general_inquiry_string = get_string('general_inquiry', 'block_ai_assistant');
$general_subject = [
    'subject' => get_string('general_course_questions', 'block_ai_assistant'),
    'subject_key' => '__GENERAL__',
    'lessons' => [
        [
            'lesson' => $course->fullname,
            'lesson_key' => $course->fullname,
            'topics' => [
                [
                    'topic' => $general_inquiry_string,
                    'topic_key' => $general_inquiry_string
                ]
            ]
        ]
    ]
];
array_unshift($syllabus_data, $general_subject);

// --- Prepare Pagination Data Structure (No changes) ---
$pagination_data = [
    'haspages'        => $totalpages > 1,
    'hasprevious'     => $page > 1,
    'hasnext'         => $page < $totalpages,
    'previouspageurl' => (new moodle_url($baseurl, $urlparams + ['page' => $page - 1]))->out(false),
    'nextpageurl'     => (new moodle_url($baseurl, $urlparams + ['page' => $page + 1]))->out(false),
    'pages'           => []
];
if ($pagination_data['haspages']) {
    for ($i = 1; $i <= $totalpages; $i++) {
        $page_obj = new stdClass();
        $page_obj->number = $i;
        $page_obj->isactive = ($i == $page);
        $page_obj->url = (new moodle_url($baseurl, $urlparams + ['page' => $i]))->out(false);
        $pagination_data['pages'][] = $page_obj;
    }
}

// --- Prepare All Data for the Template ---
$filters_data = [
    'subject' => $subjectfilter,
    'lesson'  => $lessonfilter,
    'topic'   => $topicfilter
];
$data = [
    'lessons'      => $data_lessons,
    'has_history'  => !empty($historyrecords),
    'filters'      => $filters_data,
    'pagination'   => $pagination_data,
    'courseid'     => $courseid,
    'mainsubject'  => $mainsubject,
    'syllabusjson' => json_encode($syllabus_data),
    'filtersjson'  => json_encode($filters_data),
    'historyurl'   => (new moodle_url('/blocks/ai_assistant/history.php', ['courseid' => $courseid, 'mainsubject' => $mainsubject] + ($embed ? ['embed' => 1] : [])))->out(false)
];

// --- Render the Page ---
echo $OUTPUT->header();
echo $renderer->render_from_template('block_ai_assistant/history', $data);
echo $OUTPUT->footer();