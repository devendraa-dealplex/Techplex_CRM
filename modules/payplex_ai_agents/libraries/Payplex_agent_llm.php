<?php

defined('BASEPATH') or defined('PAYPLEX_AI_TEST') or exit('No direct script access allowed');

/**
 * Payplex_agent_llm
 *
 * Minimal OpenRouter chat-completions client. Dependency-free (plain curl) so it
 * can be exercised outside CodeIgniter. Never logs or returns the API key.
 */
class Payplex_agent_llm
{
    const ENDPOINT      = 'https://openrouter.ai/api/v1/chat/completions';
    const DEFAULT_MODEL = 'openrouter/free'; // OpenRouter's router over currently-available free models

    /** API key: OPENROUTER_API_KEY env var wins, otherwise the stored setting. */
    public static function resolveKey($settingValue = '')
    {
        $env = getenv('OPENROUTER_API_KEY');
        $key = ($env !== false && trim($env) !== '') ? $env : (string) $settingValue;
        return trim($key);
    }

    /**
     * @param string $apiKey
     * @param array  $messages [['role'=>'system|user|assistant','content'=>'...'], ...]
     * @param array  $opts     model, max_tokens, temperature, timeout
     * @return array ['ok'=>bool, 'content'=>string, 'model'=>string, 'prompt_tokens'=>int,
     *                'completion_tokens'=>int, 'tokens'=>int, 'cost'=>float, 'error'=>string]
     */
    public static function chat($apiKey, array $messages, array $opts = array())
    {
        $fail = function ($error) {
            return array('ok' => false, 'content' => '', 'model' => '', 'prompt_tokens' => 0,
                'completion_tokens' => 0, 'tokens' => 0, 'cost' => 0.0, 'error' => $error);
        };

        if ($apiKey === '') {
            return $fail('no_api_key');
        }
        if (!function_exists('curl_init')) {
            return $fail('curl_not_available');
        }

        $model = !empty($opts['model']) ? (string) $opts['model'] : self::DEFAULT_MODEL;
        $payload = array(
            'model'       => $model,
            'messages'    => $messages,
            // Reasoning models spend part of this budget "thinking"; too small a
            // value yields an empty answer.
            'max_tokens'  => isset($opts['max_tokens']) ? (int) $opts['max_tokens'] : 1200,
            'temperature' => isset($opts['temperature']) ? (float) $opts['temperature'] : 0.2,
        );

        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => isset($opts['timeout']) ? (int) $opts['timeout'] : 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'X-Title: Payplex AI Agents',
            ),
            CURLOPT_POSTFIELDS     => json_encode($payload),
        ));
        $raw  = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $cerr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            return $fail('network_error: ' . $cerr);
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) {
            return $fail('bad_response (http ' . $http . ')');
        }
        if (isset($j['error'])) {
            $msg = isset($j['error']['message']) ? (string) $j['error']['message'] : 'unknown';
            return $fail('provider_error (' . (isset($j['error']['code']) ? $j['error']['code'] : $http) . '): ' . substr($msg, 0, 200));
        }

        $content = isset($j['choices'][0]['message']['content']) ? trim((string) $j['choices'][0]['message']['content']) : '';
        if ($content === '') {
            return $fail('empty_response (finish_reason: ' . (isset($j['choices'][0]['finish_reason']) ? $j['choices'][0]['finish_reason'] : 'n/a') . ')');
        }

        $u = isset($j['usage']) ? $j['usage'] : array();
        return array(
            'ok'                => true,
            'content'           => $content,
            'model'             => isset($j['model']) ? (string) $j['model'] : $model,
            'prompt_tokens'     => (int) (isset($u['prompt_tokens']) ? $u['prompt_tokens'] : 0),
            'completion_tokens' => (int) (isset($u['completion_tokens']) ? $u['completion_tokens'] : 0),
            'tokens'            => (int) (isset($u['total_tokens']) ? $u['total_tokens'] : 0),
            'cost'              => (float) (isset($u['cost']) ? $u['cost'] : 0),
            'error'             => '',
        );
    }
}
