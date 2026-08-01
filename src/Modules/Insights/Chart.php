<?php
/**
 * Inline SVG chart rendering.
 *
 * @package UserManagementSuite
 */

namespace Marinski\UserManagementSuite\Modules\Insights;

defined( 'ABSPATH' ) || exit;

/**
 * Draws the report charts as plain inline SVG.
 *
 * No charting library and no external request: a plugin that has to work on
 * WordPress.org cannot pull scripts from a CDN, and a rollup of a few hundred
 * points does not need a runtime to draw.
 */
class Chart {

	/**
	 * Render a filled line chart from a date => value series.
	 *
	 * @param array<string,int|float> $series Ordered date => value.
	 * @param array<string,mixed>     $args   height, color, label.
	 * @return string SVG markup.
	 */
	public static function line( array $series, array $args = array() ) {
		$args = array_merge(
			array(
				'height' => 220,
				'color'  => '#2271b1',
				'label'  => __( 'Signups over time', 'user-management-suite' ),
			),
			$args
		);

		$count = count( $series );

		if ( 0 === $count ) {
			return '<p class="ums-chart-empty">' . esc_html__( 'No data for this period.', 'user-management-suite' ) . '</p>';
		}

		// A single point has no line to draw; widen it into a flat two-point series.
		if ( 1 === $count ) {
			$series = array_merge( $series, $series );
			$count  = 2;
		}

		$width      = 1000;
		$height     = (int) $args['height'];
		$pad_left   = 48;
		$pad_right  = 12;
		$pad_top    = 16;
		$pad_bottom = 28;

		$plot_w = $width - $pad_left - $pad_right;
		$plot_h = $height - $pad_top - $pad_bottom;

		$values = array_values( $series );
		$dates  = array_keys( $series );
		$max    = max( $values );
		$max    = $max > 0 ? $max : 1;
		$nice   = self::nice_ceiling( $max );

		$points = array();
		$step   = $plot_w / max( 1, $count - 1 );

		foreach ( $values as $i => $value ) {
			$x        = $pad_left + ( $i * $step );
			$y        = $pad_top + $plot_h - ( ( $value / $nice ) * $plot_h );
			$points[] = round( $x, 2 ) . ',' . round( $y, 2 );
		}

		$line_path = implode( ' ', $points );
		$area_path = $pad_left . ',' . ( $pad_top + $plot_h ) . ' ' . $line_path . ' '
			. round( $pad_left + ( ( $count - 1 ) * $step ), 2 ) . ',' . ( $pad_top + $plot_h );

		$gridlines = '';
		$labels    = '';

		for ( $g = 0; $g <= 4; $g++ ) {
			$y     = $pad_top + ( $plot_h * $g / 4 );
			$value = $nice - ( $nice * $g / 4 );

			$gridlines .= sprintf(
				'<line x1="%1$d" y1="%2$.2f" x2="%3$d" y2="%2$.2f" stroke="#e0e0e0" stroke-width="1" />',
				$pad_left,
				$y,
				$width - $pad_right
			);

			$labels .= sprintf(
				'<text x="%1$d" y="%2$.2f" text-anchor="end" font-size="12" fill="#666">%3$s</text>',
				$pad_left - 8,
				$y + 4,
				esc_html( number_format_i18n( round( $value ) ) )
			);
		}

		// Only the endpoints and midpoint get a date label; more would collide.
		$x_labels  = '';
		$positions = array(
			0             => 'start',
			intdiv( $count - 1, 2 ) => 'middle',
			$count - 1    => 'end',
		);

		foreach ( $positions as $index => $anchor ) {
			if ( ! isset( $dates[ $index ] ) ) {
				continue;
			}

			$x_labels .= sprintf(
				'<text x="%1$.2f" y="%2$d" text-anchor="%3$s" font-size="12" fill="#666">%4$s</text>',
				$pad_left + ( $index * $step ),
				$height - 8,
				esc_attr( $anchor ),
				esc_html( self::short_date( $dates[ $index ] ) )
			);
		}

		return sprintf(
			'<svg class="ums-chart" viewBox="0 0 %1$d %2$d" preserveAspectRatio="none" role="img" aria-label="%3$s" style="width:100%%;height:%2$dpx;">'
				. '<title>%3$s</title>%4$s'
				. '<polyline points="%5$s" fill="%6$s" fill-opacity="0.12" stroke="none" />'
				. '<polyline points="%7$s" fill="none" stroke="%6$s" stroke-width="2" stroke-linejoin="round" stroke-linecap="round" />'
				. '%8$s%9$s</svg>',
			$width,
			$height,
			esc_attr( $args['label'] ),
			$gridlines,
			esc_attr( $area_path ),
			esc_attr( $args['color'] ),
			esc_attr( $line_path ),
			$labels,
			$x_labels
		);
	}

	/**
	 * Render a ranked bar table from a value => count map.
	 *
	 * A table rather than a chart: the values are labelled categories, the reader
	 * usually wants the exact number, and it stays readable with a screen reader.
	 *
	 * @param array<string,int>   $data Label => count, already sorted.
	 * @param array<string,mixed> $args label_header, count_header, total, links, colors.
	 * @return string HTML.
	 */
	public static function bars( array $data, array $args = array() ) {
		$args = array_merge(
			array(
				'label_header' => __( 'Value', 'user-management-suite' ),
				'count_header' => __( 'Users', 'user-management-suite' ),
				'total'        => 0,
				'links'        => array(),
				'colors'       => array(),
				'empty'        => __( 'Nothing recorded for this period.', 'user-management-suite' ),
			),
			$args
		);

		if ( array() === $data ) {
			return '<p class="ums-chart-empty">' . esc_html( $args['empty'] ) . '</p>';
		}

		$total = (int) $args['total'];

		if ( $total <= 0 ) {
			$total = array_sum( $data );
		}

		$peak = max( $data );
		$peak = $peak > 0 ? $peak : 1;

		$out = '<table class="widefat striped ums-bars"><thead><tr>'
			. '<th>' . esc_html( $args['label_header'] ) . '</th>'
			. '<th class="ums-num">' . esc_html( $args['count_header'] ) . '</th>'
			. '<th class="ums-num">' . esc_html__( 'Share', 'user-management-suite' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $data as $label => $count ) {
			$share = $total > 0 ? ( $count / $total ) * 100 : 0;
			$width = ( $count / $peak ) * 100;
			$color = isset( $args['colors'][ $label ] ) ? $args['colors'][ $label ] : '#2271b1';

			$text = isset( $args['links'][ $label ] )
				? '<a href="' . esc_url( $args['links'][ $label ] ) . '">' . esc_html( $label ) . '</a>'
				: esc_html( $label );

			$out .= sprintf(
				'<tr><td class="ums-bar-cell"><span class="ums-bar" style="width:%1$.2f%%;background:%2$s"></span><span class="ums-bar-label">%3$s</span></td>'
					. '<td class="ums-num">%4$s</td><td class="ums-num">%5$s%%</td></tr>',
				$width,
				esc_attr( $color ),
				$text,
				esc_html( number_format_i18n( $count ) ),
				esc_html( number_format_i18n( round( $share, 1 ), 1 ) )
			);
		}

		return $out . '</tbody></table>';
	}

	/**
	 * Round a maximum up to a readable axis ceiling.
	 *
	 * @param float $max Highest value in the series.
	 * @return float
	 */
	private static function nice_ceiling( $max ) {
		if ( $max <= 4 ) {
			return 4;
		}

		$magnitude = pow( 10, floor( log10( $max ) ) );
		$normal    = $max / $magnitude;

		foreach ( array( 1, 1.5, 2, 2.5, 5, 7.5, 10 ) as $candidate ) {
			if ( $normal <= $candidate ) {
				return $candidate * $magnitude;
			}
		}

		return 10 * $magnitude;
	}

	/**
	 * Compact date label for an axis.
	 *
	 * @param string $date Y-m-d.
	 * @return string
	 */
	private static function short_date( $date ) {
		$stamp = strtotime( $date );

		return $stamp ? wp_date( 'j M Y', $stamp ) : $date;
	}
}
