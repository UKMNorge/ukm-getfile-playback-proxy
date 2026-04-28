<?php
/* 
Plugin Name: UKM Getfile Playback Proxy
Plugin URI: http://www.ukm.no
Description: UKM Playback Proxy for hente og sende playback-filer med autentisering.
Author: UKM Norge / Kushtrim Aliu
Version: 1.0
Author URI: http://www.ukm.no
*/

defined('ABSPATH') || exit;

use UKMNorge\OAuth2\ArrSys\HandleAPICallWithAuthorization;
use UKMNorge\Filer\PlaybackFile;

define('UKM_PLAYBACK_PROXY_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');
define('UKM_PLAYBACK_SEND_PROXY_SECRET', 'CHANGE_THIS_TO_A_LONG_RANDOM_SECRET');

require_once('UKM/Autoloader.php');
require_once(__DIR__ . '/ukm-sendfile-proxy.php');

function ukm_getfile_add_rewrite_rule() {
    add_rewrite_rule(
        '^getplaybackfile/?$',
        'index.php?ukm_getfile=1',
        'top'
    );
}

add_action('init', 'ukm_getfile_add_rewrite_rule');

add_filter('query_vars', function ($vars) {
    $vars[] = 'ukm_getfile';
    return $vars;
});

register_activation_hook(__FILE__, function () {
    ukm_getfile_add_rewrite_rule();
    if (function_exists('ukm_sendfile_add_rewrite_rule')) {
        ukm_sendfile_add_rewrite_rule();
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

function ukm_base64url_encode($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ukm_create_playback_token(String $file_location, Int $season) {
    $secret = defined('UKM_PLAYBACK_PROXY_SECRET')
        ? UKM_PLAYBACK_PROXY_SECRET
        : getenv('UKM_PLAYBACK_PROXY_SECRET');

    if (empty($secret)) {
        throw new Exception('Missing playback proxy secret');
    }

    $payload = [
        'iss' => 'sys.ukm.no',
        'aud' => 'playback.ukm.no',
        'scope' => 'playback:file:read',
        'file_location' => (string) $file_location,
        'season' => (int) $season,
        'iat' => time(),
        'exp' => time() + 60,
    ];

    $payloadEncoded = ukm_base64url_encode(json_encode($payload));
    $signature = ukm_base64url_encode(
        hash_hmac('sha256', $payloadEncoded, $secret, true)
    );

    return $payloadEncoded . '.' . $signature;
}

add_action('template_redirect', function () {
    if (get_query_var('ukm_getfile') !== '1') {
        return;
    }

    $playbackId = null;
    try {
        $playbackId = HandleAPICallWithAuthorization::getArgumentBeforeInit('id', 'GET');
    } catch (Exception $e) {
        HandleAPICallWithAuthorization::sendError('Missing file id.', 400);
    }

    if (empty($playbackId)) {
        HandleAPICallWithAuthorization::sendError('Missing file id.', 400);
    }

    $playbackFile = null;
    try {
        $playbackFile = PlaybackFile::getById((int) $playbackId);
    } catch (Exception $e) {
        HandleAPICallWithAuthorization::sendError($e->getMessage(), 404);
    }

    $arrangementId = $playbackFile->getArrangementId();
    if (empty($arrangementId)) {
        HandleAPICallWithAuthorization::sendError('Playback file is not linked to an arrangement.', 400);
    }

    // Sjekker login WP user og tilgang til arrangementet i kommune/fylke
    $handleCall = new HandleAPICallWithAuthorization(
        ['id'],
        [],
        ['GET'],
        false,
        true,
        'arrangement_i_kommune_fylke',
        (string) $arrangementId
    );

    // $id = (int) $handleCall->getArgument('id');

    /**
     * TODO:
     * - Proxy actual file stream from playbackserver (authenticated as server)
     * - Set correct Content-Type + Content-Disposition for download/inline
     */
     
    
    $token = ukm_create_playback_token(
        $playbackFile->getFileLocation(),
        $playbackFile->getSeason(),
    );

     
    $url = 'https://playback.ukm.no/getFileAuth.php/';
    
    $ch = curl_init($url);

    $responseHeaders = [];

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
        $length = strlen($header);
        $header = trim($header);

        if ($header !== '' && strpos($header, ':') !== false) {
            [$name, $value] = explode(':', $header, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }

        return $length;
    });
     
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_SSL_VERIFYPEER => false, // TODO: remove this and use privatekey instead
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_USERAGENT => 'UKM Getfile Proxy/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: application/octet-stream',
            'X-UKM-Playback-Token: ' . $token,
        ],
    
        // Testing only:
        // CURLOPT_SSL_VERIFYPEER => false,
        // CURLOPT_SSL_VERIFYHOST => false,
    ]);
     
    $body = curl_exec($ch);
    
    if ($body === false) {
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $info = curl_getinfo($ch);
    
        curl_close($ch);
    
        error_log('Playback cURL errno: ' . $errno);
        error_log('Playback cURL error: ' . $err);
        error_log('Playback cURL info: ' . print_r($info, true));
    
        die('cURL failed: ' . $errno . ' - ' . $err);
    }
    
    $httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/octet-stream';
    
    curl_close($ch);
    
    if ($httpCode < 200 || $httpCode >= 300) {
        die('Playbackserver returned HTTP ' . $httpCode);
    }
    
    header('Content-Type: ' . $contentType);
    if (!empty($responseHeaders['content-disposition'])) {
        header('Content-Disposition: ' . $responseHeaders['content-disposition']);
    } else {
        header('Content-Disposition: attachment; filename="playback-file"');
    }

    header('Content-Length: ' . strlen($body));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    echo $body;
    exit;
});