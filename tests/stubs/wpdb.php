<?php
/**
 * Test-only stand-in for WordPress's $wpdb, defined ONLY when a real WP load
 * order hasn't already loaded the genuine class.
 *
 * NOT a full SQL engine: it emulates exactly the access patterns WpdbRunStore
 * uses (INSERT / conditional UPDATE incl. the step_count bump / SELECT-by-id /
 * SELECT-by-run_id-ordered-by-seq) against in-memory rows, so Runner tests
 * exercise store + loop TOGETHER. Anything else falls back to the recorded-
 * query + canned-return knobs (queryReturn/varReturn/…), which remain
 * available for string-level assertions.
 *
 * @package Specflux Site Agent
 */

declare ( strict_types = 1 );

if ( ! class_exists( 'wpdb', false ) ) {
	if ( ! defined( 'ARRAY_A' ) ) {
		define( 'ARRAY_A', 'ARRAY_A' );
	}

	class wpdb {

		public string $prefix = 'wp_';

		public string $options = 'wp_options';

		/** @var list<string> Every SQL string passed through this class, in order. */
		public array $queries = array();

		/** Canned return for query() when no emulation applies. */
		public int|bool $queryReturn = 0;

		/** Canned return for get_var() when no queue entry or emulated read applies. */
		public mixed $varReturn = null;

		/** Ordered canned returns consumed by successive get_var() calls first. */
		public array $varQueue = array();

		public bool $emulateRows = true;

		/** @var array<string,list<array<string,mixed>>> table => rows */
		public array $tables = array();

		/** @var array<string,int> table => last assigned id */
		public array $lastId = array();

		/** @var array{table:string,data:array<string,mixed>}|null */
		public ?array $lastInsert = null;

		/** @var array{table:string,data:array<string,mixed>,where:array<string,mixed>}|null */
		public ?array $lastUpdate = null;

		/** Last insert id, like real wpdb. */
		public int $insert_id = 0;

		public function get_charset_collate(): string {
			return 'DEFAULT CHARACTER SET utf8mb4';
		}

		/**
		 * Placeholder substitution good enough to produce readable SQL.
		 *
		 * @param mixed ...$args Args.
		 */
		public function prepare( string $query, ...$args ): string {
			if ( count( $args ) === 1 && is_array( $args[0] ) ) {
				$args = $args[0];
			}

			$quoted = (string) preg_replace( '/(?<!%)%s/', "'%s'", $query );
			// `%i` (identifier) renders bare here; real wpdb backtick-quotes it.
			$quoted = (string) preg_replace( '/(?<!%)%i/', '%s', $quoted );

			return vsprintf( $quoted, $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function query( string $query ): int|bool {
			$this->queries[] = $query;

			// Emulate THE step_count bump (appendStep's position claim).
			if ( str_contains( $query, 'SET step_count = step_count + 1' )
				&& preg_match( '/WHERE id = (\d+)\b/', $query, $m ) ) {
				foreach ( array_keys( $this->tables ) as $name ) {
					if ( ! str_ends_with( $name, 'specflux_site_agent_runs' ) ) {
						continue;
					}
					$count = count( $this->tables[ $name ] );
					for ( $i = 0; $i < $count; ++$i ) {
						if ( (int) $this->tables[ $name ][ $i ]['id'] === (int) $m[1] ) {
							$this->tables[ $name ][ $i ]['step_count'] += 1;
							$this->tables[ $name ][ $i ]['updated_at']  = gmdate( 'Y-m-d H:i:s' );

							return 1;
						}
					}
				}

				return 1;
			}

			// Emulate the atomic run claim (schema 11): claim / extend / release.
			// Rows affected is real: 1 on a matching row, 0 otherwise.
			if ( preg_match( "/^UPDATE (\\S+) SET lock_token = '([^']*)', lock_until = '([^']*)' WHERE id = (\\d+) AND \\(lock_until IS NULL OR lock_until < '([^']*)'\\)$/", $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $i => $row ) {
					if ( (int) $row['id'] === (int) $m[4]
						&& ( empty( $row['lock_until'] ) || (string) $row['lock_until'] < $m[5] ) ) {
						$this->tables[ $m[1] ][ $i ]['lock_token'] = $m[2];
						$this->tables[ $m[1] ][ $i ]['lock_until'] = $m[3];

						return 1;
					}
				}

				return 0;
			}
			if ( preg_match( "/^UPDATE (\\S+) SET lock_until = '([^']*)' WHERE id = (\\d+) AND lock_token = '([^']*)'$/", $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $i => $row ) {
					if ( (int) $row['id'] === (int) $m[3] && ( $row['lock_token'] ?? null ) === $m[4] ) {
						$this->tables[ $m[1] ][ $i ]['lock_until'] = $m[2];

						return 1;
					}
				}

				return 0;
			}
			if ( preg_match( "/^UPDATE (\\S+) SET lock_token = NULL, lock_until = NULL WHERE id = (\\d+) AND lock_token = '([^']*)'$/", $query, $m ) ) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $i => $row ) {
					if ( (int) $row['id'] === (int) $m[2] && ( $row['lock_token'] ?? null ) === $m[3] ) {
						$this->tables[ $m[1] ][ $i ]['lock_token'] = null;
						$this->tables[ $m[1] ][ $i ]['lock_until'] = null;

						return 1;
					}
				}

				return 0;
			}

			// Emulate the connector activity prune (K4): DELETE ... WHERE created_at < 'ts'.
			if ( preg_match( "/^DELETE FROM (\\S+) WHERE created_at < '([^']+)'$/", $query, $m ) ) {
				$before                = count( $this->tables[ $m[1] ] ?? array() );
				$this->tables[ $m[1] ] = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => (string) $row['created_at'] >= $m[2]
					)
				);

				return $before - count( $this->tables[ $m[1] ] );
			}

			// Run retention (0.4 U3): DELETE ... WHERE run_id|id IN (...).
			if ( preg_match( '/^DELETE FROM (\\S+) WHERE (run_id|id) IN \\(([\\d,]+)\\)$/', $query, $m ) ) {
				$ids                   = array_map( 'intval', explode( ',', $m[3] ) );
				$before                = count( $this->tables[ $m[1] ] ?? array() );
				$this->tables[ $m[1] ] = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => ! in_array( (int) $row[ $m[2] ], $ids, true )
					)
				);

				return $before - count( $this->tables[ $m[1] ] );
			}

			return $this->queryReturn;
		}

		public function get_var( ?string $query = null ): mixed {
			if ( null !== $query && str_contains( $query, 'SELECT step_count' )
				&& preg_match( '/WHERE id = (\d+)\b/', $query, $m ) ) {
				$this->queries[] = $query;
				foreach ( $this->tables as $rows ) {
					foreach ( $rows as $row ) {
						if ( (int) $row['id'] === (int) $m[1] ) {
							return (int) $row['step_count'];
						}
					}
				}
			} elseif ( null !== $query ) {
				$this->queries[] = $query;
			}

			if ( array() !== $this->varQueue ) {
				return array_shift( $this->varQueue );
			}

			return $this->varReturn;
		}

		/** @return array<string,mixed>|null */
		public function get_row( ?string $query = null, string $output = 'ARRAY_A' ): ?array {
			if ( null === $query ) {
				return $this->rowReturn;
			}
			$this->queries[] = $query;

			if (
				$this->emulateRows
				&& preg_match( '/SELECT \* FROM (\S+) WHERE id = (\d+)\b/', $query, $m )
			) {
				foreach ( $this->tables[ $m[1] ] ?? array() as $row ) {
					if ( (int) $row['id'] === (int) $m[2] ) {
						return $row;
					}
				}

				return null;
			}

			return $this->rowReturn;
		}

		/** @return list<array<string,mixed>> */
		public function get_results( ?string $query = null, string $output = 'ARRAY_A' ): array {
			if ( null !== $query ) {
				$this->queries[] = $query;
			}

			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( '/SELECT \* FROM (\S+) (?:WHERE user_id = (\d+) )?ORDER BY updated_at DESC, id DESC LIMIT (\d+)/', $query, $m )
			) {
				$rows = $this->tables[ $m[1] ] ?? array();
				if ( '' !== $m[2] ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) ( $row['user_id'] ?? 0 ) === (int) $m[2] ) );
				}
				usort(
					$rows,
					static fn ( array $a, array $b ): int => array(
						(string) ( $b['updated_at'] ?? '' ),
						(int) $b['id'],
					) <=> array(
						(string) ( $a['updated_at'] ?? '' ),
						(int) $a['id'],
					)
				);

				return array_slice( $rows, 0, max( 1, (int) $m[3] ) );
			}

			// Stalled-run sweep (0.4 U2): active, un-ticked, un-claimed rows.
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/SELECT \\* FROM (\\S+) WHERE status IN \\('pending','running','awaiting_approval'\\) AND \\( last_tick_at < '([^']*)' OR \\( last_tick_at IS NULL AND created_at < '([^']*)' \\) \\) AND \\( lock_until IS NULL OR lock_until < '([^']*)' \\) AND id > (\\d+) ORDER BY id ASC LIMIT (\\d+)/", $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => in_array( $row['status'] ?? '', array( 'pending', 'running', 'awaiting_approval' ), true )
							&& ( isset( $row['last_tick_at'] ) ? (string) $row['last_tick_at'] < $m[2] : (string) ( $row['created_at'] ?? '' ) < $m[3] )
							&& ( ! isset( $row['lock_until'] ) || (string) $row['lock_until'] < $m[4] )
							&& (int) $row['id'] > (int) $m[5]
					)
				);
				usort( $rows, static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] );

				return array_slice( $rows, 0, (int) $m[6] );
			}

			// Approval-id lookup (0.4 U3).
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/^SELECT run_id FROM (\\S+) WHERE approval_id = '([^']*)' ORDER BY id DESC LIMIT (\\d+)$/", $query, $m )
			) {
				$rows = array_values( array_filter( $this->tables[ $m[1] ] ?? array(), static fn ( array $row ): bool => (string) ( $row['approval_id'] ?? '' ) === $m[2] ) );
				usort( $rows, static fn ( array $a, array $b ): int => (int) $b['id'] <=> (int) $a['id'] );

				return array_slice( array_map( static fn ( array $row ): array => array( 'run_id' => $row['run_id'] ), $rows ), 0, (int) $m[3] );
			}

			// Wall-clock expiry candidates (0.4 U3): non-terminal, old, keyset by id.
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/^SELECT \\* FROM (\\S+) WHERE status NOT IN \\('completed','failed','cancelled'\\) AND created_at < '([^']*)' AND id > (\\d+) ORDER BY id ASC LIMIT (\\d+)$/", $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => ! in_array( $row['status'] ?? '', array( 'completed', 'failed', 'cancelled' ), true )
							&& (string) ( $row['created_at'] ?? '' ) < $m[2]
							&& (int) $row['id'] > (int) $m[3]
					)
				);
				usort( $rows, static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] );

				return array_slice( $rows, 0, (int) $m[4] );
			}

			// Retention candidates (0.4 U3): terminal ids finished before a cutoff.
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/^SELECT id FROM (\\S+) WHERE status IN \\('completed','failed','cancelled'\\) AND COALESCE\\(finished_at, updated_at\\) < '([^']*)' AND id > (\\d+) ORDER BY id ASC LIMIT (\\d+)$/", $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => in_array( $row['status'] ?? '', array( 'completed', 'failed', 'cancelled' ), true )
							&& (string) ( $row['finished_at'] ?? $row['updated_at'] ?? '' ) < $m[2]
							&& (int) $row['id'] > (int) $m[3]
					)
				);
				usort( $rows, static fn ( array $a, array $b ): int => (int) $a['id'] <=> (int) $b['id'] );

				return array_slice( array_map( static fn ( array $row ): array => array( 'id' => $row['id'] ), $rows ), 0, (int) $m[4] );
			}

			// Sources of live follow-ups (0.4 U3).
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/^SELECT DISTINCT follow_up_of FROM (\\S+) WHERE follow_up_of IN \\(([\\d,]+)\\) AND status NOT IN \\('completed','failed','cancelled'\\)$/", $query, $m )
			) {
				$ids = array_map( 'intval', explode( ',', $m[2] ) );
				$out = array();
				foreach ( $this->tables[ $m[1] ] ?? array() as $row ) {
					if ( isset( $row['follow_up_of'] ) && in_array( (int) $row['follow_up_of'], $ids, true ) && ! in_array( $row['status'] ?? '', array( 'completed', 'failed', 'cancelled' ), true ) ) {
						$out[ (int) $row['follow_up_of'] ] = array( 'follow_up_of' => (int) $row['follow_up_of'] );
					}
				}

				return array_values( $out );
			}

			// Connector activity (K4): newest first, optionally one connection.
			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( "/^SELECT \\* FROM (\\S+?)(?: WHERE uuid = '([^']*)')? ORDER BY created_at DESC, id DESC LIMIT (\\d+)$/", $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => '' === $m[2] || (string) $row['uuid'] === $m[2]
					)
				);
				usort(
					$rows,
					static fn ( array $a, array $b ): int => array( (string) $b['created_at'], (int) $b['id'] ) <=> array( (string) $a['created_at'], (int) $a['id'] )
				);

				return array_slice( $rows, 0, (int) $m[3] );
			}

			if (
				$this->emulateRows
				&& null !== $query
				&& preg_match( '/SELECT \* FROM (\S+) WHERE run_id = (\d+) ORDER BY seq ASC/', $query, $m )
			) {
				$rows = array_values(
					array_filter(
						$this->tables[ $m[1] ] ?? array(),
						static fn ( array $row ): bool => (int) $row['run_id'] === (int) $m[2]
					)
				);
				usort(
					$rows,
					static fn ( array $a, array $b ): int => (int) $a['seq'] <=> (int) $b['seq']
				);

				return $rows;
			}

			return array();
		}

		/**
		 * Store a row with an auto-assigned id.
		 *
		 * @param string              $table Table.
		 * @param array<string,mixed> $data  Data.
		 * @param ?list<string>       $format Formats.
		 */
		public function insert( string $table, array $data, ?array $format = null ): int|bool {
			unset( $format );
			$id                       = ( $this->lastId[ $table ] ?? 0 ) + 1;
			$data['id']               = $id;
			$this->lastId[ $table ]   = $id;
			$this->tables[ $table ][] = $data;
			$this->lastInsert         = array(
				'table' => $table,
				'data'  => $data,
			);
			$this->insert_id          = $id;

			return 1;
		}

		/**
		 * Merge-update rows matching ALL where keys.
		 *
		 * @param string              $table Table.
		 * @param array<string,mixed> $data  Data.
		 * @param array<string,mixed> $where Where.
		 * @param ?list<string>       $format Data formats.
		 * @param ?list<string>       $where_format Where formats.
		 */
		public function update( string $table, array $data, array $where, ?array $format = null, ?array $where_format = null ): int|bool {
			unset( $format, $where_format );
			$this->lastUpdate = array(
				'table' => $table,
				'data'  => $data,
				'where' => $where,
			);

			$rows     = $this->tables[ $table ] ?? array();
			$affected = 0;
			$count    = count( $rows );
			for ( $i = 0; $i < $count; ++$i ) {
				$match = true;
				foreach ( $where as $key => $value ) {
					if ( (int) $rows[ $i ][ $key ] !== (int) $value ) {
						$match = false;

						break;
					}
				}
				if ( $match ) {
					foreach ( $data as $key => $value ) {
						$this->tables[ $table ][ $i ][ $key ] = $value;
					}
					++$affected;
				}
			}

			return $affected;
		}
	}
}
