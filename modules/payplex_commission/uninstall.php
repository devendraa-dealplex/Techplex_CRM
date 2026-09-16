<?php
defined('BASEPATH') or exit('No direct script access allowed');
// Conservative: keep financial tables (audit/retention). Only remove options.
$CI = &get_instance();
delete_option('payplex_commission_currency');
