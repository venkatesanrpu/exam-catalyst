<?php
// FILE: moodle/blocks/ai_assistant/history.php
// MODIFIED: Changed filter hierarchy from Subject->Lesson->Topic to Subject->Topic->Lesson

require_once('../../config.php');

global $DB, $PAGE, $USER, $OUTPUT;

// --- Get Parameters ---
$courseid = required_param('courseid', PARAM_INT);
$subjectfilter = optional_param('subject', '', PARAM_ALPHANUMEXT);
$topicfilter = optional_param('topic', '', PARAM_ALPHANUMEXT);
$lessonfilter = optional_param('lesson', '', PARAM_ALPHANUMEXT);
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
$urlparams = ['courseid' => $courseid];
if ($subjectfilter) { $urlparams['subject'] = $subjectfilter; }
if ($topicfilter) { $urlparams['topic'] = $topicfilter; }
if ($lessonfilter) { $urlparams['lesson'] = $lessonfilter; }
if ($embed) { $urlparams['embed'] = 1; }
$PAGE->set_url($baseurl, $urlparams);
$PAGE->set_title(get_string('history_page_title', 'block_ai_assistant'));
$PAGE->set_heading($course->fullname);
$PAGE->set_context($context);
$PAGE->requires->css('/blocks/ai_assistant/styles.css');

if ($embed) {
    $PAGE->set_pagelayout('embedded');
}

// --- Get renderer instance ---
$renderer = $PAGE->get_renderer('block_ai_assistant', 'page');

// --- Data Retrieval & Filtering ---
$base_sql = "FROM {block_ai_assistant_history} WHERE userid = :userid AND courseid = :courseid";
$params = ['userid' => $USER->id, 'courseid' => $courseid];

if (!empty($subjectfilter)) {
    $base_sql .= " AND subject = :subject";
    $params['subject'] = $subjectfilter;
}
if (!empty($topicfilter)) {
    $base_sql .= " AND topic = :topic";
    $params['topic'] = $topicfilter;
}
if (!empty($lessonfilter)) {
    $base_sql .= " AND lesson = :lesson";
    $params['lesson'] = $lessonfilter;
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

// --- Data Structuring for the Template (NEW HIERARCHY: Subject -> Topic -> Lesson) ---
$structured_history = [];
$dateformat = get_string('strftimedatetimeshort', 'block_ai_assistant');

foreach ($historyrecords as $record) {
    $record->formattedtime = userdate($record->timecreated, $dateformat);
    $subject = empty($record->subject) ? get_string('uncategorized', 'block_ai_assistant') : $record->subject;
    $topic = empty($record->topic) ? get_string('general_topic', 'block_ai_assistant') : $record->topic;
    $lesson = empty($record->lesson) ? get_string('general_lesson', 'block_ai_assistant') : $record->lesson;
    
    $subjectkey = md5($subject);
    $topickey = md5($topic);
    $lessonkey = md5($lesson);
    
    // Initialize subject level
    if (!isset($structured_history[$subjectkey])) {
        $structured_history[$subjectkey] = ['name' => $subject, 'topics' => []];
    }
    
    // Initialize topic level
    if (!isset($structured_history[$subjectkey]['topics'][$topickey])) {
        $structured_history[$subjectkey]['topics'][$topickey] = [
            'name' => $topic,
            'uniqid' => uniqid(),
            'lessons' => []
        ];
    }
    
    // Initialize lesson level
    if (!isset($structured_history[$subjectkey]['topics'][$topickey]['lessons'][$lessonkey])) {
        $structured_history[$subjectkey]['topics'][$topickey]['lessons'][$lessonkey] = [
            'name' => $lesson,
            'uniqid' => uniqid(),
            'conversations' => []
        ];
    }
    
    // Add conversation to the lesson
    $structured_history[$subjectkey]['topics'][$topickey]['lessons'][$lessonkey]['conversations'][] = $record;
}

// Convert to indexed arrays for Mustache
$data_subjects = [];
foreach ($structured_history as $subject) {
    $topics_array = [];
    foreach ($subject['topics'] as $topic) {
        $topic['lessons'] = array_values($topic['lessons']);
        $topics_array[] = $topic;
    }
    $subject['topics'] = $topics_array;
    $data_subjects[] = $subject;
}

// --- Load Syllabus for Filter Dropdowns ---
$syllabus_path = __DIR__ . '/syllabus/chemistry.json';
$syllabus_data = file_exists($syllabus_path) ? json_decode(file_get_contents($syllabus_path)) : [];

// --- Prepare Pagination Data Structure ---
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
    'topic'   => $topicfilter,
    'lesson'  => $lessonfilter
];

$data = [
    'subjects'     => $data_subjects,
    'has_history'  => !empty($historyrecords),
    'filters'      => $filters_data,
    'pagination'   => $pagination_data,
    'courseid'     => $courseid,
    'syllabusjson' => json_encode($syllabus_data),
    'filtersjson'  => json_encode($filters_data),
    'historyurl'   => (new moodle_url('/blocks/ai_assistant/history.php', 
                          ['courseid' => $courseid] + ($embed ? ['embed' => 1] : [])))->out(false)
];

// --- Render the Page ---
echo $OUTPUT->header();
echo $renderer->render_from_template('block_ai_assistant/history', $data);
echo $OUTPUT->footer();
