<?php

if ( ! class_exists( 'MM_Crypto' ) ) {
	class MM_Crypto {

		private function get_key_material() {
			$auth = defined( 'AUTH_KEY' ) ? AUTH_KEY : '';
			$salt = defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '';
			return hash( 'sha256', $auth . $salt, true );
		}

		public function encrypt( $plain_text ) {
			if ( '' === $plain_text || null === $plain_text ) {
				return '';
			}

			$iv = openssl_random_pseudo_bytes( 16 );
			if ( false === $iv ) {
				return '';
			}

			$cipher = openssl_encrypt( (string) $plain_text, 'aes-256-cbc', $this->get_key_material(), OPENSSL_RAW_DATA, $iv );
			if ( false === $cipher ) {
				return '';
			}

			return base64_encode( $iv . $cipher );
		}

		public function decrypt( $encoded_text ) {
			if ( '' === $encoded_text || null === $encoded_text ) {
				return '';
			}

			$decoded = base64_decode( (string) $encoded_text, true );
			if ( false === $decoded ) {
				return '';
			}

			// Minimum: 16 (IV) + 1 (ciphertext) = 17 bytes.
			if ( strlen( $decoded ) < 17 ) {
				return '';
			}

			$iv     = substr( $decoded, 0, 16 );
			$cipher = substr( $decoded, 16 );
			$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $this->get_key_material(), OPENSSL_RAW_DATA, $iv );
			return false === $plain ? '' : (string) $plain;
		}

		public function encrypt_key_pattern( $value ) {
			if ( '' === $value ) {
				return '';
			}
			return $this->encrypt( $value );
		}

		public function decrypt_key_pattern( $value ) {
			if ( '' === $value ) {
				return '';
			}
			return $this->decrypt( $value );
		}
	}
}
