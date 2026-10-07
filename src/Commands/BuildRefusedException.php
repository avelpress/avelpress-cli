<?php

namespace AvelPress\Cli\Commands;

/**
 * The build stopped on purpose: the project would produce a package that must
 * not be shipped.
 */
class BuildRefusedException extends \RuntimeException {
}
