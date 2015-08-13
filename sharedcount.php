<?php
/**
 * Plugin Name: Sharedcount
 * Plugin URI: http://www.oomphinc.com/plugins/sharedcount
 * Description: Pull, cache, and expose share counts from sharedcount.com.
 * Author: Ben Doherty @ Oomph, Inc
 * Author URI: http://www.oomphinc.com
 * Version: 0.1
 * Text Domain: sharedcount
 * Domain Path: languages
 * Credits: This plugin is a distillation of the sharedcount integration found in the mashshare plugin. HT to René Hermenau.
 *
 * This SharedCount WordPress plugin is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * This SharedCount WordPress plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Mashshare Share Buttons. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package SHAREDCOUNT
 * @author Ben Doherty bendohmv@gmail.com
 * @version 0.1
 */

if ( !class_exists( 'SharedCount' ) ) {

	class SharedCount {
		static function init() {
			$c = get_called_class();

			add_action( 'customize_register', array( $c, 'customize_register' ) );
			add_action( 'sharedcount_render', array( $c, 'render_count' ) );
		}

		static function customize_register( $wp_customize ) {
			// SharedCount section
			$wp_customize->add_section( 'sharedcount', array(
				'title' => 'SharedCount', // Not localized: branding
				'priority' => 300,
				'capability' => 'edit_theme_options',
			));

			// SharedCount endpoint
			$wp_customize->add_setting( 'sharedcount[endpoint]', array(
				'default' => 'free.sharedcount.com',
				'capability' => 'edit_theme_options',
			) );

			$wp_customize->add_control( 'sharedcount_endpoint', array(
				'label'   => __( 'Endpoint', 'sharedcount' ),
				'section' => 'sharedcount',
				'settings' => 'sharedcount[endpoint]'
			) );

			// SharedCount API key
			$wp_customize->add_setting( 'sharedcount[key]', array(
				'default' => '',
				'capability' => 'edit_theme_options',
			) );

			$wp_customize->add_control( 'sharedcount_key', array(
				'label'   => __( 'API Key', 'sharedcount' ),
				'section' => 'sharedcount',
				'settings' => 'sharedcount[key]'
			) );
		}

		/**
		 * Render the shared count.
		 *
		 * @action sharedcount_render
		 */
		static function render_count( $url = '' ) {
			// Default to requested URL
			if( empty( $url ) ) {
				$url = home_url( $GLOBALS['wp']->request );
			}

			$url = apply_filters( 'sharedcount_url', $url );

			echo apply_filters( 'sharedcount', self::get_total_count( $url ) );
		}

		/**
		 * Get the total count number
		 */
		static function get_total_count( $url ) {
			$counts = self::get_counts( $url );

			return $counts['total'];
		}

		/**
		 * Get the proper cache key for a URL. This can be cleared by a rolling
		 * clear by cycling a particular incrementor over time to prevent cache
		 * collisions.
		 */
		static function cache_key( $url ) {
			$key = md5( $url );

			// Segment cache into 4096 potential buckets which are cleared periodically,
			// through either a natural expiration time of 60-500 seconds.
			$segment = substr( $url, 0, 3 );

			// Use 4 digits to key an incrementor
			$incrementor = wp_cache_get( $segment, 'sharedcount' );

			if( $incrementor === false ) {
				$incrementor = md5( rand() );

				// Roll over this incrementor
				wp_cache_set( $segment, $incrementor, 'sharedcount', 60 + hexdec( substr( $segment, 0, 2 ) ) );
			}

			// Append with a global incrementor, which can get reset manually
			$global_incrementor .= wp_cache_get( 'inc', 'sharedcount' );

			if( $global_incrementor === false ) {
				$global_incrementor = md5( rand() );

				wp_cache_set( 'inc', $global_incrementor, 'sharedcount' );
			}

			return $key . md5( $incrementor . $global_incrementor );
		}

		/**
		 * Get the shared count for a particular URL. Cache for as long as
		 * possible.
		 */
		static function get_counts( $url ) {
			$cache_key = self::cache_key( $url );

			// Rely on the object cache to save these counts
			$cached = wp_cache_get( $cache_key, 'sharedcount' );

			if( $cached !== false ) {
				return $cached;
			}

			$options = wp_parse_args( get_theme_mod( 'sharedcount' ), array(
				'key' => '',
				'endpoint' => 'free.sharedcount.com',
				'fb_mode' => 'total'
			) );

			$request = wp_remote_get( 'http://'. $options['endpoint'] . '/?url=' . urlencode( $url ) . '&apikey=' . $options['key'], array( 'timeout' => 10000 ) );

			if( isset( $request['response']['code'] ) && $request['response']['code'] !== 200 ) {
				wp_cache_set( $cache_key, 0, 'sharedcount' );
			}
			else {
				$sharedcounts = json_decode( $request['body'], true );
				$counts = array( 'shares' => array(), 'total' => 0 );

				// Choose mode of Facebook counting, defaulting to total
				switch( $options['fb_mode'] ) {
				case 'likes':
					$counts['shares']['Facebook'] = $sharedcounts['Facebook']['like_count'];
					break;
				case 'shares':
					$counts['shares']['Facebook'] = $sharedcounts['Facebook']['share_count'];
					break;
				default:
					$counts['shares']['Facebook'] = $sharedcounts['Facebook']['total_count'];
				}

				$counts['total'] += $counts['shares']['Facebook'];
				unset( $sharedcounts['Facebook'] );

				// Aggregate the rest as simple shares and total count
				foreach( $sharedcounts as $service => $count ) {
					$counts['shares'][$service] = $count;
					$counts['total'] += $count;
				}

				wp_cache_set( $cache_key, $counts, 'sharedcount' );

				return $counts;
			}
		}
	}

	SharedCount::init();
}
