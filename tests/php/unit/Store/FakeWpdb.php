<?php
/**
 * A stand-in for $wpdb in the Store unit tests: records every prepared statement and answers from a script.
 *
 * @package QershPay\WooCommerce\Tests
 */

namespace QershPay\WooCommerce\Tests\Unit\Store;

defined( 'ABSPATH' ) || exit;

/**
 * Implements the part of wpdb the Store uses: prepare() (with %i, %s, %d and %%), query(), get_row(),
 * get_results(), get_var(), suppress_errors(), esc_like(), last_error and prefix. Answers come from rules added
 * with on(): the first unused rule whose pattern matches the statement answers it.
 */
final class FakeWpdb {

	/**
	 * Table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Error of the last statement.
	 *
	 * @var string
	 */
	public $last_error = '';

	/**
	 * Every statement run, in order.
	 *
	 * @var string[]
	 */
	public $queries = array();

	/**
	 * Whether errors are suppressed.
	 *
	 * @var bool
	 */
	public $suppressed = false;

	/**
	 * Answer rules.
	 *
	 * @var array[]
	 */
	private $rules = array();

	/**
	 * Adds an answer: for the next statement matching `$pattern`, return `$result` (and set `$error`). A Closure as
	 * `$result` is called with the statement and its return value is the answer (for small stateful fakes).
	 *
	 * @param string $pattern Regular expression.
	 * @param mixed  $result  Result to return, or a Closure( string $sql ) that returns it.
	 * @param string $error   Error text to set.
	 * @param bool   $sticky  Keep the rule after use.
	 * @return self
	 */
	public function on( string $pattern, $result, string $error = '', bool $sticky = false ): self {
		$this->rules[] = array(
			'pattern' => $pattern,
			'result'  => $result,
			'error'   => $error,
			'sticky'  => $sticky,
			'used'    => false,
		);
		return $this;
	}

	/**
	 * Like wpdb::prepare().
	 *
	 * @param string $query SQL.
	 * @param mixed  ...$args Values, or one array of values.
	 * @return string
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i   = 0;
		$out = preg_replace_callback(
			'/%%|%[isd]/',
			function ( $m ) use ( &$i, $args ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				if ( ! array_key_exists( $i, $args ) ) {
					throw new \LogicException( 'prepare(): too few arguments' );
				}
				$value = $args[ $i++ ];
				if ( '%i' === $m[0] ) {
					return '`' . str_replace( '`', '``', (string) $value ) . '`';
				}
				if ( '%d' === $m[0] ) {
					return (string) (int) $value;
				}
				return "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
		if ( count( $args ) !== $i ) {
			throw new \LogicException( 'prepare(): ' . count( $args ) . ' arguments for ' . (int) $i . ' placeholders' );
		}
		return $out;
	}

	/**
	 * Like wpdb::suppress_errors().
	 *
	 * @param bool $suppress New value.
	 * @return bool Old value.
	 */
	public function suppress_errors( $suppress = true ) {
		$old              = $this->suppressed;
		$this->suppressed = (bool) $suppress;
		return $old;
	}

	/**
	 * Like wpdb::esc_like().
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}

	/**
	 * Like wpdb::query(): int or false.
	 *
	 * @param string $sql SQL.
	 * @return int|bool
	 */
	public function query( $sql ) {
		return $this->answer( $sql, 0 );
	}

	/**
	 * Like wpdb::get_row().
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type.
	 * @return array|null
	 */
	public function get_row( $sql, $output = 'OBJECT' ) {
		unset( $output );
		return $this->answer( $sql, null );
	}

	/**
	 * Like wpdb::get_results().
	 *
	 * @param string $sql    SQL.
	 * @param string $output Output type.
	 * @return array|null
	 */
	public function get_results( $sql, $output = 'OBJECT' ) {
		unset( $output );
		return $this->answer( $sql, array() );
	}

	/**
	 * Like wpdb::get_var().
	 *
	 * @param string $sql SQL.
	 * @return string|null
	 */
	public function get_var( $sql ) {
		return $this->answer( $sql, null );
	}

	/**
	 * Statements matching a pattern.
	 *
	 * @param string $pattern Regular expression.
	 * @return string[]
	 */
	public function matching( string $pattern ): array {
		return array_values(
			array_filter(
				$this->queries,
				function ( $q ) use ( $pattern ) {
					return 1 === preg_match( $pattern, $q );
				}
			)
		);
	}

	/**
	 * Records a statement and answers it.
	 *
	 * @param string $sql     SQL.
	 * @param mixed  $default Answer without a rule.
	 * @return mixed
	 */
	private function answer( $sql, $default ) {
		$this->queries[]  = $sql;
		$this->last_error = '';
		foreach ( $this->rules as &$rule ) {
			if ( ( $rule['sticky'] || ! $rule['used'] ) && 1 === preg_match( $rule['pattern'], $sql ) ) {
				$rule['used']     = true;
				$this->last_error = $rule['error'];
				return $rule['result'] instanceof \Closure ? ( $rule['result'] )( $sql ) : $rule['result'];
			}
		}
		unset( $rule );
		return $default;
	}
}
