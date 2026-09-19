<?php
defined('BASEPATH') or exit('No direct script access allowed');

/**
 * Deliberately non-destructive. KYC recordings are compliance evidence and
 * have retention obligations, so deactivating or uninstalling the module keeps
 * every table and every stored video. Drop them by hand only after your
 * retention period has passed and legal has signed off.
 */
