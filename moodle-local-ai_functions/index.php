<?php
// FILE: moodle/local/ai_functions/index.php
require_once('../../config.php');
require_once($CFG->libdir.'/adminlib.php');

admin_externalpage_setup('local_ai_functions_manage');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manage_agents_heading', 'local_ai_functions'));

$agents = $DB->get_records('local_ai_functions_agents');
$table = new html_table();
$table->head = [
    get_string('agent_name', 'local_ai_functions'),
    get_string('agent_key', 'local_ai_functions'),
    get_string('agent_endpoint', 'local_ai_functions'),
    get_string('actions', 'local_ai_functions')
];
$table->data = [];

foreach ($agents as $agent) {
    $editurl = new moodle_url('/local/ai_functions/edit.php', ['id' => $agent->id]);
    $deleteurl = new moodle_url('/local/ai_functions/edit.php', ['id' => $agent->id, 'action' => 'delete', 'sesskey' => sesskey()]);
    
    $actions = html_writer::link($editurl, get_string('edit'));
    $actions .= ' | ';
    $actions .= html_writer::link($deleteurl, get_string('delete'), ['onclick' => "return confirm('Are you sure?');"]);

    $table->data[] = [
        htmlspecialchars($agent->name),
        htmlspecialchars($agent->agent_key),
        htmlspecialchars($agent->endpoint),
        $actions
    ];
}

echo html_writer::table($table);

$addurl = new moodle_url('/local/ai_functions/edit.php');
echo $OUTPUT->single_button($addurl, get_string('add_new_agent', 'local_ai_functions'));

echo $OUTPUT->footer();
