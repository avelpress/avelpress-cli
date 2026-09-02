<?php

namespace AvelPress\Cli\Release\Contracts;

use AvelPress\Cli\Release\ArtifactRef;

/**
 * Something that points at the current package and has to be updated.
 *
 * Planning is separate from applying so a dry run, the backup file and the
 * report can all be produced from the same description of the change.
 */
interface ReleaseTarget {

	/**
	 * Name shown in the report.
	 *
	 * @return string
	 */
	public function name(): string;

	/**
	 * Which copy of the package this target should point at.
	 *
	 * @return string One of the ArtifactStorage VISIBILITY_* constants.
	 */
	public function artifactVisibility(): string;

	/**
	 * Describes what would change, without changing anything.
	 *
	 * @param ArtifactRef $artifact Package that should be linked.
	 * @param string      $version  Version being released.
	 * @return array[] One entry per object that needs an update.
	 */
	public function plan( ArtifactRef $artifact, string $version ): array;

	/**
	 * Applies a plan produced by plan().
	 *
	 * @param array[] $plan Plan entries.
	 */
	public function apply( array $plan ): void;
}
