<?php
defined('BASEPATH') or exit('No direct script access allowed');

require_once __DIR__ . '/Payplex_kyc_scripts.php';

/**
 * Multi-channel delivery of a KYC link: Email (Perfex SMTP), SMS (Twilio) and
 * WhatsApp (Twilio or Meta Cloud API).
 *
 * Every channel is independent — one failing never blocks the others — and every
 * outcome (sent / failed / skipped, with the reason) is written to
 * payplex_vkyc_notifications so staff can see WHY a customer never got a link.
 *
 * The link contains the raw token, so it is never written to a log or an error
 * string; only recipients and provider message ids are recorded.
 *
 * Providers are plain cURL against the REST APIs: no SDK to keep in sync, and
 * nothing to break if composer autoloading changes.
 */
class Payplex_kyc_notifier
{
    const CHANNELS = ['email', 'sms', 'whatsapp'];

    private $CI;
    private $model;

    public function __construct($model)
    {
        $this->CI    = &get_instance();
        $this->model = $model;
    }

    /* ------------------------------------------------------------------ link */

    public static function buildLink($rawToken)
    {
        return site_url('kyc/verify') . '?token=' . rawurlencode($rawToken);
    }

    /* --------------------------------------------------------------- secrets */

    /** Provider credentials are encrypted at rest with the app's encryption key. */
    public static function saveSecret($option, $plain)
    {
        $CI = &get_instance();
        $CI->load->library('encryption');
        $enc = $CI->encryption->encrypt($plain);
        if ($enc === false) {
            return false;
        }
        update_option($option, $enc);
        return true;
    }

    public static function readSecret($option)
    {
        $raw = get_option($option);
        if ($raw === null || $raw === '') {
            return '';
        }
        $CI = &get_instance();
        $CI->load->library('encryption');
        $dec = $CI->encryption->decrypt($raw);
        return $dec === false ? '' : (string) $dec;
    }

    /** "Configured" means it decrypts to something non-empty — not merely that a row exists. */
    public static function secretUsable($option)
    {
        return self::readSecret($option) !== '';
    }

    /* ---------------------------------------------------------------- phones */

    /**
     * Normalise to E.164 for SMS/WhatsApp.
     *  - Indian mobiles (10 digits, 6-9 start, optional 0/91/+91) → +91XXXXXXXXXX
     *  - An explicit "+<country><number>" (8-15 digits) is kept as given
     *  - anything else → null (skipped, with the reason logged)
     */
    public static function normalizePhone($raw)
    {
        $raw    = trim((string) $raw);
        $digits = preg_replace('/\D+/', '', $raw);
        if ($digits === '') {
            return null;
        }
        $national = preg_replace('/^(?:0|91)(?=\d{10}$)/', '', $digits);
        if (preg_match('/^[6-9]\d{9}$/', $national)) {
            return '+91' . $national;
        }
        if (strpos($raw, '+') === 0 && strlen($digits) >= 8 && strlen($digits) <= 15) {
            return '+' . $digits;
        }
        return null;
    }

    /* -------------------------------------------------------------- dispatch */

    /**
     * @param object $req      request row
     * @param string $rawToken
     * @param array  $channels subset of CHANNELS
     * @return array channel => ['status' => sent|failed|skipped, 'error' => ?string]
     */
    public function dispatch($req, $rawToken, array $channels)
    {
        $link    = self::buildLink($rawToken);
        $results = [];
        foreach (array_intersect(self::CHANNELS, $channels) as $ch) {
            try {
                switch ($ch) {
                    case 'email':    $res = $this->sendEmail($req, $link);    break;
                    case 'sms':      $res = $this->sendSms($req, $link);      break;
                    default:         $res = $this->sendWhatsApp($req, $link); break;
                }
            } catch (\Throwable $e) {
                $res = ['status' => 'failed', 'recipient' => null, 'id' => null, 'error' => 'Unexpected error: ' . get_class($e)];
            }
            $this->model->logNotification($req->id, $ch, $res['recipient'], $res['status'], $res['id'], $res['error']);
            $results[$ch] = ['status' => $res['status'], 'error' => $res['error']];
        }
        return $results;
    }

    /* ------------------------------------------------------------ templating */

    private function lang($req)
    {
        return Payplex_kyc_scripts::normalize(isset($req->script_language) ? $req->script_language : 'en');
    }

    private function vars($req, $link)
    {
        return [
            '{customer_name}' => $req->customer_name,
            '{name}'          => $req->customer_name,
            '{company}'       => (string) get_option('companyname'),
            '{link}'          => $link,
            // expiry is written in the customer's language (month names)
            '{expiry}'        => Payplex_kyc_scripts::formatDateTime($this->lang($req), strtotime($req->expires_at)),
        ];
    }

    /**
     * SMS / non-template WhatsApp text. The admin's custom wording (Settings → Message text)
     * is English, so it is only used for English recipients; Hindi and Marathi recipients get the
     * built-in localized text rather than an English sentence.
     */
    private function smsBody($req, $link)
    {
        $lang = $this->lang($req);
        $tpl  = $lang === 'en' ? trim((string) get_option('payplex_videokyc_msg_sms')) : '';
        if ($tpl === '') {
            $tpl = Payplex_kyc_scripts::messages($lang)['sms'];
        }
        return strtr($tpl, $this->vars($req, $link));
    }

    /* ----------------------------------------------------------------- email */

    private function sendEmail($req, $link)
    {
        $to = trim((string) $req->customer_email);
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->skip('No valid email address on file.', $to);
        }
        $v   = $this->vars($req, $link);
        $msg = Payplex_kyc_scripts::messages($this->lang($req));
        $e   = function ($t) use ($v) {
            return htmlspecialchars(strtr($t, $v), ENT_QUOTES, 'UTF-8');
        };
        $href = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $html = "<div style=\"font-family:'Segoe UI','Noto Sans Devanagari',Mangal,Arial,sans-serif;font-size:14px;color:#222\">"
              . '<p>' . $e($msg['email_hello']) . '</p>'
              . '<p>' . $e($msg['email_body']) . '</p>'
              . "<p><a href=\"{$href}\" style=\"background:#0d6efd;color:#fff;padding:10px 18px;border-radius:4px;"
              . "text-decoration:none;display:inline-block\">" . $e($msg['email_button']) . '</a></p>'
              . '<p style="color:#666">' . $e($msg['email_note']) . '</p></div>';

        $this->CI->load->model('emails_model');
        $ok = $this->CI->emails_model->send_simple_email($to, strtr($msg['email_subject'], $v), $html);
        return $ok
            ? ['status' => 'sent', 'recipient' => $to, 'id' => null, 'error' => null]
            : ['status' => 'failed', 'recipient' => $to, 'id' => null, 'error' => 'SMTP send failed — check Setup → Email.'];
    }

    /* ------------------------------------------------------------------- sms */

    private function sendSms($req, $link)
    {
        $to = self::normalizePhone($req->customer_phone);
        if ($to === null) {
            return $this->skip('No valid mobile number on file.', $req->customer_phone);
        }
        $sid   = trim((string) get_option('payplex_videokyc_twilio_sid'));
        $token = self::readSecret('payplex_videokyc_twilio_token');
        $from  = trim((string) get_option('payplex_videokyc_twilio_sms_from'));
        $msvc  = trim((string) get_option('payplex_videokyc_twilio_messaging_service'));
        if ($sid === '' || $token === '' || ($from === '' && $msvc === '')) {
            return $this->skip('SMS (Twilio) is not configured.', $to);
        }

        $fields = ['To' => $to, 'Body' => $this->smsBody($req, $link)];
        $msvc !== '' ? $fields['MessagingServiceSid'] = $msvc : $fields['From'] = $from;

        return $this->twilio($sid, $token, $fields, $to);
    }

    /* -------------------------------------------------------------- whatsapp */

    private function sendWhatsApp($req, $link)
    {
        $to = self::normalizePhone($req->customer_phone);
        if ($to === null) {
            return $this->skip('No valid mobile number on file.', $req->customer_phone);
        }
        return get_option('payplex_videokyc_whatsapp_provider') === 'meta'
            ? $this->whatsAppMeta($req, $link, $to)
            : $this->whatsAppTwilio($req, $link, $to);
    }

    private function whatsAppTwilio($req, $link, $to)
    {
        $sid   = trim((string) get_option('payplex_videokyc_twilio_sid'));
        $token = self::readSecret('payplex_videokyc_twilio_token');
        $from  = trim((string) get_option('payplex_videokyc_twilio_wa_from'));
        if ($sid === '' || $token === '' || $from === '') {
            return $this->skip('WhatsApp (Twilio) is not configured.', $to);
        }
        $fields = ['To' => 'whatsapp:' . $to, 'From' => 'whatsapp:' . preg_replace('/^whatsapp:/i', '', $from)];

        // Business-initiated WhatsApp messages must use a pre-approved template. A template has fixed
        // wording in ONE language, so Hindi/Marathi have their own optional Content SIDs; without one
        // the default template is used (and its language is whatever it was approved in).
        $lang       = $this->lang($req);
        $contentSid = $lang !== 'en' ? trim((string) get_option('payplex_videokyc_twilio_wa_content_sid_' . $lang)) : '';
        if ($contentSid === '') {
            $contentSid = trim((string) get_option('payplex_videokyc_twilio_wa_content_sid'));
        }
        if ($contentSid !== '') {
            $fields['ContentSid']       = $contentSid;
            $fields['ContentVariables'] = json_encode(['1' => $req->customer_name, '2' => (string) get_option('companyname'), '3' => $link]);
        } else {
            $fields['Body'] = $this->smsBody($req, $link);   // sandbox / inside a 24h session window only
        }
        return $this->twilio($sid, $token, $fields, $to);
    }

    private function whatsAppMeta($req, $link, $to)
    {
        $phoneId  = trim((string) get_option('payplex_videokyc_meta_phone_id'));
        $token    = self::readSecret('payplex_videokyc_meta_token');
        $template = trim((string) get_option('payplex_videokyc_meta_template'));
        $lang     = trim((string) get_option('payplex_videokyc_meta_template_lang')) ?: 'en';
        // Hindi / Marathi: an optional dedicated approved template (its language code is 'hi' / 'mr').
        $reqLang  = $this->lang($req);
        if ($reqLang !== 'en') {
            $localized = trim((string) get_option('payplex_videokyc_meta_template_' . $reqLang));
            if ($localized !== '') {
                $template = $localized;
                $lang     = $reqLang;
            }
        }
        if ($phoneId === '' || $token === '' || $template === '') {
            return $this->skip('WhatsApp (Meta Cloud API) is not configured.', $to);
        }
        // Template body variables: {{1}} name, {{2}} company, {{3}} link.
        $payload = [
            'messaging_product' => 'whatsapp',
            'to'                => ltrim($to, '+'),
            'type'              => 'template',
            'template'          => [
                'name'       => $template,
                'language'   => ['code' => $lang],
                'components' => [[
                    'type'       => 'body',
                    'parameters' => [
                        ['type' => 'text', 'text' => (string) $req->customer_name],
                        ['type' => 'text', 'text' => (string) get_option('companyname')],
                        ['type' => 'text', 'text' => $link],
                    ],
                ]],
            ],
        ];
        $r = $this->http('https://graph.facebook.com/v19.0/' . rawurlencode($phoneId) . '/messages', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Content-Type: application/json'],
        ]);
        $j = json_decode($r['body'], true);
        if ($r['code'] >= 200 && $r['code'] < 300 && !empty($j['messages'][0]['id'])) {
            return ['status' => 'sent', 'recipient' => $to, 'id' => $j['messages'][0]['id'], 'error' => null];
        }
        $msg = isset($j['error']['message']) ? $j['error']['message'] : ($r['err'] ?: 'HTTP ' . $r['code']);
        return ['status' => 'failed', 'recipient' => $to, 'id' => null, 'error' => $msg];
    }

    /* ---------------------------------------------------------------- helpers */

    private function twilio($sid, $token, array $fields, $recipient)
    {
        $r = $this->http('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode($sid) . '/Messages.json', [
            CURLOPT_POST       => true,
            CURLOPT_POSTFIELDS => http_build_query($fields),
            CURLOPT_USERPWD    => $sid . ':' . $token,
        ]);
        $j = json_decode($r['body'], true);
        if ($r['code'] >= 200 && $r['code'] < 300 && !empty($j['sid'])) {
            return ['status' => 'sent', 'recipient' => $recipient, 'id' => $j['sid'], 'error' => null];
        }
        $msg = isset($j['message']) ? $j['message'] : ($r['err'] ?: 'HTTP ' . $r['code']);
        return ['status' => 'failed', 'recipient' => $recipient, 'id' => null, 'error' => $msg];
    }

    private function skip($reason, $recipient)
    {
        return ['status' => 'skipped', 'recipient' => $recipient ?: null, 'id' => null, 'error' => $reason];
    }

    /** Small cURL wrapper: TLS verification ON, hard timeouts, never throws. */
    private function http($url, array $opts)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, $opts + [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        $out  = ['code' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE), 'body' => (string) $body, 'err' => curl_error($ch)];
        curl_close($ch);
        return $out;
    }
}
