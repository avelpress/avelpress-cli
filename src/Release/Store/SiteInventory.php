<?php

namespace AvelPress\Cli\Release\Store;

use AvelPress\Cli\Release\Http\WpRestClient;

/**
 * Reads what the store currently serves.
 *
 * Download files hang off simple products, variable products and each of their
 * variations, and Polylang duplicates the whole set per language. Nothing here
 * is configured by hand: the inventory sweeps the catalogue, discovers the
 * languages from the products themselves and returns every download entry it
 * finds, so a release never depends on someone remembering a product id.
 */
class SiteInventory {

	/**
	 * REST client for the store.
	 *
	 * @var WpRestClient
	 */
	private $client;

	/**
	 * Products already fetched, keyed by id.
	 *
	 * @var array
	 */
	private $products = null;

	/**
	 * Download entries already collected.
	 *
	 * The sweep costs one request per variable product, and a release asks for
	 * it more than once (preflight, plan, prune), so it is kept.
	 *
	 * @var array|null
	 */
	private $downloads = null;

	/**
	 * Client allowed to read the media library, when credentials permit it.
	 *
	 * @var WpRestClient|null
	 */
	private $mediaClient;

	/**
	 * @param WpRestClient      $client      REST client for the store.
	 * @param WpRestClient|null $mediaClient Client for wp/v2, when available.
	 */
	public function __construct( WpRestClient $client, WpRestClient $mediaClient = null ) {
		$this->client = $client;
		$this->mediaClient = $mediaClient;
	}

	/**
	 * Whether the media library can be inspected.
	 *
	 * @return bool
	 */
	public function hasMediaAccess(): bool {
		return $this->mediaClient !== null;
	}

	/**
	 * Site URL the inventory reads from.
	 *
	 * @return string
	 */
	public function baseUrl(): string {
		return $this->client->baseUrl();
	}

	/**
	 * Drops the cached sweep.
	 *
	 * Needed after the store has been written to, so a later question about what
	 * is still referenced is answered against the new state.
	 */
	public function refresh(): void {
		$this->products = null;
		$this->downloads = null;
	}

	/**
	 * Every download entry published by the store.
	 *
	 * @return array[] Entries with product, variation, language and file data.
	 */
	public function downloads(): array {
		if ( $this->downloads !== null ) {
			return $this->downloads;
		}

		$entries = [];

		foreach ( $this->allProducts() as $product ) {
			$entries = array_merge( $entries, $this->entriesOf( $product, null ) );

			if ( ! isset( $product['type'] ) || $product['type'] !== 'variable' ) {
				continue;
			}

			$variations = $this->client->getAll(
				'wc/v3/products/' . $product['id'] . '/variations',
				[ 'status' => 'any' ]
			);

			foreach ( $variations as $variation ) {
				$entries = array_merge( $entries, $this->entriesOf( $variation, $product ) );
			}
		}

		$this->downloads = $entries;

		return $this->downloads;
	}

	/**
	 * ZIP files present in the media library.
	 *
	 * Used to spot packages no product points to anymore. Files uploaded outside
	 * the media library are invisible here, which the report states explicitly.
	 *
	 * @return array[] Items with id, file name and URL.
	 */
	public function mediaZips(): array {
		if ( $this->mediaClient === null ) {
			return [];
		}

		$items = [];

		foreach ( $this->mediaClient->getAll( 'wp/v2/media', [ 'media_type' => 'application' ] ) as $media ) {
			$url = isset( $media['source_url'] ) ? $media['source_url'] : '';

			if ( ! preg_match( '/\.zip$/i', $url ) ) {
				continue;
			}

			$items[] = [
				'id' => $media['id'],
				'url' => $url,
				'date' => isset( $media['date'] ) ? substr( $media['date'], 0, 10 ) : '',
			];
		}

		return $items;
	}

	/**
	 * How many products were swept, for the report header.
	 *
	 * @return int
	 */
	public function productCount(): int {
		return count( $this->allProducts() );
	}

	/**
	 * Fetches the catalogue in every language the site publishes.
	 *
	 * The first sweep may come back filtered to the default language; the
	 * languages advertised by Polylang in the payload drive the extra sweeps,
	 * and results are merged by id so a site without Polylang costs one request.
	 *
	 * @return array Products keyed by id.
	 */
	private function allProducts(): array {
		if ( $this->products !== null ) {
			return $this->products;
		}

		$products = [];

		foreach ( $this->client->getAll( 'wc/v3/products', [ 'status' => 'any' ] ) as $product ) {
			$products[ $product['id'] ] = $product;
		}

		foreach ( $this->languagesIn( $products ) as $language ) {
			foreach ( $this->client->getAll( 'wc/v3/products', [ 'status' => 'any', 'lang' => $language ] ) as $product ) {
				$products[ $product['id'] ] = $product;
			}
		}

		$this->products = $products;

		return $this->products;
	}

	/**
	 * Language codes advertised by the products themselves.
	 *
	 * @param array $products Products keyed by id.
	 * @return string[]
	 */
	private function languagesIn( array $products ): array {
		$languages = [];

		foreach ( $products as $product ) {
			if ( ! empty( $product['lang'] ) ) {
				$languages[ $product['lang'] ] = true;
			}

			if ( ! empty( $product['translations'] ) && is_array( $product['translations'] ) ) {
				foreach ( array_keys( $product['translations'] ) as $code ) {
					$languages[ $code ] = true;
				}
			}
		}

		return array_keys( $languages );
	}

	/**
	 * Turns the downloads of one product or variation into entries.
	 *
	 * @param array      $item   Product or variation payload.
	 * @param array|null $parent Parent product when $item is a variation.
	 * @return array[]
	 */
	private function entriesOf( array $item, $parent ): array {
		if ( empty( $item['downloads'] ) || ! is_array( $item['downloads'] ) ) {
			return [];
		}

		$entries = [];

		foreach ( $item['downloads'] as $download ) {
			$entries[] = [
				'product_id' => $item['id'],
				'parent_id' => $parent ? $parent['id'] : 0,
				'type' => $parent ? 'variation' : ( isset( $item['type'] ) ? $item['type'] : 'simple' ),
				'product_name' => $parent && empty( $item['name'] ) ? $parent['name'] : ( isset( $item['name'] ) ? $item['name'] : '' ),
				'lang' => $this->languageOf( $item, $parent ),
				'status' => isset( $item['status'] ) ? $item['status'] : '',
				'download_id' => isset( $download['id'] ) ? $download['id'] : '',
				'download_name' => isset( $download['name'] ) ? $download['name'] : '',
				'file' => isset( $download['file'] ) ? $download['file'] : '',
			];
		}

		return $entries;
	}

	/**
	 * Resolves the language of a product or variation.
	 *
	 * @param array      $item   Product or variation payload.
	 * @param array|null $parent Parent product when $item is a variation.
	 * @return string
	 */
	private function languageOf( array $item, $parent ): string {
		if ( ! empty( $item['lang'] ) ) {
			return $item['lang'];
		}

		return ( $parent && ! empty( $parent['lang'] ) ) ? $parent['lang'] : '';
	}
}
