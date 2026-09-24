<?php

defined('BASEPATH') or exit('No direct script access allowed');

class Migration_Version_341 extends CI_Migration
{
    public function up(): void
    {
        add_option('contract_number_prefix', 'CON-');
    }
}
