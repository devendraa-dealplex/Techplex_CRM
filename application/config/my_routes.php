<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * Custom routes (included at the end of application/config/routes.php).
 *
 * Module-owned route files only apply to URLs that START with the module name,
 * so the pretty Video KYC URLs live here instead.
 */

/* ---- Video KYC: staff side (session + CSRF protected) ---- */
$route['admin/video-kyc']                 = 'payplex_videokyc/videokyc/index';
$route['admin/video-kyc/(:any)/(:any)']   = 'payplex_videokyc/videokyc/$1/$2';
$route['admin/video-kyc/(:any)']          = 'payplex_videokyc/videokyc/$1';

/* ---- Video KYC: customer portal (logged-in customer, own KYC only) ---- */
$route['clients/video-kyc']               = 'payplex_videokyc/kyc_portal/index';
$route['clients/video-kyc/(:any)']        = 'payplex_videokyc/kyc_portal/$1';

/* ---- Video KYC: customer side (public, authorised by the link token) ----
 *
 * These are deliberately NOT under /api/... — core CSRF config exempts every
 * URI matching 'api\/.+' (for the REST API module), which would silently switch
 * CSRF protection off for anything routed there. */
$route['kyc/verify']                      = 'payplex_videokyc/kyc_public/index';
$route['kyc/api/validate/(:any)']         = 'payplex_videokyc/kyc_public/validate/$1';
$route['kyc/api/upload']                  = 'payplex_videokyc/kyc_public/upload';
