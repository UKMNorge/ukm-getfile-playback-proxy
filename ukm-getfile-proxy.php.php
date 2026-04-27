<?php
/* 
Plugin Name: UKM Getfile Playback Proxy
Plugin URI: http://www.ukm.no
Description: UKM Getfile Playback Proxy for å hente playback-filer med autentisering. Bruk sys.ukm.no/getplaybackfile?id=123
Author: UKM Norge / Kushtrim Aliu
Version: 1.0
Author URI: http://www.ukm.no
*/

defined('ABSPATH') || exit;

use UKMNorge\OAuth2\ArrSys\HandleAPICallWithAuthorization;
use UKMNorge\Filer\PlaybackFile;

require_once('UKM/Autoloader.php');

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
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

add_action('template_redirect', function () {
    if (get_query_var('ukm_getfile') !== '1') {
        return;
    }

    header('Content-Type: application/json; charset=utf-8');

    $fileId = null;
    try {
        $fileId = HandleAPICallWithAuthorization::getArgumentBeforeInit('id', 'GET');
    } catch (Exception $e) {
        HandleAPICallWithAuthorization::sendError('Missing file id.', 400);
    }

    if (empty($fileId)) {
        HandleAPICallWithAuthorization::sendError('Missing file id.', 400);
    }

    $playbackFile = null;
    try {
        $playbackFile = PlaybackFile::getById((int) $fileId);
    } catch (Exception $e) {
        HandleAPICallWithAuthorization::sendError($e->getMessage(), 404);
    }

    $arrangementId = $playbackFile->getArrangementId();
    if (empty($arrangementId)) {
        HandleAPICallWithAuthorization::sendError('Playback file is not linked to an arrangement.', 400);
    }

    $handleCall = new HandleAPICallWithAuthorization(
        ['id'],
        [],
        ['GET'],
        false,
        true,
        'arrangement_i_kommune_fylke',
        (string) $arrangementId
    );

    $id = (int) $handleCall->getArgument('id');

    /**
     * TODO:
     * - Proxy actual file stream from playbackserver (authenticated as server)
     * - Set correct Content-Type + Content-Disposition for download/inline
     */
    $handleCall->sendToClient([
        'success' => true,
        'id' => $id,
        'arrangementId' => (int) $arrangementId,
    ]);
});