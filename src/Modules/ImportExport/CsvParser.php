<?php
/**
 * Lightweight CSV file reader with delimiter detection and chunked reading.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\ImportExport;

defined( 'ABSPATH' ) || exit;

/**
 * Reads CSV files in chunks without loading the full file into memory.
 * All file operations use native PHP functions — WP_Filesystem cannot handle
 * fgetcsv-style stream parsing, so phpcs:ignore is applied where needed.
 */
class CsvParser {

	/**
	 * Attempt to detect the CSV delimiter by sampling the first data line.
	 *
	 * @param string $file Absolute path to the CSV file.
	 * @return string Detected delimiter character (comma, semicolon, or tab).
	 */
	public static function detect_delimiter( $file ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'r' );
		if ( false === $fp ) {
			return ',';
		}

		// Read up to two lines — the header may not have obvious counts.
		$lines = array();
		for ( $i = 0; $i < 2; $i++ ) {
			$line = fgets( $fp );
			if ( false !== $line ) {
				$lines[] = $line;
			}
		}
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$sample = implode( '', $lines );
		$counts = array(
			','  => substr_count( $sample, ',' ),
			';'  => substr_count( $sample, ';' ),
			"\t" => substr_count( $sample, "\t" ),
			'|'  => substr_count( $sample, '|' ),
		);

		arsort( $counts );

		return (string) key( $counts );
	}

	/**
	 * Read the header row (first line) of a CSV file.
	 *
	 * @param string $file      Absolute path to the CSV file.
	 * @param string $delimiter Field delimiter.
	 * @return string[] Array of header strings, or empty array on failure.
	 */
	public static function read_headers( $file, $delimiter = ',' ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'r' );
		if ( false === $fp ) {
			return array();
		}

		$row = fgetcsv( $fp, 0, $delimiter );
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( ! is_array( $row ) ) {
			return array();
		}

		// Strip UTF-8 BOM from first cell.
		if ( isset( $row[0] ) ) {
			$row[0] = ltrim( $row[0], "\xEF\xBB\xBF" );
		}

		return array_map( 'trim', $row );
	}

	/**
	 * Count the total number of data rows (excluding header) in a CSV file.
	 *
	 * @param string $file      Absolute path to the CSV file.
	 * @param string $delimiter Field delimiter.
	 * @return int Row count, or 0 on failure.
	 */
	public static function count_rows( $file, $delimiter = ',' ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'r' );
		if ( false === $fp ) {
			return 0;
		}

		$count = -1; // -1 so we exclude the header row.
		while ( false !== fgetcsv( $fp, 0, $delimiter ) ) {
			++$count;
		}
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return max( 0, $count );
	}

	/**
	 * Read a chunk of data rows (skipping the header) from a CSV file.
	 *
	 * @param string   $file       Absolute path to the CSV file.
	 * @param int      $offset     Zero-based row index to start from (0 = first data row).
	 * @param int      $length     Maximum rows to return.
	 * @param string   $delimiter  Field delimiter.
	 * @param string[] $headers  Header row values used as array keys. If empty, numeric keys.
	 * @return array<int,array<string,string>> Associative rows if headers given, else indexed.
	 */
	public static function read_chunk( $file, $offset, $length, $delimiter = ',', $headers = array() ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$fp = fopen( $file, 'r' );
		if ( false === $fp ) {
			return array();
		}

		// Skip header row.
		fgetcsv( $fp, 0, $delimiter );

		// Skip rows before the requested offset.
		for ( $i = 0; $i < $offset; $i++ ) {
			if ( false === fgetcsv( $fp, 0, $delimiter ) ) {
				fclose( $fp );
				return array();
			}
		}

		$rows = array();
		$read = 0;
		while ( $read < $length ) {
			$row = fgetcsv( $fp, 0, $delimiter );
			if ( false === $row ) {
				break;
			}
			// Skip entirely blank lines.
			if ( array( null ) === $row ) {
				continue;
			}
			if ( ! empty( $headers ) ) {
				$assoc = array();
				foreach ( $headers as $i => $key ) {
					$assoc[ $key ] = isset( $row[ $i ] ) ? $row[ $i ] : '';
				}
				$rows[] = $assoc;
			} else {
				$rows[] = $row;
			}
			++$read;
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $rows;
	}
}
