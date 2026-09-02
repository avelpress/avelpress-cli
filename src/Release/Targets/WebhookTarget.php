<?php

namespace AvelPress\Cli\Release\Targets;

use AvelPress\Cli\Release\ArtifactRef;
use AvelPress\Cli\Release\Contracts\ReleaseTarget;
use AvelPress\Cli\Release\Http\HttpClient;
use AvelPress\Cli\Release\ReleaseContext;
use AvelPress\Cli\Release\Support\PluginHeader;

/**
 * Announces the release to the update manifest.
 *
 * Plugins that update themselves ask an endpoint what the current version is;
 * that answer used to be edited by hand and drifted — stale dates, a changelog
 * reading "Update changelog". Sending it from the release means the manifest and
 * the store can never disagree again, and the values come from the plugin header
 * and readme instead of being typed a second time.
 */
class WebhookTarget implements ReleaseTarget {

	/**
	 * Transport.
	 *
	 * @var HttpClient
	 */
	private $http;

	/**
	 * Endpoint that receives the manifest.
	 *
	 * @var string
	 */
	private $url;

	/**
	 * Bearer token for the endpoint.
	 *
	 * @var string
	 */
	private $token;

	/**
	 * Project being released.
	 *
	 * @var ReleaseContext
	 */
	private $context;

	/**
	 * @param HttpClient     $http    Transport.
	 * @param string         $url     Endpoint that receives the manifest.
	 * @param string         $token   Bearer token.
	 * @param ReleaseContext $context Project being released.
	 */
	public function __construct( HttpClient $http, string $url, string $token, ReleaseContext $context ) {
		$this->http = $http;
		$this->url = $url;
		$this->token = $token;
		$this->context = $context;
	}

	/**
	 * Name shown in the report.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'updater';
	}

	/**
	 * Builds the manifest that describes this release.
	 *
	 * @param ArtifactRef $artifact Package that should be linked.
	 * @param string      $version  Version being released.
	 * @return array[] A single entry.
	 */
	public function plan( ArtifactRef $artifact, string $version ): array {
		$readme = $this->context->projectDir() . DIRECTORY_SEPARATOR . 'readme.txt';
		$header = PluginHeader::read( $this->context->mainFile(), $readme );

		return [
			[
				'label' => 'update manifest for ' . $this->context->pluginId(),
				'manifest' => [
					'pluginId' => $this->context->pluginId(),
					'version' => $version,
					'package' => $artifact->url(),
					'requires' => $header['requires'],
					'requiresPHP' => $header['requires_php'],
					'tested' => $header['tested'],
					'changelog' => PluginHeader::changelogFor( $readme, $version ),
				],
			],
		];
	}

	/**
	 * Sends the manifest to the endpoint.
	 *
	 * @param array[] $plan Plan entries.
	 * @throws \RuntimeException When the endpoint rejects the manifest.
	 */
	public function apply( array $plan ): void {
		foreach ( $plan as $item ) {
			$response = $this->http->request(
				'POST',
				$this->url,
				json_encode( $item['manifest'] ),
				[
					'Content-Type: application/json',
					'Accept: application/json',
					'Authorization: Bearer ' . $this->token,
				]
			);

			if ( $response['status'] < 200 || $response['status'] >= 300 ) {
				$decoded = json_decode( $response['body'], true );
				$message = isset( $decoded['error']['message'] )
					? $decoded['error']['message']
					: substr( $response['body'], 0, 200 );

				throw new \RuntimeException(
					'The update manifest endpoint answered ' . $response['status'] . ': ' . $message
				);
			}
		}
	}
}
