<?php

defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Keyguard_consumers.php';

/**
 * Keyguard_policy
 *
 * Pure decision logic: given a description of the current request, decide whether
 * the Perfex core `google_api_key` option value may be returned to the caller.
 *
 * This class performs NO I/O. It reads no database, no session, no superglobals.
 * Everything it needs arrives in the $context array assembled by the module
 * bootstrap. That is deliberate: the decision is the part that has to be correct,
 * so it is the part that has to be testable without a CRM around it.
 *
 * v1.1.0 — NARROWED. v1.0.0 allowed the admin area as a whole. This version
 * allows only the named consumers in Keyguard_consumers, each one gated on the
 * feature that switches it on and the capability the staff member must hold.
 * An admin route with no consumer gets nothing, exactly like a public one.
 */
class Keyguard_policy
{
    /** The one option name this policy governs. Nothing else is ever touched. */
    const GUARDED_OPTION = 'google_api_key';

    /** Decisions. */
    const ALLOW = 'allow';
    const DENY  = 'deny';

    /** Reasons — recorded so a decision can always be explained after the fact. */
    const R_NOT_GUARDED         = 'not_the_guarded_option';
    const R_EMPTY               = 'value_already_empty';
    const R_CLI                 = 'cli_or_cron_context';
    const R_CONSUMER            = 'named_consumer';               // + ':' . consumer id
    const R_LEADFINDER          = 'leadfinder_namespace_never_uses_core_key';
    const R_CLIENT_AREA         = 'customer_facing_page';
    const R_UNAUTHENTICATED     = 'unauthenticated_request';
    const R_STAFF_OUTSIDE_ADMIN = 'staff_session_but_customer_facing_page';
    const R_NO_CONSUMER         = 'no_consumer_for_this_route';
    const R_FEATURE_OFF         = 'consumer_feature_disabled';
    const R_NO_CAPABILITY       = 'staff_lacks_consumer_capability';

    /**
     * URI namespaces that must never receive the core key, checked before the
     * consumer registry so no future registry entry can accidentally cover one.
     *
     * Lead Finder holds its own Places key, encrypted, in its own table. It reads
     * tbloptions for nothing. The live namespace is `payplex_leadfinder`, taken
     * from admin_url('payplex_leadfinder/finder') in the module bootstrap and
     * confirmed against staging — `admin/finder` is kept only because a future
     * release could mount the controller directly.
     */
    public static function deniedAdminNamespaces()
    {
        return array(
            'payplex_leadfinder',
            'finder',
        );
    }

    /**
     * Context keys the policy understands. Anything absent is treated as false
     * or empty, which fails closed.
     */
    public static function contextKeys()
    {
        return array(
            'option_name',      // string - the option being read
            'value_is_empty',   // bool   - nothing to protect
            'is_cli',           // bool   - command line / cron worker
            'is_cron_request',  // bool   - the cron endpoint over HTTP
            'staff_logged_in',  // bool
            'client_logged_in', // bool
            'uri',              // string - uri_string(), no leading slash
            'admin_prefix',     // string - first segment of the admin area
            'features',         // array  - option name => bool, for the gated consumers
            'capabilities',     // array  - "feature.capability" => bool
        );
    }

    /**
     * Decide.
     *
     * @param  array $context
     * @return array {decision, reason, consumer}
     */
    public static function decide(array $context)
    {
        $name = isset($context['option_name']) ? (string) $context['option_name'] : '';

        // 1. Only ever act on the one guarded option. Every other option in the
        //    CRM passes through this filter untouched.
        if ($name !== self::GUARDED_OPTION) {
            return self::result(self::ALLOW, self::R_NOT_GUARDED);
        }

        // 2. Nothing configured: nothing to protect.
        if (!empty($context['value_is_empty'])) {
            return self::result(self::ALLOW, self::R_EMPTY);
        }

        // 3. Command line and cron. Server-side consumers run here and there is
        //    no HTTP response for the value to leak into.
        if (!empty($context['is_cli']) || !empty($context['is_cron_request'])) {
            return self::result(self::ALLOW, self::R_CLI);
        }

        $uri    = self::normaliseUri(isset($context['uri']) ? $context['uri'] : '');
        $prefix = isset($context['admin_prefix']) ? $context['admin_prefix'] : '';

        // 4. Lead Finder, before anything else can match it.
        if (self::isDeniedAdminNamespace($uri, $prefix)) {
            return self::result(self::DENY, self::R_LEADFINDER);
        }

        // 5. Nobody authenticated: public login, admin login, registration,
        //    password reset, public knowledge base.
        if (empty($context['staff_logged_in'])) {
            if (!empty($context['client_logged_in'])) {
                return self::result(self::DENY, self::R_CLIENT_AREA);
            }

            return self::result(self::DENY, self::R_UNAUTHENTICATED);
        }

        // 6. A staff session on a customer-facing URL is still a customer-facing
        //    page, and its HTML must not carry the key.
        $inAdminArea = self::isAdminArea($uri, $prefix);

        if (!$inAdminArea) {
            return self::result(self::DENY, self::R_STAFF_OUTSIDE_ADMIN);
        }

        // 7. The narrowing. A route inside the admin area is allowed only if a
        //    registered consumer covers it, that consumer's feature is on, and
        //    the staff member holds the capability it names.
        $features     = isset($context['features']) && is_array($context['features'])
            ? $context['features'] : array();
        $capabilities = isset($context['capabilities']) && is_array($context['capabilities'])
            ? $context['capabilities'] : array();

        // Routes in the registry are stored relative to the admin prefix, so
        // that a renamed admin folder does not stop every consumer matching.
        $rel = self::adminRelative($uri, $prefix);

        // Only a consumer that names this route may explain a denial. The
        // picker entry matches the admin area as a whole, so without this
        // distinction every unrelated admin route would report "feature
        // disabled" — blaming the picker for a route that has no consumer at
        // all, and hiding a missing capability behind it.
        $namedReason = null;

        foreach (Keyguard_consumers::all() as $consumer) {
            if (!Keyguard_consumers::matchesRoute($consumer, $rel, $inAdminArea)) {
                continue;
            }

            $namesThisRoute = ($consumer['match'] !== Keyguard_consumers::M_ADMIN_AREA);

            // Feature gate: a consumer whose feature is switched off is not a
            // consumer. This is what keeps a disabled calendar integration and
            // a disabled picker from receiving the key.
            if (!empty($consumer['feature']) && empty($features[$consumer['feature']])) {
                if ($namesThisRoute && $namedReason === null) {
                    $namedReason = self::R_FEATURE_OFF;
                }
                continue;
            }

            // Capability gate: being inside the admin area is not the same as
            // being allowed to see the screen the key belongs to.
            if (!empty($consumer['capability'])) {
                $key = Keyguard_consumers::capabilityKey($consumer['capability']);
                if (empty($capabilities[$key])) {
                    if ($namesThisRoute && $namedReason === null) {
                        $namedReason = self::R_NO_CAPABILITY;
                    }
                    continue;
                }
            }

            return self::result(self::ALLOW, self::R_CONSUMER . ':' . $consumer['id'], $consumer['id']);
        }

        return self::result(self::DENY, $namedReason === null ? self::R_NO_CONSUMER : $namedReason);
    }

    /**
     * Convenience wrapper: the value the filter should return.
     *
     * @param  mixed $value
     * @param  array $context
     * @return mixed
     */
    public static function apply($value, array $context)
    {
        $d = self::decide($context);

        return $d['decision'] === self::ALLOW ? $value : '';
    }

    /**
     * Is this URI inside the admin area?
     *
     * The admin prefix is derived at runtime rather than hard-coded, because a
     * Perfex install can rename it.
     */
    public static function isAdminArea($uri, $adminPrefix)
    {
        $uri    = self::normaliseUri($uri);
        $prefix = trim((string) $adminPrefix, '/');

        if ($prefix === '') {
            return false;
        }

        return $uri === $prefix || strpos($uri, $prefix . '/') === 0;
    }

    /**
     * Is this URI one of the namespaces that must not receive the key?
     */
    public static function isDeniedAdminNamespace($uri, $adminPrefix)
    {
        $uri    = self::normaliseUri($uri);
        $prefix = trim((string) $adminPrefix, '/');

        if ($prefix === '' || strpos($uri, $prefix . '/') !== 0) {
            return false;
        }

        $rest = substr($uri, strlen($prefix) + 1);

        foreach (self::deniedAdminNamespaces() as $ns) {
            if ($rest === $ns || strpos($rest, $ns . '/') === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * The part of the URI below the admin prefix.
     *
     * 'admin/clients' with prefix 'admin' gives 'clients'; 'backoffice/clients'
     * with prefix 'backoffice' gives the same. Registry routes are written in
     * this form so they survive a renamed admin folder.
     *
     * @param  string $uri
     * @param  string $adminPrefix
     * @return string
     */
    public static function adminRelative($uri, $adminPrefix)
    {
        $uri    = self::normaliseUri($uri);
        $prefix = trim((string) $adminPrefix, '/');

        if ($prefix === '') {
            return $uri;
        }

        if ($uri === $prefix) {
            return '';
        }

        if (strpos($uri, $prefix . '/') === 0) {
            return substr($uri, strlen($prefix) + 1);
        }

        return $uri;
    }

    /**
     * Strip leading and trailing slashes.
     */
    public static function normaliseUri($uri)
    {
        return trim((string) $uri, '/');
    }

    private static function result($decision, $reason, $consumer = null)
    {
        return array('decision' => $decision, 'reason' => $reason, 'consumer' => $consumer);
    }
}
