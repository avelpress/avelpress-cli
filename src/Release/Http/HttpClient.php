<?php

namespace AvelPress\Cli\Release\Http;

/**
 * The one place that talks to the network.
 *
 * Redirects are never followed: requests carry credentials, and a redirect would
 * hand them to whatever host answers next. In practice a 3xx here always means a
 * misconfigured URL, which the caller reports as such.
 */
class HttpClient {

	/**
	 * Performs a request.
	 *
	 * @param string            $method   HTTP method.
	 * @param string            $url      Absolute URL.
	 * @param string|array|null $body     Raw request body, or an array of fields
	 *                                    to send as multipart/form-data.
	 * @param array             $headers  Request headers.
	 * @param int               $timeout  Timeout in seconds.
	 * @param string|null       $basicAuth "user:password" for HTTP Basic, when needed.
	 * @return array{status: int, headers: array, body: string}
	 * @throws \RuntimeException On transport errors.
	 */
	public function request(
		string $method,
		string $url,
		$body = null,
		array $headers = [],
		int $timeout = 30,
		$basicAuth = null
	): array {
		$responseHeaders = [];
		$handle = curl_init();

		$options = [
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => $method,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => false,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_HTTPHEADER => $headers,
			CURLOPT_USERAGENT => 'avelpress-cli',
			CURLOPT_HEADERFUNCTION => function ($handle, $header) use (&$responseHeaders) {
				$parts = explode( ':', $header, 2 );

				if ( count( $parts ) === 2 ) {
					$responseHeaders[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}

				return strlen( $header );
			},
		];

		if ( $basicAuth !== null ) {
			$options[ CURLOPT_USERPWD ] = $basicAuth;
			$options[ CURLOPT_HTTPAUTH ] = CURLAUTH_BASIC;
		}

		if ( $body !== null ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}

		curl_setopt_array( $handle, $options );

		$raw = curl_exec( $handle );
		$status = (int) curl_getinfo( $handle, CURLINFO_HTTP_CODE );
		$error = curl_error( $handle );

		curl_close( $handle );

		if ( $raw === false ) {
			throw new \RuntimeException( "Request to $url failed: $error" );
		}

		if ( $status >= 300 && $status < 400 ) {
			throw new \RuntimeException( "$url answered $status (redirect). Check the configured URL — it must be the canonical one." );
		}

		return [ 'status' => $status, 'headers' => $responseHeaders, 'body' => (string) $raw ];
	}
}
