<?php
/**
 * Model-gateway contract (the Runner's single seam to the AI Client).
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

namespace Specflux\SiteAgent\Model;

// Bail on direct access.
defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Implemented by {@see AiClientGateway}; mocked in Runner tests so the whole
 * S4 tick algorithm is verifiable without a provider.
 */
interface ModelGatewayInterface {

	/**
	 * Generate ONE model turn (S9: a tick never makes more than one).
	 *
	 * @param list<\WordPress\AiClient\Messages\DTO\Message> $history           History messages.
	 * @param string                                          $system_instruction System instruction.
	 * @param \Specflux\SiteAgent\Tools\ToolRegistry          $tools             Admitted tools.
	 * @param array{0:string,1:string}|null                   $model_preference  Optional `[provider id, model id]` preference; null lets the AI Client auto-select.
	 * @return \Specflux\SiteAgent\Model\ModelTurn|\WP_Error
	 */
	public function generateTurn( array $history, string $system_instruction, \Specflux\SiteAgent\Tools\ToolRegistry $tools, ?array $model_preference = null ): ModelTurn|WP_Error;
}
