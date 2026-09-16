<?php
defined('BASEPATH') or defined('LEADGEN_FB_TEST') or exit('No direct script access allowed');

/**
 * Migration 005 — the five custom-field definitions the lead references need.
 *
 * ⚠️ THIS IS THE FIRST MIGRATION IN THIS MODULE THAT TOUCHES CORE TABLES.
 * ------------------------------------------------------------------------
 * Every migration before this one declared `touches_core_tables => false` and a
 * test asserted it against the statements rather than against the declaration.
 * This one declares **true**, names exactly which core tables it writes, and is
 * held to a higher bar because of it. Read this section before approving it.
 *
 * WHAT IT DOES, PRECISELY
 * -----------------------
 * It inserts five rows into `tblcustomfields` — the definitions of five custom
 * fields on the Leads form. It does **not** ALTER any table, add any column, or
 * change any existing row. Perfex custom fields are an entity-attribute-value
 * design: the definition is a row, and the values live in
 * `tblcustomfieldsvalues`, which already exists and already holds every other
 * module's values.
 *
 * That distinction is the whole reason this is acceptable at all. An ALTER on
 * `tblleads` would be a schema change to the CRM's central table, taken by a
 * module, on production — and I would be refusing to write it. Inserting five
 * definition rows is the mechanism Perfex provides for exactly this, it is what
 * the Setup → Custom Fields screen does, and it reverses with a DELETE.
 *
 * WHY IT IS NEEDED
 * ----------------
 * One CRM now receives leads from several Facebook Pages belonging to several
 * businesses. The module already records `page_id`, `form_id`, `campaign_id`
 * and the Facebook lead reference on its own claim row, which is enough to
 * reconcile a delivery and prove idempotency — and invisible to the salesperson
 * who opens the lead. Without these fields, "which campaign produced this lead"
 * is answerable only by someone with database access.
 *
 * WHAT IT DELIBERATELY DOES NOT SEED
 * ----------------------------------
 * The business unit and Page name are seeded **empty**. Writing "DealPlex" and
 * "Dealplex Solutions" into this file would hard-code one customer's business
 * into the module — the same class of mistake as the hard-coded status id this
 * module was opened to fix, which worked on one install and mislabelled
 * everything on the next. They are configuration, set on the settings page.
 *
 * A lead arriving before they are configured is tagged with its source only.
 * That is a missing tag, which is visible; not a wrong tag, which is not.
 *
 * SAFETY
 * ------
 * Additive. Five row inserts, each guarded by a uniqueness check on
 * `(fieldto, slug)` so a re-run inserts nothing. No schema change, no existing
 * row modified, no value written. `down` deletes exactly the five definitions
 * by slug.
 */
return array(
    'version'             => 5,
    'name'                => '005_lead_tagging_and_fields',

    /*
     * Declared true, and the core tables named. The discipline test requires
     * both: a migration that touches core tables and says false is worse than
     * one that says true, because the declaration is what a reviewer reads.
     */
    'touches_core_tables' => true,
    'core_tables'         => array('customfields'),
    'core_justification'  => 'Inserts five custom-field DEFINITION rows for the Leads '
        . 'form so the Facebook Page, Page ID, Form ID, Campaign ID and Facebook Lead ID '
        . 'appear on the lead record. Row inserts only — no ALTER, no column, no '
        . 'existing row modified. This is the mechanism Setup > Custom Fields uses. '
        . 'Reversed by deleting the five rows by slug.',

    'up' => array(
        array(
            'kind'   => 'insert_row_if_absent',
            'table'  => 'customfields',
            'unique' => array('fieldto' => 'leads', 'slug' => 'facebook_page'),
            'row'    => array(
                'fieldto' => 'leads', 'name' => 'Facebook Page', 'slug' => 'facebook_page',
                'type' => 'input', 'required' => 0, 'only_admin' => 0,
                'show_on_table' => 1, 'field_order' => 90, 'active' => 1,
                'disalow_client_to_edit' => 1, 'show_on_pdf' => 0, 'show_on_client_portal' => 0,
                'options' => '', 'display_inline' => 0, 'bs_column' => 12, 'default_value' => '',
            ),
        ),
        array(
            'kind'   => 'insert_row_if_absent',
            'table'  => 'customfields',
            'unique' => array('fieldto' => 'leads', 'slug' => 'facebook_page_id'),
            'row'    => array(
                'fieldto' => 'leads', 'name' => 'Facebook Page ID', 'slug' => 'facebook_page_id',
                'type' => 'input', 'required' => 0, 'only_admin' => 0,
                'show_on_table' => 0, 'field_order' => 91, 'active' => 1,
                'disalow_client_to_edit' => 1, 'show_on_pdf' => 0, 'show_on_client_portal' => 0,
                'options' => '', 'display_inline' => 0, 'bs_column' => 12, 'default_value' => '',
            ),
        ),
        array(
            'kind'   => 'insert_row_if_absent',
            'table'  => 'customfields',
            'unique' => array('fieldto' => 'leads', 'slug' => 'facebook_form_id'),
            'row'    => array(
                'fieldto' => 'leads', 'name' => 'Facebook Form ID', 'slug' => 'facebook_form_id',
                'type' => 'input', 'required' => 0, 'only_admin' => 0,
                'show_on_table' => 0, 'field_order' => 92, 'active' => 1,
                'disalow_client_to_edit' => 1, 'show_on_pdf' => 0, 'show_on_client_portal' => 0,
                'options' => '', 'display_inline' => 0, 'bs_column' => 12, 'default_value' => '',
            ),
        ),
        array(
            'kind'   => 'insert_row_if_absent',
            'table'  => 'customfields',
            'unique' => array('fieldto' => 'leads', 'slug' => 'facebook_campaign_id'),
            'row'    => array(
                'fieldto' => 'leads', 'name' => 'Facebook Campaign ID', 'slug' => 'facebook_campaign_id',
                'type' => 'input', 'required' => 0, 'only_admin' => 0,
                'show_on_table' => 0, 'field_order' => 93, 'active' => 1,
                'disalow_client_to_edit' => 1, 'show_on_pdf' => 0, 'show_on_client_portal' => 0,
                'options' => '', 'display_inline' => 0, 'bs_column' => 12, 'default_value' => '',
            ),
        ),
        array(
            'kind'   => 'insert_row_if_absent',
            'table'  => 'customfields',
            'unique' => array('fieldto' => 'leads', 'slug' => 'facebook_lead_id'),
            'row'    => array(
                'fieldto' => 'leads', 'name' => 'Facebook Lead ID', 'slug' => 'facebook_lead_id',
                'type' => 'input', 'required' => 0, 'only_admin' => 0,
                'show_on_table' => 0, 'field_order' => 94, 'active' => 1,
                'disalow_client_to_edit' => 1, 'show_on_pdf' => 0, 'show_on_client_portal' => 0,
                'options' => '', 'display_inline' => 0, 'bs_column' => 12, 'default_value' => '',
            ),
        ),
    ),

    /**
     * Seeded empty, deliberately. See the note above on hard-coding.
     *
     * `facebook_tagging_enabled` defaults ON because tagging is the point of
     * this migration and a lead that arrives untagged into a shared CRM is the
     * problem being fixed. It writes tags and custom-field values on the lead
     * and nothing else; no option here can cause anything to be sent.
     */
    'options' => array(
        'facebook_business_unit'   => '',
        'facebook_page_name'       => '',
        'facebook_tagging_enabled' => '1',
    ),

    /**
     * Rollback. Deletes exactly the five definitions this migration created,
     * matched on `fieldto` AND `slug` so a field with a colliding slug on
     * another entity is untouched.
     *
     * It deliberately does NOT delete from `tblcustomfieldsvalues`: those rows
     * are the values recorded against real leads, and removing the field
     * definition while leaving the values keeps the data recoverable. See the
     * warning.
     */
    'down' => array(
        "DELETE FROM `{P}customfields` WHERE fieldto = 'leads' AND slug = 'facebook_page'",
        "DELETE FROM `{P}customfields` WHERE fieldto = 'leads' AND slug = 'facebook_page_id'",
        "DELETE FROM `{P}customfields` WHERE fieldto = 'leads' AND slug = 'facebook_form_id'",
        "DELETE FROM `{P}customfields` WHERE fieldto = 'leads' AND slug = 'facebook_campaign_id'",
        "DELETE FROM `{P}customfields` WHERE fieldto = 'leads' AND slug = 'facebook_lead_id'",
    ),

    'down_warning' => 'Deleting these five definitions hides the Facebook Page, '
        . 'Page ID, Form ID, Campaign ID and Lead ID from every lead that has them. '
        . 'The VALUES are not deleted — they stay in tblcustomfieldsvalues and '
        . 'reappear if the definitions are recreated with the same slugs — but they '
        . 'become invisible and unqueryable through the UI in the meantime. Export '
        . 'tblcustomfieldsvalues for these field ids before rolling back. Tags '
        . 'already applied to leads are not touched by this rollback at all.',
);
