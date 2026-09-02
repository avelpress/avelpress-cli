<?php

namespace AvelPress\Cli\Release\Storage;

use AvelPress\Cli\Release\ArtifactRef;
use AvelPress\Cli\Release\Contracts\ArtifactStorage;
use AvelPress\Cli\Release\Http\WpRestClient;
use AvelPress\Cli\Release\Support\DownloadMatcher;

/**
 * Publishes packages to the WordPress media library.
 *
 * This is the driver that needs nothing but an application password, which is
 * why it is the default. The file lands in the regular uploads folder, so it is
 * publicly reachable — a deliberate trade for not requiring shell access.
 */
class WordPressMediaStorage implements ArtifactStorage {

	/**
	 * REST client for wp/v2.
	 *
	 * @var WpRestClient
	 */
	private $client;

	/**
	 * @param WpRestClient $client REST client for wp/v2.
	 */
	public function __construct( WpRestClient $client ) {
		$this->client = $client;
	}

	/**
	 * Uploads the package and returns its public URL.
	 *
	 * @param string $file Absolute path of the ZIP.
	 * @return ArtifactRef
	 * @throws \RuntimeException When the response carries no URL.
	 */
	public function put( string $file ): ArtifactRef {
		$media = $this->client->upload( 'wp/v2/media', $file );

		if ( empty( $media['source_url'] ) ) {
			throw new \RuntimeException( 'The upload succeeded but WordPress returned no file URL.' );
		}

		return new ArtifactRef( $media['source_url'], isset( $media['id'] ) ? (int) $media['id'] : 0 );
	}

	/**
	 * Packages of this plugin already in the media library, newest first.
	 *
	 * Ordering is by attachment id: it grows monotonically, so it survives files
	 * whose names carry no version.
	 *
	 * @param DownloadMatcher $matcher Matcher for the plugin file names.
	 * @return ArtifactRef[]
	 */
	public function published( DownloadMatcher $matcher ): array {
		$found = [];

		foreach ( $this->client->getAll( 'wp/v2/media', [ 'media_type' => 'application' ] ) as $media ) {
			$url = isset( $media['source_url'] ) ? $media['source_url'] : '';

			if ( ! $url || ! $matcher->matches( $url ) ) {
				continue;
			}

			$found[] = new ArtifactRef( $url, isset( $media['id'] ) ? (int) $media['id'] : 0 );
		}

		usort( $found, function (ArtifactRef $a, ArtifactRef $b) {
			return $b->id() - $a->id();
		} );

		return $found;
	}

	/**
	 * Deletes a package from the media library.
	 *
	 * @param ArtifactRef $artifact Package to remove.
	 */
	public function remove( ArtifactRef $artifact ): void {
		if ( ! $artifact->id() ) {
			throw new \RuntimeException( 'Cannot delete ' . $artifact->fileName() . ': it is not a media library item.' );
		}

		$this->client->delete( 'wp/v2/media/' . $artifact->id(), [ 'force' => 'true' ] );
	}
}
