<?php

namespace AvelPress\Cli\Release\Http;

/**
 * A REST call that came back with an error.
 *
 * Keeps the WordPress error code around because some of them mean something the
 * user has to act on — "product_invalid_download", for instance, means the file
 * is outside WooCommerce's approved download directories.
 */
class RestException extends \RuntimeException {

	/**
	 * WordPress error code, when the response carried one.
	 *
	 * @var string
	 */
	private $errorCode;

	/**
	 * HTTP status of the response.
	 *
	 * @var int
	 */
	private $status;

	/**
	 * @param string $message   Human readable message.
	 * @param string $errorCode WordPress error code.
	 * @param int    $status    HTTP status.
	 */
	public function __construct( string $message, string $errorCode = '', int $status = 0 ) {
		parent::__construct( $message );

		$this->errorCode = $errorCode;
		$this->status = $status;
	}

	/**
	 * WordPress error code.
	 *
	 * @return string
	 */
	public function errorCode(): string {
		return $this->errorCode;
	}

	/**
	 * HTTP status.
	 *
	 * @return int
	 */
	public function status(): int {
		return $this->status;
	}
}
