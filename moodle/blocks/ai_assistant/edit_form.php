<?php
// FILE: moodle/blocks/ai_assistant/edit_form.php
// UPDATE: Adds the new 'mainsubjectkey' text field to the block's configuration form.

defined('MOODLE_INTERNAL') || die();

class block_ai_assistant_edit_form extends block_edit_form {
    protected function specific_definition($mform) {
        global $DB;

        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // --- Agent Selection (No changes here) ---
        $agents = $DB->get_records('local_ai_functions_agents', [], 'name ASC');
        $options = [];
        if (!empty($agents)) {
            foreach ($agents as $agent) {
                $options[$agent->agent_key] = $agent->name;
            }
        }

        if (empty($options)) {
            $mform->addElement('static', 'noagents', get_string('no_agents_found_title', 'block_ai_assistant'), get_string('no_agents_found_desc', 'block_ai_assistant'));
        } else {
            $mform->addElement('select', 'config_agent_key', get_string('select_agent', 'block_ai_assistant'), $options);
            $mform->addHelpButton('config_agent_key', 'select_agent', 'block_ai_assistant');
            $mform->setDefault('config_agent_key', 'chemistry_ai');
        }

        // --- NEW: Main Subject Key Setting ---
        $mform->addElement('text', 'config_mainsubjectkey', get_string('mainsubjectkey', 'block_ai_assistant'));
        $mform->setType('config_mainsubjectkey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('config_mainsubjectkey', 'mainsubjectkey', 'block_ai_assistant');
        $mform->addRule('config_mainsubjectkey', get_string('required'), 'required', null, 'client');
        // --- END OF NEW SETTING ---
    }
}