<?php

namespace AvelPress\Cli\Release\Contracts;

use AvelPress\Cli\Release\ArtifactRef;
use AvelPress\Cli\Release\Support\DownloadMatcher;

/**
 * Where release packages are published.
 *
 * The default driver uploads to the WordPress media library over HTTP, which is
 * what keeps a release possible from any machine. Other drivers (a protected
 * endpoint, a bucket) can be added without the release pipeline knowing.
 */
interface ArtifactStorage {

	/**
	 * Publishes a local file and returns how to reach it.
	 *
	 * @param string $file Absolute path of the file to publish.
	 * @return ArtifactRef
	 */
	public function put( string $file ): ArtifactRef;

	/**
	 * Packages already published for a plugin, newest first.
	 *
	 * @param DownloadMatcher $matcher Matcher for the plugin file names.
	 * @return ArtifactRef[]
	 */
	public function published( DownloadMatcher $matcher ): array;

	/**
	 * Removes a previously published package.
	 *
	 * @param ArtifactRef $artifact Package to remove.
	 */
	public function remove( ArtifactRef $artifact ): void;
}
