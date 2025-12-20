<?php
defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/blocks/edit_form.php');
require_once($CFG->libdir . '/filelib.php'); // Draft area helpers.

/**
 * Block instance configuration form.
 */
class block_ai_assistant_edit_form extends block_edit_form {

    protected function specific_definition($mform) {
        global $DB;

        $mform->addElement('header', 'configheader', get_string('blocksettings', 'block'));

        // --- Agent Selection (existing) ---
        $agents = $DB->get_records('local_ai_functions_agents', [], 'name ASC');
        $options = [];
        foreach ($agents as $agent) {
            $options[$agent->agent_key] = $agent->name;
        }

        if (empty($options)) {
            $mform->addElement('static', 'noagents',
                get_string('no_agents_found_title', 'block_ai_assistant'),
                get_string('no_agents_found_desc', 'block_ai_assistant')
            );
        } else {
            $mform->addElement('select', 'config_agent_key',
                get_string('select_agent', 'block_ai_assistant'),
                $options
            );
            $mform->addHelpButton('config_agent_key', 'select_agent', 'block_ai_assistant');
            $mform->setDefault('config_agent_key', 'chemistry_ai');
        }

        // --- Main Subject Key (existing, audit label) ---
        $mform->addElement('text', 'config_mainsubjectkey', get_string('mainsubjectkey', 'block_ai_assistant'));
        $mform->setType('config_mainsubjectkey', PARAM_ALPHANUMEXT);
        $mform->addHelpButton('config_mainsubjectkey', 'mainsubjectkey', 'block_ai_assistant');
        $mform->addRule('config_mainsubjectkey', get_string('required'), 'required', null, 'client');

        // ===================== NEW: Syllabus JSON upload =====================
        // IMPORTANT:
        // - Do NOT use "config_" prefix for filemanager in a block instance form.
        // - Files are not stored in block config; they belong in File API file areas.
        $fileoptions = [
            'maxfiles' => 1,
            'subdirs' => 0,
            'accepted_types' => ['.json'],
        ];

        $mform->addElement(
            'filemanager',
            'syllabusfile', // No "config_" prefix (prevents JS initialisation issues in block forms).
            get_string('syllabusfile', 'block_ai_assistant'),
            null,
            $fileoptions
        );
        $mform->addHelpButton('syllabusfile', 'syllabusfile', 'block_ai_assistant');

        // Draft itemid is an integer.
        $mform->setType('syllabusfile', PARAM_INT);
    }

    /**
     * Load existing syllabus file into draft area so it appears when editing again.
     */
    public function set_data($defaults) {
        // parent::set_data() expects an object.
        if (is_array($defaults)) {
            $defaults = (object)$defaults;
        }

        // When editing an existing block, instance id exists and we can prepare the draft area.
        if (!empty($this->block) && !empty($this->block->instance) && !empty($this->block->instance->id)) {
            $context = context_block::instance($this->block->instance->id);

            $fileoptions = [
                'maxfiles' => 1,
                'subdirs' => 0,
                'accepted_types' => ['.json'],
            ];

            // Prepare draft area for our non-config filemanager element.
            $draftitemid = file_get_submitted_draft_itemid('syllabusfile');

            file_prepare_draft_area(
                $draftitemid,
                $context->id,
                'block_ai_assistant',
                'syllabus',
                0,
                $fileoptions
            );

            $defaults->syllabusfile = $draftitemid;
        }

        parent::set_data($defaults);
    }
}
?>