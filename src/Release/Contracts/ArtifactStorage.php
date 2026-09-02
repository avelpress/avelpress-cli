<?php

namespace AvelPress\Cli\Release\Contracts;

use AvelPress\Cli\Release\ArtifactRef;
use AvelPress\Cli\Release\Support\DownloadMatcher;

/**
 * Where release packages are published.
 *
 * A release produces the same ZIP for two audiences with opposite needs. The
 * store copy must be reachable only through a purchase, while the copy the
 * update manifest points at is fetched by every customer's WordPress with no
 * credentials at all. Hence the visibility: one file, published twice.
 */
interface ArtifactStorage {

	/**
	 * Only served through a purchase; direct access is refused.
	 */
	const VISIBILITY_PROTECTED = 'protected';

	/**
	 * Fetchable by anyone who knows the URL.
	 */
	const VISIBILITY_PUBLIC = 'public';

	/**
	 * Publishes a local file and returns how to reach it.
	 *
	 * @param string $file       Absolute path of the file to publish.
	 * @param string $visibility One of the VISIBILITY_* constants.
	 * @return ArtifactRef
	 */
	public function put( string $file, string $visibility = self::VISIBILITY_PROTECTED ): ArtifactRef;

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
