<?php
// FILE: moodle/local/ai_functions/edit_form.php
require_once($CFG->libdir . '/formslib.php');

class local_ai_functions_edit_form extends moodleform {
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('agent_name', 'local_ai_functions'), 'maxlength="255" size="50"');
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required');

        $mform->addElement('text', 'agent_key', get_string('agent_key', 'local_ai_functions'), 'maxlength="100" size="50"');
        // FIX: The second parameter should be the string identifier, not the final help string key.
        $mform->addHelpButton('agent_key', 'agent_key', 'local_ai_functions');
        $mform->setType('agent_key', PARAM_ALPHANUMEXT);
        $mform->addRule('agent_key', null, 'required');

        $mform->addElement('text', 'endpoint', get_string('agent_endpoint', 'local_ai_functions'), 'size="70"');
        // FIX: Corrected the help button call.
        $mform->addHelpButton('endpoint', 'agent_endpoint', 'local_ai_functions');
        $mform->setType('endpoint', PARAM_URL);
        $mform->addRule('endpoint', null, 'required');

        $mform->addElement('textarea', 'config_data', get_string('config_data', 'local_ai_functions'), 'rows="8" cols="70"');
        // FIX: Corrected the help button call.
        $mform->addHelpButton('config_data', 'config_data', 'local_ai_functions');
        $mform->setType('config_data', PARAM_RAW);
        $mform->addRule('config_data', null, 'required');
        
        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }

    function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (json_decode($data['config_data']) === null) {
            $errors['config_data'] = get_string('error_invalid_json', 'local_ai_functions');
        }
        return $errors;
    }
}

