<?php defined('BASEPATH') or exit('No direct script access allowed');

/**
 * HTTP client for the ABDM bridge (/api/hms/*). Every call carries the hospital's API key and the
 * acting staff member, so the bridge's audit trail shows who did what.
 */
class Abdm_client
{
    private $CI;
    private $base;
    private $key;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->config->load('abdm', true);
        $this->base = rtrim((string) $this->CI->config->item('abdm_bridge_url', 'abdm'), '/');
        $this->key  = (string) $this->CI->config->item('abdm_api_key', 'abdm');
    }

    public function configured()
    {
        return $this->base !== '' && strpos($this->key, 'abdm_') === 0;
    }

    public function get($path)
    {
        return $this->request('GET', $path);
    }

    public function post($path, $body = array())
    {
        return $this->request('POST', $path, $body);
    }

    /** Binary responses (ABHA card / QR image). Returns [content_type, bytes]. */
    public function post_binary($path, $body)
    {
        return $this->request('POST', $path, $body, true);
    }

    private function acting_user()
    {
        if (is_cli() || !isset($this->CI->session)) {
            return 'cron';
        }
        $admin = $this->CI->session->userdata('hospitaladmin');
        if (!$admin) {
            return 'system';
        }
        $name = isset($admin['username']) ? $admin['username'] : '';
        return 'staff#' . $admin['id'] . ($name !== '' ? ' ' . $name : '');
    }

    /**
     * @return array decoded JSON
     * @throws Exception with the bridge's error message
     */
    private function request($method, $path, $body = null, $binary = false)
    {
        if (!$this->configured()) {
            throw new Exception('ABDM bridge is not configured (application/config/abdm.php).');
        }
        $ch = curl_init($this->base . '/api/hms' . $path);
        $headers = array(
            'Authorization: Bearer ' . $this->key,
            'Accept: application/json',
            'X-Acting-User: ' . preg_replace('/[^\w#@. -]/', '', $this->acting_user()),
        );
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            // An empty PHP array must go out as a JSON object, not [].
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body === array() ? '{}' : json_encode($body));
        }
        curl_setopt_array($ch, array(
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HEADER         => true,
        ));
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new Exception('Cannot reach ABDM bridge: ' . $err);
        }
        $status      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $ctype       = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $payload = substr($raw, $header_size);

        if ($status >= 200 && $status < 300) {
            if ($binary) {
                return array($ctype, $payload);
            }
            return $payload === '' ? array() : json_decode($payload, true);
        }
        $data = json_decode($payload, true);
        $msg  = is_array($data) && isset($data['error']) ? $data['error'] : ('ABDM bridge error (HTTP ' . $status . ')');
        if (is_array($data) && !empty($data['details']) && is_array($data['details'])) {
            $parts = array();
            foreach ($data['details'] as $d) {
                if (isset($d['field'], $d['message'])) {
                    $parts[] = $d['field'] . ': ' . $d['message'];
                }
            }
            if ($parts) {
                $msg .= ' (' . implode('; ', $parts) . ')';
            }
        }
        throw new Exception($msg, $status);
    }
}
