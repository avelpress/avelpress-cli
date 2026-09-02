<?php

namespace AvelPress\Cli\Release\Http;

/**
 * Minimal REST client for a WordPress site.
 *
 * Talks to both wp/v2 and wc/v3 over HTTP Basic, which is what lets the release
 * run from any machine — no shell access to the server is ever required. The
 * transport itself lives in HttpClient; this class only knows how to build the
 * URLs, carry the credentials and read WordPress responses.
 */
class WpRestClient {

	/**
	 * Transport.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Site URL without a trailing slash.
	 *
	 * @var string
	 */
	private $baseUrl;

	/**
	 * Basic auth user.
	 *
	 * @var string
	 */
	private $user;

	/**
	 * Basic auth password.
	 *
	 * @var string
	 */
	private $password;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * @param string          $baseUrl  Site URL, e.g. https://infixs.com.
	 * @param string          $user     Basic auth user.
	 * @param string          $password Basic auth password.
	 * @param int             $timeout  Request timeout in seconds.
	 * @param HttpClient|null $http     Transport, for tests.
	 */
	public function __construct( string $baseUrl, string $user, string $password, int $timeout = 30, HttpClient $http = null ) {
		$this->baseUrl = rtrim( $baseUrl, '/' );
		$this->user = $user;
		$this->password = $password;
		$this->timeout = $timeout;
		$this->http = $http ? $http : new HttpClient();
	}

	/**
	 * Performs a GET request.
	 *
	 * @param string $path  Route path, e.g. "wc/v3/products".
	 * @param array  $query Query parameters.
	 * @return array Decoded response body.
	 */
	public function get( string $path, array $query = [] ): array {
		$response = $this->request( 'GET', $path, $query );

		return $response['body'];
	}

	/**
	 * Sends a JSON body with PUT.
	 *
	 * @param string $path Route path.
	 * @param array  $body Payload.
	 * @return array Decoded response body.
	 */
	public function put( string $path, array $body ): array {
		$response = $this->request( 'PUT', $path, [], json_encode( $body ), [ 'Content-Type: application/json' ] );

		return $response['body'];
	}

	/**
	 * Sends a DELETE request.
	 *
	 * @param string $path  Route path.
	 * @param array  $query Query parameters.
	 * @return array Decoded response body.
	 */
	public function delete( string $path, array $query = [] ): array {
		$response = $this->request( 'DELETE', $path, $query );

		return $response['body'];
	}

	/**
	 * Uploads a file to the media library as a downloadable product file.
	 *
	 * The upload is multipart on purpose, carrying the same "type" field the
	 * WooCommerce product editor sends. WooCommerce hooks upload_dir and
	 * wp_unique_filename on that field, which moves the package into the
	 * protected woocommerce_uploads folder — direct access answers 403, the file
	 * is only served through a purchase — and appends a random suffix to the
	 * name. Without the field the package would sit in the public uploads folder
	 * under a fully guessable URL. On a site without WooCommerce the field is
	 * simply ignored.
	 *
	 * @param string $path        Route path.
	 * @param string $file        Path of the file to upload.
	 * @param string $contentType MIME type sent to WordPress.
	 * @return array Decoded response body.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public function upload( string $path, string $file, string $contentType = 'application/zip' ): array {
		if ( ! is_readable( $file ) ) {
			throw new \RuntimeException( "Could not read $file." );
		}

		$response = $this->request( 'POST', $path, [], [
			'file' => new \CURLFile( $file, $contentType, basename( $file ) ),
			'type' => 'downloadable_product',
		] );

		return $response['body'];
	}

	/**
	 * Fetches every page of a collection route.
	 *
	 * WordPress reports the page count in X-WP-TotalPages; when the header is
	 * missing the request is treated as a single page.
	 *
	 * @param string $path  Route path.
	 * @param array  $query Query parameters.
	 * @return array Flattened items from all pages.
	 */
	public function getAll( string $path, array $query = [] ): array {
		$query = array_merge( [ 'per_page' => 100 ], $query );
		$page = 1;
		$items = [];

		do {
			$query['page'] = $page;
			$response = $this->request( 'GET', $path, $query );

			if ( ! is_array( $response['body'] ) ) {
				break;
			}

			$items = array_merge( $items, $response['body'] );

			$totalPages = isset( $response['headers']['x-wp-totalpages'] )
				? (int) $response['headers']['x-wp-totalpages']
				: 1;

			$page++;
		} while ( $page <= $totalPages );

		return $items;
	}

	/**
	 * Site URL this client talks to.
	 *
	 * @return string
	 */
	public function baseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * Executes a request and decodes the response.
	 *
	 * @param string      $method       HTTP method.
	 * @param string      $path         Route path.
	 * @param array       $query        Query parameters.
	 * @param string|null $body         Raw request body.
	 * @param array       $extraHeaders Additional request headers.
	 * @return array{body: mixed, headers: array, status: int}
	 * @throws \RuntimeException On transport errors and non 2xx responses.
	 */
	private function request( string $method, string $path, array $query = [], $body = null, array $extraHeaders = [] ): array {
		$url = $this->baseUrl . '/wp-json/' . ltrim( $path, '/' );

		if ( $query ) {
			$url .= '?' . http_build_query( $query );
		}

		$response = $this->http->request(
			$method,
			$url,
			$body,
			array_merge( [ 'Accept: application/json' ], $extraHeaders ),
			$this->timeout,
			$this->user . ':' . $this->password
		);

		$raw = $response['body'];
		$status = $response['status'];
		$decoded = json_decode( $raw, true );

		if ( $status < 200 || $status >= 300 ) {
			$message = isset( $decoded['message'] ) ? $decoded['message'] : substr( $raw, 0, 200 );
			$errorCode = isset( $decoded['code'] ) ? (string) $decoded['code'] : '';

			throw new RestException( "$method $path answered $status: $message", $errorCode, $status );
		}

		if ( $decoded === null && $raw !== 'null' ) {
			throw new \RuntimeException( "$method $path returned a response that is not JSON." );
		}

		return [ 'body' => $decoded, 'headers' => $response['headers'], 'status' => $status ];
	}
}
