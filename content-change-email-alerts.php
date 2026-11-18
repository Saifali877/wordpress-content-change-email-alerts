<?php
/**
 * Plugin Name: Content Change Email Alerts
 * Description: Emails other administrators and editors when a user changes a post, page, public custom post type, or Elementor template.
 * Version: 1.1.0
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * Author: Saif Ur Rehman
 * License: GPL-2.0-or-later
 * Text Domain: content-change-email-alerts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DPS_Content_Change_Email_Alerts' ) ) {
	final class DPS_Content_Change_Email_Alerts {
		private static $pending = array();

		public static function init() {
			add_action( 'wp_after_insert_post', array( __CLASS__, 'post_saved' ), 20, 4 );
			add_action( 'added_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
			add_action( 'updated_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
			add_action( 'deleted_post_meta', array( __CLASS__, 'meta_changed' ), 10, 4 );
			add_action( 'set_object_terms', array( __CLASS__, 'terms_changed' ), 10, 6 );
			add_action( 'shutdown', array( __CLASS__, 'send_emails' ), 999 );
		}

		private static function can_track( $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post || ! get_current_user_id() || ! current_user_can( 'edit_post', $post_id ) ) {
				return false;
			}

			if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return false;
			}

			if ( in_array( $post->post_status, array( 'auto-draft', 'trash', 'inherit' ), true ) || 'attachment' === $post->post_type ) {
				return false;
			}

			$type = get_post_type_object( $post->post_type );
			return $type && ( $type->public || 'elementor_library' === $post->post_type );
		}

		private static function queue( $post_id, $created = false ) {
			$post_id = absint( $post_id );
			if ( ! self::can_track( $post_id ) ) {
				return;
			}

			if ( ! isset( self::$pending[ $post_id ] ) ) {
				self::$pending[ $post_id ] = array(
					'actor'   => get_current_user_id(),
					'created' => false,
				);
			}

			if ( $created ) {
				self::$pending[ $post_id ]['created'] = true;
			}
		}

		public static function post_saved( $post_id, $post, $update, $before ) {
			if ( ! $update ) {
				self::queue( $post_id, true );
				return;
			}

			if ( ! $before instanceof WP_Post ) {
				return;
			}

			$fields = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order', 'post_password' );
			foreach ( $fields as $field ) {
				if ( $post->$field !== $before->$field ) {
					self::queue( $post_id );
					return;
				}
			}
		}

		public static function meta_changed( $meta_id, $post_id, $key, $value ) {
			if ( ! is_string( $key ) || '' === $key ) {
				return;
			}

			$tracked = array(
				'_elementor_data', '_elementor_page_settings', '_thumbnail_id',
				'_price', '_regular_price', '_sale_price', '_sku',
				'_product_attributes', '_product_image_gallery',
			);

			if ( in_array( $key, $tracked, true ) ) {
				self::queue( $post_id );
				return;
			}

			// ACF reference rows contain a field key, e.g. _subtitle => field_abc123.
			// The value argument also allows us to recognize deleted reference rows.
			if ( '_' === $key[0] ) {
				if ( is_string( $value ) && 0 === strpos( $value, 'field_' ) ) {
					self::queue( $post_id );
				}
				return;
			}

			$reference = get_post_meta( $post_id, '_' . $key, true );
			if ( is_string( $reference ) && 0 === strpos( $reference, 'field_' ) ) {
				self::queue( $post_id );
			}
		}

		public static function terms_changed( $post_id, $terms, $term_taxonomy_ids, $taxonomy, $append, $old_term_taxonomy_ids ) {
			$old = array_map( 'intval', (array) $old_term_taxonomy_ids );
			$new = array_map( 'intval', (array) $term_taxonomy_ids );
			if ( $append ) {
				$new = array_merge( $old, $new );
			}

			$old = array_values( array_unique( $old ) );
			$new = array_values( array_unique( $new ) );
			sort( $old );
			sort( $new );
			if ( $old !== $new ) {
				self::queue( $post_id );
			}
		}

		public static function send_emails() {
			if ( ! self::$pending ) {
				return;
			}

			$events = self::$pending;
			self::$pending = array();
			$recipients = get_users( array(
				'role__in' => array( 'administrator', 'editor' ),
				'fields'   => array( 'ID', 'user_email' ),
			) );

			foreach ( $events as $post_id => $event ) {
				$actor = get_userdata( $event['actor'] );
				$post  = get_post( $post_id );
				if ( ! $actor || ! $post || ! self::can_track( $post_id ) ) {
					continue;
				}

				$title     = get_the_title( $post_id ) ?: '(Untitled)';
				$action    = $event['created'] ? 'Created' : 'Updated';
				$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
				$subject   = sprintf( '[%s] %s: %s', sanitize_text_field( $site_name ), $action, sanitize_text_field( $title ) );
				$subject   = str_replace( array( "\r", "\n" ), ' ', $subject );
				$edit_url  = get_edit_post_link( $post_id, 'raw' );
				$page_url  = get_permalink( $post_id );

				$details = array(
					'Website'      => $site_name,
					'Changed by'   => $actor->display_name,
					'Username'     => $actor->user_login,
					'Content type' => $post->post_type,
					'Title'        => $title,
					'Change'       => $action,
					'Date and time'=> wp_date( 'd M Y, h:i A T' ),
				);
				$lines = array();
				foreach ( $details as $label => $value ) {
					$lines[] = $label . ': ' . wp_strip_all_tags( (string) $value );
				}
				if ( $page_url ) {
					$lines[] = 'Page URL: ' . esc_url_raw( $page_url );
				}
				if ( $edit_url ) {
					$lines[] = 'Edit URL: ' . esc_url_raw( $edit_url );
				}
				$body = implode( "\n", $lines );

				$seen = array();
				foreach ( $recipients as $recipient ) {
					$email = strtolower( trim( (string) $recipient->user_email ) );
					if ( (int) $recipient->ID === (int) $actor->ID ||
						$email === strtolower( (string) $actor->user_email ) ||
						! is_email( $email ) || isset( $seen[ $email ] ) ) {
						continue;
					}
					$seen[ $email ] = true;
					wp_mail( $email, $subject, $body );
				}
			}
		}
	}

	DPS_Content_Change_Email_Alerts::init();
}
