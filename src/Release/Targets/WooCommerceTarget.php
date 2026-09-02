<?php

namespace AvelPress\Cli\Release\Targets;

use AvelPress\Cli\Release\ArtifactRef;
use AvelPress\Cli\Release\Contracts\ReleaseTarget;
use AvelPress\Cli\Release\Http\RestException;
use AvelPress\Cli\Release\Http\WpRestClient;
use AvelPress\Cli\Release\Store\SiteInventory;
use AvelPress\Cli\Release\Support\DownloadMatcher;

/**
 * Points every store download of a plugin at the package just published.
 *
 * Two rules shape this class. The download id is never regenerated, because it
 * is the key the customer's purchase is tied to — a new id silently breaks the
 * download of every order already paid for. And WooCommerce replaces the whole
 * downloads array on write, so entries belonging to other plugins are sent back
 * untouched instead of being dropped.
 */
class WooCommerceTarget implements ReleaseTarget {

	/**
	 * REST client for wc/v3.
	 *
	 * @var WpRestClient
	 */
	private $client;

	/**
	 * Store inventory.
	 *
	 * @var SiteInventory
	 */
	private $inventory;

	/**
	 * Matcher for the plugin file names.
	 *
	 * @var DownloadMatcher
	 */
	private $matcher;

	/**
	 * @param WpRestClient    $client    REST client for wc/v3.
	 * @param SiteInventory   $inventory Store inventory.
	 * @param DownloadMatcher $matcher   Matcher for the plugin file names.
	 */
	public function __construct( WpRestClient $client, SiteInventory $inventory, DownloadMatcher $matcher ) {
		$this->client = $client;
		$this->inventory = $inventory;
		$this->matcher = $matcher;
	}

	/**
	 * Name shown in the report.
	 *
	 * @return string
	 */
	public function name(): string {
		return 'store';
	}

	/**
	 * Builds the list of products and variations that need a new file URL.
	 *
	 * @param ArtifactRef $artifact Package that should be linked.
	 * @param string      $version  Version being released.
	 * @return array[]
	 */
	public function plan( ArtifactRef $artifact, string $version ): array {
		$byProduct = [];

		foreach ( $this->inventory->downloads() as $entry ) {
			$byProduct[ $entry['product_id'] ][] = $entry;
		}

		$plan = [];

		foreach ( $byProduct as $productId => $entries ) {
			$downloads = [];
			$changes = [];

			foreach ( $entries as $entry ) {
				$file = $entry['file'];

				if ( $this->matcher->matches( $file ) && $file !== $artifact->url() ) {
					$changes[] = [
						'download_id' => $entry['download_id'],
						'from' => $file,
						'to' => $artifact->url(),
					];

					$file = $artifact->url();
				}

				$downloads[] = [
					'id' => $entry['download_id'],
					'name' => $entry['download_name'],
					'file' => $file,
				];
			}

			if ( ! $changes ) {
				continue;
			}

			$first = $entries[0];

			$plan[] = [
				'product_id' => (int) $productId,
				'parent_id' => (int) $first['parent_id'],
				'label' => '#' . $productId . ' ' . $first['product_name']
					. ( $first['lang'] ? ' [' . $first['lang'] . ']' : '' ),
				'downloads_before' => array_map( function ($entry) {
					return [ 'id' => $entry['download_id'], 'name' => $entry['download_name'], 'file' => $entry['file'] ];
				}, $entries ),
				'downloads_after' => $downloads,
				'changes' => $changes,
			];
		}

		return $plan;
	}

	/**
	 * Writes the planned download arrays back to the store.
	 *
	 * @param array[] $plan Plan entries.
	 * @throws \RuntimeException When WooCommerce refuses the file.
	 */
	public function apply( array $plan ): void {
		foreach ( $plan as $item ) {
			$this->write( $item['product_id'], $item['parent_id'], $item['downloads_after'], $item['label'] );
		}
	}

	/**
	 * Restores the download arrays saved in a backup file.
	 *
	 * @param array[] $plan Plan entries, applied in reverse.
	 */
	public function revert( array $plan ): void {
		foreach ( $plan as $item ) {
			$this->write( $item['product_id'], $item['parent_id'], $item['downloads_before'], $item['label'] );
		}
	}

	/**
	 * Sends one downloads array to the right endpoint.
	 *
	 * @param int    $productId Product or variation id.
	 * @param int    $parentId  Parent product id, 0 for a plain product.
	 * @param array  $downloads Downloads array to write.
	 * @param string $label     Label used in error messages.
	 * @throws \RuntimeException When WooCommerce refuses the file.
	 */
	private function write( int $productId, int $parentId, array $downloads, string $label ): void {
		$path = $parentId
			? 'wc/v3/products/' . $parentId . '/variations/' . $productId
			: 'wc/v3/products/' . $productId;

		try {
			$this->client->put( $path, [ 'downloads' => $downloads ] );
		} catch (RestException $e) {
			if ( $e->errorCode() === 'product_invalid_download' ) {
				throw new \RuntimeException(
					"WooCommerce refused the file on $label because the folder is not in its approved download directories. "
					. 'Add the uploads folder once in WooCommerce > Settings > Products > Downloadable products > Approved directories, '
					. 'using the folder root (…/wp-content/uploads/) so every future month is covered.'
				);
			}

			throw $e;
		}
	}
}
