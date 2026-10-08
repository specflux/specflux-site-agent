<?php
/**
 * Test-only stand-ins for SenroGate's Capability Pack surface (Pack value
 * object, Tier enum, PackResolver/registry): only the shape the harness reads.
 *
 * LOAD ONLY FROM A SEPARATE-PROCESS TEST: defining these flips every
 * `class_exists( ...\Packs\Pack::class )` probe in the harness, which other
 * tests assume is false.
 *
 * Knobs: $GLOBALS['senroflux_test_as_packs'] (name => Pack) and
 * $GLOBALS['senroflux_test_as_bindings'] (token => pack name).
 *
 * @package SenroFlux
 */

declare ( strict_types = 1 );

require_once __DIR__ . '/agent-safety-pack/tier.php';
require_once __DIR__ . '/agent-safety-pack/pack.php';
require_once __DIR__ . '/agent-safety-pack/resolver.php';
