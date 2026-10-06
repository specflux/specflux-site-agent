<?php
/**
 * A test store platform with mutable detection.
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

namespace Specflux\SenroFlux\Tests\Packs\Commerce\Platforms;

use Specflux\SenroFlux\Packs\Commerce\Platforms\StorePlatform;
use WP_Error;

final class FakePlatform implements StorePlatform {

	public bool $active = true;

	public bool $governed;

	/**
	 * @param list<string> $namespaces Ability namespaces.
	 */
	public function __construct(
		private readonly string $id,
		bool $governed = true,
		private readonly array $namespaces = array( 'fake/', 'senroflux/' ),
		private readonly string $capability = 'manage_fake'
	) {
		$this->governed = $governed;
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return 'Fake ' . $this->id;
	}

	public function isActive(): bool {
		return $this->active;
	}

	public function inactiveReason(): ?string {
		return $this->active ? null : 'not_installed';
	}

	public function minVersion(): string {
		return '1.0.0';
	}

	public function isGoverned(): bool {
		return $this->governed;
	}

	public function abilityNamespaces(): array {
		return $this->namespaces;
	}

	public function runCapability(): string {
		return $this->capability;
	}

	public function roleMap(): array {
		return array( 'read' => 'fake-read' );
	}

	public function polyfills(): array {
		return array();
	}

	public function verbTiers(): array {
		return array( 'fake/verb' => 1 );
	}

	public function inputProperties( string $template ): array {
		unset( $template );

		return array();
	}

	public function verbFor( string $base_name, array $input ): ?string {
		unset( $input );

		return 'fake-read' === $base_name ? 'fake/verb' : null;
	}

	public function validateCall( string $base_name, array $input ): ?WP_Error {
		unset( $base_name, $input );

		return null;
	}

	public function objectIdForWrite( string $verb, array $args, array $output ): ?string {
		unset( $verb, $args, $output );

		return null;
	}
}
