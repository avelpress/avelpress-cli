<?php

namespace AvelPress\Cli\Release;

/**
 * A package that exists on the site and can be linked from a product.
 */
class ArtifactRef {

	/**
	 * Public URL of the file.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Media library id, 0 when the storage has no notion of one.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * @param string $url Public URL of the file.
	 * @param int    $id  Media library id.
	 */
	public function __construct( string $url, int $id = 0 ) {
		$this->url = $url;
		$this->id = $id;
	}

	/**
	 * Public URL of the file.
	 *
	 * @return string
	 */
	public function url(): string {
		return $this->url;
	}

	/**
	 * Media library id.
	 *
	 * @return int
	 */
	public function id(): int {
		return $this->id;
	}

	/**
	 * File name without the path.
	 *
	 * @return string
	 */
	public function fileName(): string {
		return basename( (string) parse_url( $this->url, PHP_URL_PATH ) );
	}
}
