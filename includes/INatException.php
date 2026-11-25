<?php
/**
 * Error raised by the iNaturalist client.
 *
 * SPDX-FileCopyrightText: 2026 Eric van der Vlist <vdv@dyomedea.com>
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * @package WpInaturalist
 */

namespace Evlist\WpInaturalist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An error with a machine-readable kind, so that the refresh job can decide whether to retry.
 */
final class INatException extends \RuntimeException {

	const NETWORK      = 'network';
	const RATE_LIMITED = 'rate_limited';
	const SERVER       = 'server';
	const HTTP         = 'http';
	const MALFORMED    = 'malformed';
	const TRUNCATED    = 'truncated';

	/**
	 * Kind of error, one of the constants above.
	 *
	 * @var string
	 */
	public $kind;

	/**
	 * Seconds to wait before retrying, when the service said so.
	 *
	 * @var int|null
	 */
	public $retryAfter;

	/**
	 * Constructor.
	 *
	 * @param string   $kind       One of the constants of this class.
	 * @param string   $message    Description (English, not displayed as is).
	 * @param int|null $retryAfter Seconds to wait before retrying.
	 */
	public function __construct( string $kind, string $message, ?int $retryAfter = null ) {
		parent::__construct( $message );
		$this->kind       = $kind;
		$this->retryAfter = $retryAfter;
	}

	/**
	 * Whether trying again later may succeed.
	 */
	public function isRetryable(): bool {
		return in_array( $this->kind, array( self::NETWORK, self::RATE_LIMITED, self::SERVER ), true );
	}
}
