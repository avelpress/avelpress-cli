<?php

namespace AvelPress\Cli\Release\Support;

/**
 * Decides which store download files belong to a plugin.
 *
 * Store files are named after the plugin slug and then decorated: an optional
 * version, the random suffix WordPress adds to protected uploads and, sometimes,
 * an extra label such as "-update". Matching has to accept all of that while
 * refusing a sibling plugin whose slug merely starts with the same words —
 * "infixs-correios-automatico" must never claim
 * "infixs-correios-automatico-pro-1.2.6-lkvpko.zip".
 */
class DownloadMatcher {

	/**
	 * Slugs owned by the plugin being released.
	 *
	 * @var string[]
	 */
	private $slugs;

	/**
	 * Slugs that look similar but belong to other plugins.
	 *
	 * @var string[]
	 */
	private $excludes;

	/**
	 * @param string[] $slugs    Plugin slug plus configured aliases.
	 * @param string[] $excludes Sibling slugs that must not be claimed.
	 */
	public function __construct( array $slugs, array $excludes = [] ) {
		$this->slugs = array_values( array_filter( $slugs ) );
		$this->excludes = array_values( array_filter( $excludes ) );
	}

	/**
	 * Whether the file belongs to this plugin.
	 *
	 * @param string $fileName File name or URL.
	 * @return bool
	 */
	public function matches( string $fileName ): bool {
		return $this->matchedSlug( $fileName ) !== null;
	}

	/**
	 * Returns which of the plugin slugs claims the file.
	 *
	 * Candidates are tried longest first so the most specific slug wins; when the
	 * winner is an excluded sibling, the file is not ours.
	 *
	 * @param string $fileName File name or URL.
	 * @return string|null
	 */
	public function matchedSlug( string $fileName ) {
		$name = self::fileName( $fileName );

		$candidates = array_merge( $this->slugs, $this->excludes );
		usort( $candidates, function ($a, $b) {
			return strlen( $b ) - strlen( $a );
		} );

		foreach ( $candidates as $candidate ) {
			if ( self::slugMatches( $candidate, $name ) === null ) {
				continue;
			}

			return in_array( $candidate, $this->excludes, true ) ? null : $candidate;
		}

		return null;
	}

	/**
	 * Extracts the version encoded in the file name.
	 *
	 * @param string $fileName File name or URL.
	 * @return string|null Version, or null when the name carries none.
	 */
	public function versionOf( string $fileName ) {
		$slug = $this->matchedSlug( $fileName );

		if ( $slug === null ) {
			return null;
		}

		$version = self::slugMatches( $slug, self::fileName( $fileName ) );

		return $version === '' ? null : $version;
	}

	/**
	 * Guesses the slug and version of any store file, without knowing the plugin.
	 *
	 * Used by the site-wide audit, which has to group files it has no local
	 * project for.
	 *
	 * @param string $fileName File name or URL.
	 * @return array{slug: string, version: string|null}
	 */
	public static function describe( string $fileName ): array {
		$name = preg_replace( '/\.zip$/i', '', self::fileName( $fileName ) );

		if ( preg_match( '/^(.+?)[.\-]v?(\d+(?:\.\d+)*)(?:[.\-].*)?$/', $name, $matches ) ) {
			return [ 'slug' => $matches[1], 'version' => $matches[2] ];
		}

		if ( preg_match( '/^(.+?)-[A-Za-z0-9]{4,12}$/', $name, $matches ) ) {
			return [ 'slug' => $matches[1], 'version' => null ];
		}

		return [ 'slug' => $name, 'version' => null ];
	}

	/**
	 * Reduces a URL to its file name.
	 *
	 * @param string $fileName File name or URL.
	 * @return string
	 */
	public static function fileName( string $fileName ): string {
		$path = parse_url( $fileName, PHP_URL_PATH );

		return basename( $path === null ? $fileName : $path );
	}

	/**
	 * Tests one slug against one file name.
	 *
	 * Two shapes are accepted. With a version, anything may follow it, because
	 * the version already anchors the name. Without a version, at most one short
	 * token is allowed — the random suffix — which is what keeps a sibling slug
	 * like "-pro-1.2.6-lkvpko" from matching the shorter slug.
	 *
	 * @param string $slug Candidate slug.
	 * @param string $name File name.
	 * @return string|null '' when it matches without a version, the version when
	 *                     it matches with one, null when it does not match.
	 */
	private static function slugMatches( string $slug, string $name ) {
		$quoted = preg_quote( $slug, '#' );

		if ( preg_match( '#^' . $quoted . '[.\-]v?(\d+(?:\.\d+)*)(?:[.\-][A-Za-z0-9]+)*\.zip$#i', $name, $matches ) ) {
			return $matches[1];
		}

		if ( preg_match( '#^' . $quoted . '(?:-[A-Za-z0-9]{4,12})?\.zip$#i', $name ) ) {
			return '';
		}

		return null;
	}
}
