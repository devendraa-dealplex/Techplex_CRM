<?php
defined('BASEPATH') or defined('PAYPLEX_TEST') or exit('No direct script access allowed');

/**
 * Languages for Video KYC: the script the customer reads aloud, the wording of
 * the email/SMS that carries the link, and the text of the customer page.
 *
 * Pure PHP with no framework dependency, so install.php, models, controllers and
 * the notifier all use the SAME strings. The staff dashboard mirrors render() and
 * formatDate() in JavaScript for the live preview; the server's output is the
 * authoritative copy that is stored on the request.
 *
 * Supported: en (default), hi (Hindi), mr (Marathi).
 *
 * NOTE: Hindi/Marathi wording should be reviewed by a native speaker before it is
 * relied on for compliance use — edit the script per language under
 * Video KYC → Settings & Templates.
 */
class Payplex_kyc_scripts
{
    const DEFAULT_LANG = 'en';

    /** code => label shown in the staff dropdown */
    public static function languages()
    {
        return [
            'en' => 'English',
            'hi' => 'Hindi (हिंदी)',
            'mr' => 'Marathi (मराठी)',
        ];
    }

    public static function isValid($code)
    {
        return is_string($code) && isset(self::languages()[$code]);
    }

    /** Unknown / empty → 'en'. */
    public static function normalize($code)
    {
        $code = strtolower(trim((string) $code));
        return self::isValid($code) ? $code : self::DEFAULT_LANG;
    }

    /* ---------------------------------------------------- reference scripts */

    /** The standard consent statement in each language. Placeholders: {customer_name} {company_name} {date}. */
    public static function defaultBodies()
    {
        return [
            'en' => 'My name is {customer_name}. I am completing my KYC verification for {company_name} on {date}. '
                  . 'I confirm that I am doing this of my own free will and that the documents and details '
                  . 'I have provided are true and correct.',
            'hi' => 'मेरा नाम {customer_name} है। मैं {date} को {company_name} के लिए अपना वीडियो केवाईसी सत्यापन पूरा कर रहा/रही हूँ। '
                  . 'मैं इसकी पुष्टि करता/करती हूँ कि मैं यह अपनी स्वेच्छा से कर रहा/रही हूँ और मेरे द्वारा प्रदान किए गए '
                  . 'दस्तावेज और विवरण पूरी तरह से सही और सत्य हैं।',
            'mr' => 'माझे नाव {customer_name} आहे. मी {date} रोजी {company_name} साठी माझे व्हिडिओ केवाईसी पडताळणी पूर्ण करत आहे. '
                  . 'मी याची पुष्टी करतो/करते की मी हे माझ्या स्वतःच्या इच्छेने करत आहे आणि मी दिलेली कागदपत्रे आणि '
                  . 'तपशील पूर्णपणे खरे आणि बरोबर आहेत.',
        ];
    }

    public static function defaultBody($lang)
    {
        $b = self::defaultBodies();
        return $b[self::normalize($lang)];
    }

    /**
     * The template text to use for a language. A template that has no version in
     * that language falls back to the standard statement for it — never to the
     * English text, because a customer must not be told to read English words
     * under a Hindi page.
     *
     * @param object|array $tpl row with body / body_hi / body_mr
     */
    public static function bodyFor($tpl, $lang)
    {
        $lang = self::normalize($lang);
        $t    = (array) $tpl;
        $col  = $lang === 'en' ? 'body' : 'body_' . $lang;
        $txt  = isset($t[$col]) ? trim((string) $t[$col]) : '';
        return $txt !== '' ? $txt : self::defaultBody($lang);
    }

    /** True when the template itself carries text for this language (used to show a notice in the UI). */
    public static function templateHas($tpl, $lang)
    {
        $lang = self::normalize($lang);
        $t    = (array) $tpl;
        $col  = $lang === 'en' ? 'body' : 'body_' . $lang;
        return isset($t[$col]) && trim((string) $t[$col]) !== '';
    }

    /* ---------------------------------------------------------------- dates */

    public static function months()
    {
        return [
            'en' => ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'],
            'hi' => ['जनवरी', 'फ़रवरी', 'मार्च', 'अप्रैल', 'मई', 'जून', 'जुलाई', 'अगस्त', 'सितंबर', 'अक्टूबर', 'नवंबर', 'दिसंबर'],
            'mr' => ['जानेवारी', 'फेब्रुवारी', 'मार्च', 'एप्रिल', 'मे', 'जून', 'जुलै', 'ऑगस्ट', 'सप्टेंबर', 'ऑक्टोबर', 'नोव्हेंबर', 'डिसेंबर'],
        ];
    }

    /** "19 September 2026" / "19 सितंबर 2026" / "19 सप्टेंबर 2026" — Western digits, easy to read aloud. */
    public static function formatDate($lang, $ts = null)
    {
        $ts = $ts === null ? time() : (int) $ts;
        $m  = self::months()[self::normalize($lang)];
        return (int) date('j', $ts) . ' ' . $m[(int) date('n', $ts) - 1] . ' ' . date('Y', $ts);
    }

    public static function formatDateTime($lang, $ts)
    {
        return self::formatDate($lang, $ts) . ', ' . date('h:i A', (int) $ts);
    }

    /* --------------------------------------------------------------- render */

    /** Fill {customer_name}, {company_name} (also {company}) and {date}. */
    public static function render($body, $customerName, $lang, $company = '', $ts = null)
    {
        $company = (string) $company;
        return strtr((string) $body, [
            '{customer_name}' => $customerName,
            '{company_name}'  => $company,
            '{company}'       => $company,
            '{date}'          => self::formatDate($lang, $ts),
        ]);
    }

    /* ------------------------------------------- email / SMS wording (per lang) */

    public static function messages($lang)
    {
        $all = [
            'en' => [
                'email_subject' => 'Complete your video KYC — {company}',
                'email_hello'   => 'Hello {name},',
                'email_body'    => '{company} needs to verify your identity. It takes about a minute: you\'ll record a short video of yourself reading a sentence on your phone or computer.',
                'email_button'  => 'Start Video KYC',
                'email_note'    => 'This link is personal to you and valid until {expiry}. Please don\'t forward it.',
                'sms'           => 'Hi {customer_name}, please complete your video KYC for {company}: {link} (valid till {expiry}).',
            ],
            'hi' => [
                'email_subject' => 'अपना वीडियो केवाईसी पूरा करें — {company}',
                'email_hello'   => 'नमस्ते {name},',
                'email_body'    => '{company} को आपकी पहचान सत्यापित करनी है। इसमें लगभग एक मिनट लगेगा: आपको अपने फ़ोन या कंप्यूटर पर एक वाक्य पढ़ते हुए अपना छोटा-सा वीडियो रिकॉर्ड करना होगा।',
                'email_button'  => 'वीडियो केवाईसी शुरू करें',
                'email_note'    => 'यह लिंक केवल आपके लिए है और {expiry} तक मान्य है। कृपया इसे किसी को फ़ॉरवर्ड न करें।',
                'sms'           => 'नमस्ते {customer_name}, कृपया {company} के लिए अपना वीडियो केवाईसी पूरा करें: {link} ({expiry} तक मान्य)।',
            ],
            'mr' => [
                'email_subject' => 'तुमचे व्हिडिओ केवाईसी पूर्ण करा — {company}',
                'email_hello'   => 'नमस्कार {name},',
                'email_body'    => '{company} ला तुमची ओळख पडताळायची आहे. यासाठी सुमारे एक मिनिट लागेल: तुम्हाला तुमच्या फोनवर किंवा संगणकावर एक वाक्य वाचतानाचा तुमचा छोटा व्हिडिओ रेकॉर्ड करायचा आहे.',
                'email_button'  => 'व्हिडिओ केवाईसी सुरू करा',
                'email_note'    => 'ही लिंक फक्त तुमच्यासाठी आहे आणि {expiry} पर्यंत वैध आहे. कृपया ती कोणालाही फॉरवर्ड करू नका.',
                'sms'           => 'नमस्कार {customer_name}, कृपया {company} साठी तुमचे व्हिडिओ केवाईसी पूर्ण करा: {link} ({expiry} पर्यंत वैध).',
            ],
        ];
        return $all[self::normalize($lang)];
    }

    /* -------------------------------------------------- customer page strings */

    /**
     * Every string on the customer capture page, all languages. Placeholders in
     * braces ({name}, {company}, {min}, {max}) are filled in by the page's JS.
     */
    public static function ui()
    {
        return [
            'en' => [
                'checking'   => 'Checking your link…',
                'hello'      => 'Hello, {name}',
                'intro'      => '{company} needs to verify your identity. It takes about a minute.',
                'step1'      => 'Allow camera and microphone access.',
                'step2'      => 'Sit somewhere well lit, face the camera, and keep your face fully visible.',
                'step3'      => 'Read the sentence shown on screen out loud, clearly.',
                'step4'      => 'Review your video, then submit it.',
                'privacy'    => 'Your recording is private, encrypted in transit and only seen by authorised staff for identity verification.',
                'allow'      => 'Allow camera & microphone',
                'read_aloud' => 'Please read this aloud:',
                'rec_hint'   => 'Record between {min} and {max} seconds.',
                'start_rec'  => 'Start recording',
                'stop_rec'   => 'Stop recording',
                'review'     => 'Check that your face is visible and your voice is clear. Not happy? Record again.',
                'retake'     => 'Record again',
                'submit'     => 'Submit video',
                'done_title' => 'Submitted',
                'done_text'  => 'Thank you. Your video has been received and will be reviewed shortly. You can close this page.',
                'back_home'  => 'Back to home',
                'err_invalid_t'  => 'Link not valid',
                'err_invalid'    => 'This link is not valid. Please check that you opened the full link you were sent, or ask for a new one.',
                'err_expired_t'  => 'Link expired',
                'err_expired'    => 'This link has expired. Please contact the company and ask them to send you a new one.',
                'err_already_submitted_t' => 'Already submitted',
                'err_already_submitted'   => 'A video has already been submitted with this link. You do not need to do anything else.',
                'err_rejected_t' => 'Please request a new link',
                'err_rejected'   => 'Your previous submission could not be accepted. Please contact the company for a new link.',
                'err_attempts_exhausted_t' => 'No attempts left',
                'err_attempts_exhausted'   => 'The maximum number of attempts for this link has been used. Please contact the company for a new link.',
                'err_unsupported_t' => 'Browser not supported',
                'err_unsupported'   => 'This browser cannot record video here. Please open the link in the latest Chrome, Safari or Firefox, and make sure the address begins with https://. If you opened it inside another app (WhatsApp, Instagram, etc.), use "Open in browser".',
                'err_network_t' => 'Connection problem',
                'err_network'   => 'We could not reach the server. Please check your internet connection and reload this page.',
                'perm_denied'   => 'Permission was denied. Tap the camera icon in your browser\'s address bar, allow camera and microphone for this page, then try again.',
                'perm_notfound' => 'No camera or microphone was found on this device.',
                'perm_busy'     => 'Your camera or microphone is being used by another app. Close it and try again.',
                'perm_generic'  => 'We could not access your camera and microphone.',
                'nothing_recorded' => 'Nothing was recorded. Please try again.',
                'too_large'     => 'This video is too large to upload. Please record again and keep it shorter.',
                'upload_blocked' => 'The upload was blocked. Please reload this page and try again.',
                'upload_network' => 'Network problem. Check your connection and tap Submit again — your recording is still here.',
                'err_not_video' => 'That file is not a supported video. Please record again.',
                'err_no_file'   => 'No video was received. Please try again.',
                'err_store'     => 'We could not save your video. Please try again shortly.',
            ],
            'hi' => [
                'checking'   => 'आपका लिंक जाँचा जा रहा है…',
                'hello'      => 'नमस्ते, {name}',
                'intro'      => '{company} को आपकी पहचान सत्यापित करनी है। इसमें लगभग एक मिनट लगेगा।',
                'step1'      => 'कैमरा और माइक्रोफ़ोन की अनुमति दें।',
                'step2'      => 'ऐसी जगह बैठें जहाँ अच्छी रोशनी हो, कैमरे की ओर देखें और अपना पूरा चेहरा दिखाई देने दें।',
                'step3'      => 'स्क्रीन पर दिखाया गया वाक्य साफ़ आवाज़ में ज़ोर से पढ़ें।',
                'step4'      => 'अपना वीडियो देखें, फिर उसे जमा करें।',
                'privacy'    => 'आपकी रिकॉर्डिंग निजी है, ट्रांसमिशन के दौरान एन्क्रिप्टेड रहती है और केवल पहचान सत्यापन के लिए अधिकृत कर्मचारी ही इसे देखते हैं।',
                'allow'      => 'कैमरा और माइक्रोफ़ोन की अनुमति दें',
                'read_aloud' => 'कृपया इसे ज़ोर से पढ़ें:',
                'rec_hint'   => '{min} से {max} सेकंड के बीच रिकॉर्ड करें।',
                'start_rec'  => 'रिकॉर्डिंग शुरू करें',
                'stop_rec'   => 'रिकॉर्डिंग रोकें',
                'review'     => 'जाँच लें कि आपका चेहरा दिख रहा है और आवाज़ साफ़ है। संतुष्ट नहीं हैं? दोबारा रिकॉर्ड करें।',
                'retake'     => 'दोबारा रिकॉर्ड करें',
                'submit'     => 'वीडियो जमा करें',
                'done_title' => 'जमा हो गया',
                'done_text'  => 'धन्यवाद। आपका वीडियो प्राप्त हो गया है और जल्द ही उसकी समीक्षा की जाएगी। आप यह पेज बंद कर सकते हैं।',
                'back_home'  => 'होम पर वापस जाएँ',
                'err_invalid_t'  => 'लिंक मान्य नहीं है',
                'err_invalid'    => 'यह लिंक मान्य नहीं है। कृपया जाँच लें कि आपने भेजा गया पूरा लिंक खोला है, या नया लिंक माँगें।',
                'err_expired_t'  => 'लिंक की समय-सीमा समाप्त',
                'err_expired'    => 'इस लिंक की समय-सीमा समाप्त हो चुकी है। कृपया कंपनी से संपर्क करें और नया लिंक भेजने के लिए कहें।',
                'err_already_submitted_t' => 'पहले ही जमा किया जा चुका है',
                'err_already_submitted'   => 'इस लिंक से वीडियो पहले ही जमा किया जा चुका है। आपको और कुछ करने की आवश्यकता नहीं है।',
                'err_rejected_t' => 'कृपया नया लिंक माँगें',
                'err_rejected'   => 'आपका पिछला सबमिशन स्वीकार नहीं किया जा सका। कृपया नए लिंक के लिए कंपनी से संपर्क करें।',
                'err_attempts_exhausted_t' => 'कोई प्रयास शेष नहीं',
                'err_attempts_exhausted'   => 'इस लिंक के लिए अनुमत प्रयासों की अधिकतम संख्या पूरी हो चुकी है। कृपया नए लिंक के लिए कंपनी से संपर्क करें।',
                'err_unsupported_t' => 'ब्राउज़र समर्थित नहीं है',
                'err_unsupported'   => 'यह ब्राउज़र यहाँ वीडियो रिकॉर्ड नहीं कर सकता। कृपया लिंक को Chrome, Safari या Firefox के नवीनतम संस्करण में खोलें और सुनिश्चित करें कि पता https:// से शुरू होता है। यदि आपने इसे किसी दूसरे ऐप (WhatsApp, Instagram आदि) में खोला है, तो "ब्राउज़र में खोलें" चुनें।',
                'err_network_t' => 'कनेक्शन में समस्या',
                'err_network'   => 'हम सर्वर तक नहीं पहुँच सके। कृपया अपना इंटरनेट कनेक्शन जाँचें और यह पेज दोबारा लोड करें।',
                'perm_denied'   => 'अनुमति अस्वीकृत की गई। ब्राउज़र के एड्रेस बार में कैमरा आइकन दबाएँ, इस पेज के लिए कैमरा और माइक्रोफ़ोन की अनुमति दें, फिर दोबारा प्रयास करें।',
                'perm_notfound' => 'इस डिवाइस पर कोई कैमरा या माइक्रोफ़ोन नहीं मिला।',
                'perm_busy'     => 'आपका कैमरा या माइक्रोफ़ोन किसी दूसरे ऐप में उपयोग हो रहा है। उसे बंद करके दोबारा प्रयास करें।',
                'perm_generic'  => 'हम आपके कैमरा और माइक्रोफ़ोन तक नहीं पहुँच सके।',
                'nothing_recorded' => 'कुछ भी रिकॉर्ड नहीं हुआ। कृपया दोबारा प्रयास करें।',
                'too_large'     => 'यह वीडियो अपलोड करने के लिए बहुत बड़ा है। कृपया दोबारा रिकॉर्ड करें और उसे छोटा रखें।',
                'upload_blocked' => 'अपलोड रोक दिया गया। कृपया यह पेज रीलोड करें और दोबारा प्रयास करें।',
                'upload_network' => 'नेटवर्क में समस्या। अपना कनेक्शन जाँचें और दोबारा "वीडियो जमा करें" दबाएँ — आपकी रिकॉर्डिंग यहीं सुरक्षित है।',
                'err_not_video' => 'यह फ़ाइल समर्थित वीडियो नहीं है। कृपया दोबारा रिकॉर्ड करें।',
                'err_no_file'   => 'कोई वीडियो प्राप्त नहीं हुआ। कृपया दोबारा प्रयास करें।',
                'err_store'     => 'हम आपका वीडियो सहेज नहीं सके। कृपया थोड़ी देर बाद दोबारा प्रयास करें।',
            ],
            'mr' => [
                'checking'   => 'तुमची लिंक तपासली जात आहे…',
                'hello'      => 'नमस्कार, {name}',
                'intro'      => '{company} ला तुमची ओळख पडताळायची आहे. यासाठी सुमारे एक मिनिट लागेल.',
                'step1'      => 'कॅमेरा आणि मायक्रोफोनची परवानगी द्या.',
                'step2'      => 'चांगला प्रकाश असलेल्या ठिकाणी बसा, कॅमेऱ्याकडे पाहा आणि तुमचा संपूर्ण चेहरा दिसू द्या.',
                'step3'      => 'स्क्रीनवर दाखवलेले वाक्य स्पष्ट आवाजात मोठ्याने वाचा.',
                'step4'      => 'तुमचा व्हिडिओ पाहा, मग तो सबमिट करा.',
                'privacy'    => 'तुमचे रेकॉर्डिंग खाजगी आहे, प्रसारणादरम्यान एन्क्रिप्टेड असते आणि ओळख पडताळणीसाठी फक्त अधिकृत कर्मचारीच ते पाहतात.',
                'allow'      => 'कॅमेरा आणि मायक्रोफोनची परवानगी द्या',
                'read_aloud' => 'कृपया हे मोठ्याने वाचा:',
                'rec_hint'   => '{min} ते {max} सेकंदांदरम्यान रेकॉर्ड करा.',
                'start_rec'  => 'रेकॉर्डिंग सुरू करा',
                'stop_rec'   => 'रेकॉर्डिंग थांबवा',
                'review'     => 'तुमचा चेहरा दिसत आहे आणि आवाज स्पष्ट आहे हे तपासा. समाधान नाही? पुन्हा रेकॉर्ड करा.',
                'retake'     => 'पुन्हा रेकॉर्ड करा',
                'submit'     => 'व्हिडिओ सबमिट करा',
                'done_title' => 'सबमिट झाले',
                'done_text'  => 'धन्यवाद. तुमचा व्हिडिओ मिळाला आहे आणि लवकरच त्याची तपासणी केली जाईल. तुम्ही हे पेज बंद करू शकता.',
                'back_home'  => 'मुख्यपृष्ठावर परत जा',
                'err_invalid_t'  => 'लिंक वैध नाही',
                'err_invalid'    => 'ही लिंक वैध नाही. कृपया तुम्हाला पाठवलेली संपूर्ण लिंक उघडली आहे का ते तपासा, किंवा नवीन लिंक मागा.',
                'err_expired_t'  => 'लिंकची मुदत संपली',
                'err_expired'    => 'या लिंकची मुदत संपली आहे. कृपया कंपनीशी संपर्क साधा आणि नवीन लिंक पाठवण्यास सांगा.',
                'err_already_submitted_t' => 'आधीच सबमिट केले आहे',
                'err_already_submitted'   => 'या लिंकवरून व्हिडिओ आधीच सबमिट केला आहे. तुम्हाला आणखी काही करण्याची गरज नाही.',
                'err_rejected_t' => 'कृपया नवीन लिंक मागा',
                'err_rejected'   => 'तुमचे मागील सबमिशन स्वीकारले जाऊ शकले नाही. कृपया नवीन लिंकसाठी कंपनीशी संपर्क साधा.',
                'err_attempts_exhausted_t' => 'प्रयत्न शिल्लक नाहीत',
                'err_attempts_exhausted'   => 'या लिंकसाठी परवानगी असलेले जास्तीत जास्त प्रयत्न वापरले गेले आहेत. कृपया नवीन लिंकसाठी कंपनीशी संपर्क साधा.',
                'err_unsupported_t' => 'ब्राउझर समर्थित नाही',
                'err_unsupported'   => 'हा ब्राउझर येथे व्हिडिओ रेकॉर्ड करू शकत नाही. कृपया लिंक Chrome, Safari किंवा Firefox च्या नवीनतम आवृत्तीत उघडा आणि पत्ता https:// ने सुरू होतो याची खात्री करा. जर तुम्ही ती दुसऱ्या अ‍ॅपमध्ये (WhatsApp, Instagram इ.) उघडली असेल, तर "ब्राउझरमध्ये उघडा" निवडा.',
                'err_network_t' => 'कनेक्शनमध्ये अडचण',
                'err_network'   => 'आम्ही सर्व्हरशी संपर्क साधू शकलो नाही. कृपया तुमचे इंटरनेट कनेक्शन तपासा आणि हे पेज पुन्हा लोड करा.',
                'perm_denied'   => 'परवानगी नाकारली गेली. ब्राउझरच्या अ‍ॅड्रेस बारमधील कॅमेरा आयकॉन दाबा, या पेजसाठी कॅमेरा आणि मायक्रोफोनची परवानगी द्या, मग पुन्हा प्रयत्न करा.',
                'perm_notfound' => 'या डिव्हाइसवर कॅमेरा किंवा मायक्रोफोन आढळला नाही.',
                'perm_busy'     => 'तुमचा कॅमेरा किंवा मायक्रोफोन दुसऱ्या अ‍ॅपमध्ये वापरला जात आहे. ते बंद करून पुन्हा प्रयत्न करा.',
                'perm_generic'  => 'आम्ही तुमच्या कॅमेरा आणि मायक्रोफोनपर्यंत पोहोचू शकलो नाही.',
                'nothing_recorded' => 'काहीही रेकॉर्ड झाले नाही. कृपया पुन्हा प्रयत्न करा.',
                'too_large'     => 'हा व्हिडिओ अपलोड करण्यासाठी खूप मोठा आहे. कृपया पुन्हा रेकॉर्ड करा आणि तो लहान ठेवा.',
                'upload_blocked' => 'अपलोड रोखले गेले. कृपया हे पेज रीलोड करा आणि पुन्हा प्रयत्न करा.',
                'upload_network' => 'नेटवर्कमध्ये अडचण. तुमचे कनेक्शन तपासा आणि पुन्हा "व्हिडिओ सबमिट करा" दाबा — तुमचे रेकॉर्डिंग येथेच आहे.',
                'err_not_video' => 'ही फाइल समर्थित व्हिडिओ नाही. कृपया पुन्हा रेकॉर्ड करा.',
                'err_no_file'   => 'कोणताही व्हिडिओ मिळाला नाही. कृपया पुन्हा प्रयत्न करा.',
                'err_store'     => 'आम्ही तुमचा व्हिडिओ सेव्ह करू शकलो नाही. कृपया थोड्या वेळाने पुन्हा प्रयत्न करा.',
            ],
        ];
    }
}
