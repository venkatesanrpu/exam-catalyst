<?php
// FILE: moodle/local/ai_functions/edit.php
require_once('../../config.php');
require_once($CFG->dirroot . '/local/ai_functions/edit_form.php');

require_login();
require_capability('moodle/site:config', context_system::instance());

$id = optional_param('id', 0, PARAM_INT);
$action = optional_param('action', '', PARAM_ALPHA);

$PAGE->set_url(new moodle_url('/local/ai_functions/edit.php', ['id' => $id]));
$PAGE->set_context(context_system::instance());
$PAGE->set_title(get_string('edit_agent_title', 'local_ai_functions'));

$redirect_url = new moodle_url('/local/ai_functions/index.php');

// Handle delete action
if ($action === 'delete' && $id) {
    require_sesskey();
    $DB->delete_records('local_ai_functions_agents', ['id' => $id]);
    redirect($redirect_url);
}

$mform = new local_ai_functions_edit_form();

if ($mform->is_cancelled()) {
    redirect($redirect_url);
} else if ($fromform = $mform->get_data()) {
    $record = new stdClass();
    if (!empty($fromform->id)) {
        $record->id = $fromform->id;
    }
    $record->name = $fromform->name;
    $record->agent_key = $fromform->agent_key;
    $record->endpoint = $fromform->endpoint;
    $record->config_data = $fromform->config_data;
    $record->timemodified = time();

    if (!empty($record->id)) {
        $DB->update_record('local_ai_functions_agents', $record);
    } else {
        $record->timecreated = time();
        $DB->insert_record('local_ai_functions_agents', $record);
    }
    redirect($redirect_url);
}

if ($id) {
    $agent = $DB->get_record('local_ai_functions_agents', ['id' => $id]);
    if ($agent) {
        $mform->set_data($agent);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading($id ? get_string('edit_agent_heading', 'local_ai_functions') : get_string('add_agent_heading', 'local_ai_functions'));
$mform->display();
echo $OUTPUT->footer();
