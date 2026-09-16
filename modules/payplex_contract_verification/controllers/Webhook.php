<?php

defined('BASEPATH') or exit('No direct script access allowed');

/*
 * REQUIRED HERE, NOT INHERITED.
 *
 * This controller calls Contract_webhook_guard::admit() and required nothing.
 * It happened to work whenever Signing.php had already been loaded in the same
 * request and pulled the guard in — which is an accident of load order, not a
 * dependency. A direct hit on this endpoint with no prior load is a fatal
 * error, and this is the one endpoint the public internet can reach.
 *
 * Found by the CI rule that every module class a runtime file names must be
 * required by that file.
 */
require_once __DIR__ . '/../libraries/Contract_failures.php';
require_once __DIR__ . '/../libraries/Contract_webhook_guard.php';

/**
 * Inbound provider callback — the module's only public, unauthenticated route.
 *
 * WHY THIS IS A SEPARATE CONTROLLER
 * ---------------------------------
 * The signing provider is a machine. It has no CRM session, no staff account
 * and no CSRF token. `Signing::webhook()` was written under `AdminController`,
 * which means Perfex's admin authentication redirects every unauthenticated
 * caller to the login page — so the provider could never have reached it. That
 * was not visible until the module was deployed and the route was actually
 * called from outside a logged-in browser.
 *
 * This follows the convention already established in this estate by
 * `leadgen_facebook`: admin screens extend `AdminController`, and the inbound
 * webhook is its own `Webhook` controller extending `App_Controller`, served
 * from a public path.
 *
 * WHAT BEING PUBLIC DOES *NOT* MEAN
 * ---------------------------------
 * It means unauthenticated, not unguarded. Everything that made the admin
 * version safe is enforced here and is mandatory:
 *
 *   - the raw body is read once, byte-exact, and never re-encoded before the
 *     signature is checked;
 *   - size is checked before anything is parsed;
 *   - the signature is verified and FAILS CLOSED — an algorithm this build has
 *     no documentation for is refused, which today means every delivery is
 *     refused, which is the correct behaviour for an endpoint whose
 *     authentication scheme is unspecified;
 *   - the timestamp is checked against a 300-second replay window in both
 *     directions;
 *   - idempotency is enforced by a UNIQUE key on the provider's event id;
 *   - every delivery, admitted or refused, is recorded with a digest of the
 *     body and never the body itself.
 *
 * Nothing here trusts the payload. Nothing here changes a contract's state
 * while `Contract_webhook_guard` has no verifier — it cannot, because the
 * guard refuses before the payload is parsed.
 */
class Webhook extends App_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('payplex_contract_verification/contract_verification_model', 'cv');
    }

    /**
     * POST /payplex_contract_verification/webhook
     *
     * Answers with a status code and a tiny JSON body. It never renders a view:
     * the caller is a machine and an HTML page explaining migrations would be
     * logged by the provider as a malformed response.
     */
    public function index()
    {
        if (strtoupper((string) $this->input->server('REQUEST_METHOD')) !== 'POST') {
            $this->output->set_status_header(405);

            return;
        }

        $raw = $this->rawBody();

        /*
         * The guard runs BEFORE the schema check on purpose. Whether our tables
         * exist is our problem, not something an unauthenticated caller should
         * be able to probe: an endpoint that answers differently depending on
         * internal state is an information leak, however small.
         */
        $decision = Contract_webhook_guard::admit(array(
            'raw_body'  => $raw,
            'signature' => (string) $this->input->get_request_header('X-Signature', true),
            'secret'    => '',
            'algorithm' => '',
            'timestamp' => $this->input->get_request_header('X-Timestamp', true),
            'now'       => time(),
            'seen_keys' => array(),
        ));

        if (!$this->cv->schemaReady()) {
            $this->output->set_status_header(503);

            return;
        }

        /* A refused delivery is exactly what somebody needs to see when they ask
           why a contract is not updating. The digest proves what arrived
           without being what arrived. */
        $this->cv->recordRefusedWebhook(array(
            'body_sha256' => hash('sha256', (string) $raw),
            'result'      => (string) $decision['reason'],
            'http_status' => (int) $decision['http_status'],
            'received_at' => time(),
        ));

        $this->output->set_status_header((int) $decision['http_status']);
        $this->output->set_content_type('application/json');
        $this->output->set_output(json_encode(array('received' => true)));
    }

    /**
     * The raw request body, read once and cached.
     *
     * `php://input` yields its contents once. A second read returns an empty
     * string, and a signature computed over an empty string never verifies —
     * which presents as "the provider's signatures are all wrong" and is very
     * hard to see from the outside.
     */
    private function rawBody()
    {
        static $body = null;

        if ($body !== null) { return $body; }

        $body = (string) file_get_contents('php://input');

        return $body;
    }
}
