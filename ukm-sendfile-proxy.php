<?php

defined('ABSPATH') || exit;

use UKMNorge\OAuth2\ArrSys\HandleAPICallWithAuthorization;



function ukm_sendfile_add_rewrite_rule() {
	add_rewrite_rule(
		'^sendplaybackfile/?$',
		'index.php?ukm_sendfile=1',
		'top'
	);
}

add_action('init', 'ukm_sendfile_add_rewrite_rule');

add_filter('query_vars', function ($vars) {
	$vars[] = 'ukm_sendfile';
	return $vars;
});

function ukm_sendfile_base64url_encode($data) {
	return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function ukm_create_playback_send_token($plId, $fileId, $arrangementId, $userId) {
	$secret = defined('UKM_PLAYBACK_SEND_PROXY_SECRET')
		? UKM_PLAYBACK_SEND_PROXY_SECRET
		: getenv('UKM_PLAYBACK_SEND_PROXY_SECRET');

	if (empty($secret)) {
		throw new Exception('Missing playback send proxy secret');
	}

	$payload = [
		'iss' => 'sys.ukm.no',
		'aud' => 'playback.ukm.no',
		'scope' => 'playback:file:write',
		'pl_id' => (int) $plId,
		'file_id' => (int) $fileId,
		'arrangement_id' => (int) $arrangementId,
		'user_id' => (int) $userId,
		'iat' => time(),
		'exp' => time() + 60,
	];

	$payloadEncoded = ukm_sendfile_base64url_encode(json_encode($payload));
	$signature = ukm_sendfile_base64url_encode(
		hash_hmac('sha256', $payloadEncoded, $secret, true)
	);

	return $payloadEncoded . '.' . $signature;
}

add_action('template_redirect', function () {
	if (get_query_var('ukm_sendfile') !== '1') {
		return;
	}

	$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
	if ($origin !== '') {
		header('Access-Control-Allow-Origin: ' . $origin);
		header('Vary: Origin');
	}
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Methods: POST, PUT, OPTIONS');
	$requestedHeaders = $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? 'Content-Type, X-Requested-With, Authorization';
	header('Access-Control-Allow-Headers: ' . $requestedHeaders);
	header('Access-Control-Max-Age: 86400');

	if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
		status_header(204);
		exit;
	}

	if (!in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT'], true)) {
		HandleAPICallWithAuthorization::sendError('Use POST or PUT for file upload.', 405);
	}

	$handleCall = new HandleAPICallWithAuthorization(
		['id', 'arrangement_id'],
		[],
		['POST', 'PUT'],
		false,
		true,
		'arrangement_i_kommune_fylke',
		(string) ($_REQUEST['arrangement_id'] ?? '')
	);

	$fileId = (int) $handleCall->getArgument('id');
	$arrangementId = (int) $handleCall->getArgument('arrangement_id');
	$plId = isset($_REQUEST['pl_id']) ? (int) $_REQUEST['pl_id'] : -1;

	$token = ukm_create_playback_send_token(
		$plId,
		$fileId,
		$arrangementId,
		get_current_user_id()
	);

	// Basic default endpoint; can be changed if playback exposes another upload path.
	$url = 'https://playback.ukm.no/upload/uploadFileAuth.php/' . $plId . '/' . $fileId . '/';

	$in = fopen('php://input', 'rb');
	if ($in === false) {
		HandleAPICallWithAuthorization::sendError('Unable to open input stream.', 500);
	}

	$responseHeaders = [];
	$ch = curl_init($url);

	curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) use (&$responseHeaders) {
		$length = strlen($header);
		$header = trim($header);

		if ($header !== '' && strpos($header, ':') !== false) {
			[$name, $value] = explode(':', $header, 2);
			$responseHeaders[strtolower(trim($name))] = trim($value);
		}

		return $length;
	});

	$contentType = $_SERVER['CONTENT_TYPE'] ?? 'application/octet-stream';
	$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : null;

	$curlOptions = [
		CURLOPT_UPLOAD => true,
		CURLOPT_INFILE => $in,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS => 5,
		CURLOPT_CONNECTTIMEOUT => 10,
		CURLOPT_TIMEOUT => 0,
		CURLOPT_SSL_VERIFYPEER => false, // TODO: remove this and use private key / CA trust
		CURLOPT_FAILONERROR => false,
		CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
		CURLOPT_USERAGENT => 'UKM Sendfile Proxy/0.1',
		CURLOPT_CUSTOMREQUEST => $_SERVER['REQUEST_METHOD'],
		CURLOPT_HTTPHEADER => [
			'Content-Type: ' . $contentType,
			'Accept: application/json, text/plain, */*',
			'X-UKM-Playback-Token: ' . $token,
		],
	];

	if ($contentLength !== null && $contentLength >= 0) {
		$curlOptions[CURLOPT_INFILESIZE] = $contentLength;
	}

	curl_setopt_array($ch, $curlOptions);
	$responseBody = curl_exec($ch);

    var_dump($responseBody);
    die;

	if ($responseBody === false) {
		$errno = curl_errno($ch);
		$err = curl_error($ch);
		curl_close($ch);
		fclose($in);

		error_log('Playback send cURL errno: ' . $errno);
		error_log('Playback send cURL error: ' . $err);

		HandleAPICallWithAuthorization::sendError('Upload proxy failed: ' . $err, 502);
	}

	$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	$responseContentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: 'application/json';

	curl_close($ch);
	fclose($in);

	status_header($httpCode > 0 ? $httpCode : 502);
	header('Content-Type: ' . $responseContentType);

	if (!empty($responseHeaders['content-disposition'])) {
		header('Content-Disposition: ' . $responseHeaders['content-disposition']);
	}

	echo $responseBody;
	exit;
});
