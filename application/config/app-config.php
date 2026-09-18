<?php

defined('BASEPATH') or exit('No direct script access allowed');
/*
* --------------------------------------------------------------------------
* Base Site URL
* --------------------------------------------------------------------------
*
* URL to your CodeIgniter root. Typically this will be your base URL,
* WITH a trailing slash:
*
*   http://example.com/
*
* If this is not set then CodeIgniter will try guess the protocol, domain
* and path to your installation. However, you should always configure this
* explicitly and never rely on auto-guessing, especially in production
* environments.
*
*/
define('APP_BASE_URL', 'http://localhost:8000/');

/*
* --------------------------------------------------------------------------
* Encryption Key
* IMPORTANT: Do not change this ever!
* --------------------------------------------------------------------------
*
* If you use the Encryption class, you must set an encryption key.
* See the user guide for more info.
*
* http://codeigniter.com/user_guide/libraries/encryption.html
*
* Auto added on install
*/
define('APP_ENC_KEY', '9e460be55c2381694698ee190b37a99d');

/**
 * Database Credentials
 * The hostname of your database server
 */
define('APP_DB_HOSTNAME', 'localhost');

/**
 * The username used to connect to the database
 */
define('APP_DB_USERNAME', 'dealplex');

/**
 * The password used to connect to the database
 */
define('APP_DB_PASSWORD', 'Dealplex_12345');

/**
 * The name of the database you want to connect to
 */
define('APP_DB_NAME', 'support_dealplex');

/**
 * @since  2.3.0
 * Database charset
 */
define('APP_DB_CHARSET', 'utf8mb4');

/**
 * @since  2.3.0
 * Database collation
 */
define('APP_DB_COLLATION', 'utf8mb4_unicode_ci');

/**
 *
 * Session handler driver
 * By default the database driver will be used.
 *
 * For files session use this config:
 * define('SESS_DRIVER', 'files');
 * define('SESS_SAVE_PATH', NULL);
 * In case you are having problem with the SESS_SAVE_PATH consult with your hosting provider to set "session.save_path" value to php.ini
 *
 */
define('SESS_DRIVER', 'database');
define('SESS_SAVE_PATH', 'sessions');
define('APP_SESSION_COOKIE_SAME_SITE', 'Lax');

/**
 * Enables CSRF Protection
 */
define('APP_CSRF_PROTECTION', true);

/* -----------------------------------------------------------------
 * CSRF exclusions added by the Payplex AI Calling module.
 *
 * config.php merges this variable into its own csrf_exclude_uris, so
 * the exclusion lives here rather than in core and is not lost when
 * Perfex is upgraded.
 *
 * payplex_aicalling/webhook receives signed events from the calling
 * backend. It has no session for CSRF to protect: every request is
 * authenticated by an HMAC-SHA256 signature over the timestamp,
 * method, path and body digest, inside a 300-second replay window,
 * compared with hash_equals, and refused outright when no webhook
 * secret is configured. CSRF cannot apply to it; the signature can,
 * and does.
 * ----------------------------------------------------------------- */
$app_csrf_exclude_uris = isset($app_csrf_exclude_uris) ? $app_csrf_exclude_uris : array();
$app_csrf_exclude_uris[] = 'payplex_aicalling/webhook';
