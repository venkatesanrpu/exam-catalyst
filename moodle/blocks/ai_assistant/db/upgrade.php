<?php
// FILE: blocks/ai_assistant/db/upgrade.php

defined('MOODLE_INTERNAL') || die();

function xmldb_block_ai_assistant_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2025122002) {

        $table = new xmldb_table('block_ai_assistant_syllabus');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE);
        $table->add_field('blockinstanceid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        // IMPORTANT: do NOT set DEFAULT '' for NOT NULL CHAR fields.
        $table->add_field('agent_key', XMLDB_TYPE_CHAR, '100', null, XMLDB_NOTNULL, null, null);
        $table->add_field('mainsubjectkey', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);

        $table->add_field('syllabus_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);

        $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
        $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('blockinstanceid_uq', XMLDB_KEY_UNIQUE, ['blockinstanceid']);

        // Do NOT add an extra index on blockinstanceid; UNIQUE key already creates one.

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        upgrade_block_savepoint(true, 2025122002, 'ai_assistant');
    }

    return true;
}
