<?php

namespace MediaWiki\Extension\NSFWImageModeration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use Psr\Log\LoggerInterface;
use Throwable;

class ImageClassifier {

	public const CONSTRUCTOR_OPTIONS = [
		'NSFWImageModerationClassifierUrl',
		'NSFWImageModerationAlwaysReject',
		'NSFWImageModerationTimeout',
		'NSFWImageModerationNsfwThreshold',
		'NSFWImageModerationApiKey',
		'NSFWImageModerationDebug',
		'NSFWImageModerationFailClosed'
	];

	private const CLASSIFIABLE_MIME_TYPES = [
		'image/bmp',
		'image/gif',
		'image/jpeg',
		'image/jpg',
		'image/pjpeg',
		'image/png',
		'image/tiff',
		'image/webp',
		'image/x-bmp',
		'image/x-ms-bmp',
		'image/x-png',
		'image/x-tiff',
	];

	private const CLASSIFIABLE_IMAGE_TYPES = [
		IMAGETYPE_BMP,
		IMAGETYPE_GIF,
		IMAGETYPE_JPEG,
		IMAGETYPE_PNG,
		IMAGETYPE_TIFF_II,
		IMAGETYPE_TIFF_MM,
		IMAGETYPE_WEBP,
	];

	public function __construct(
		private readonly HttpRequestFactory $http_request_factory,
		private readonly ServiceOptions $options,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	private ?ClassificationResult $last_result = null;

	public function debug_enabled(): bool {
		return (bool)$this->options->get( 'NSFWImageModerationDebug' );
	}

	/**
	 * Classifier detail for an approved image in the current request, or null when debug is off.
	 */
	public function last_approved_debug_summary(): ?string {
		if ( !$this->debug_enabled() || $this->last_result === null || $this->last_result->rejected ) {
			return null;
		}

		if ( str_starts_with( $this->last_result->detail, 'skipped=' ) ) {
			return null;
		}

		return 'rejected=0 ' . $this->last_result->debug_summary();
	}

	/**
	 * @return array{0: string}|array{0: string, 1: string}
	 */
	public function rejection_error( string $message_key, string $debug_detail = '' ): array {
		if ( $this->debug_enabled() ) {
			return [ $message_key . '-debug', $debug_detail !== '' ? $debug_detail : 'no-details' ];
		}

		return [ $message_key ];
	}

	public function classify( string $file_path, string $mime = '', string $filename = '' ): ClassificationResult {
		if ( $this->options->get( 'NSFWImageModerationAlwaysReject' ) ) {
			$result = new ClassificationResult(
				true,
				'nsfwimagemoderation-upload-rejected',
				'nsfw',
				1.0,
				[],
				'mode=always-reject'
			);
			$this->log_result( $result, $file_path, $mime, $filename );

			return $result;
		}

		if ( !self::is_classifiable_image( $file_path, $mime ) ) {
			$result = new ClassificationResult(
				false,
				'',
				'',
				null,
				[],
				'skipped=not-readable-image'
			);
			$this->log_result( $result, $file_path, $mime, $filename );

			return $result;
		}

		$result = $this->classify_with_service( $file_path );
		$this->log_result( $result, $file_path, $mime, $filename );

		return $result;
	}

	/**
	 * True when the upload is a raster image the classifier can decode, false if otherwise. SVG and other `image/*` drawing formats are excluded.
	 */
	public static function is_classifiable_image( string $file_path, string $mime = '' ): bool {
		if ( $file_path === '' || !is_readable( $file_path ) ) {
			return false;
		}

		if ( $mime !== '' && !self::is_classifiable_mime( $mime ) ) {
			return false;
		}

		return self::is_readable_raster_image( $file_path );
	}

	/**
	 * True when MediaWiki reports a raster MIME type the classifier can decode, false if otherwise.
	 */
	public static function is_classifiable_mime( string $mime ): bool {
		return in_array( self::normalize_mime( $mime ), self::CLASSIFIABLE_MIME_TYPES, true );
	}

	/**
	 * True when the file magic bytes identify a raster format the classifier can decode, false if otherwise.
	 */
	public static function is_readable_raster_image( string $file_path ): bool {
		$image_type = self::detect_image_type( $file_path );

		return $image_type !== false && in_array( $image_type, self::CLASSIFIABLE_IMAGE_TYPES, true );
	}

	private static function normalize_mime( string $mime ): string {
		$mime = strtolower( trim( $mime ) );
		$semicolon = strpos( $mime, ';' );
		if ( $semicolon !== false ) {
			$mime = trim( substr( $mime, 0, $semicolon ) );
		}

		return $mime;
	}

	/**
	 * @return int|false
	 */
	private static function detect_image_type( string $file_path ) {
		if ( function_exists( 'exif_imagetype' ) ) {
			$type = @exif_imagetype( $file_path );
			if ( $type !== false ) {
				return $type;
			}
		}

		$image_info = @getimagesize( $file_path );
		if ( $image_info === false ) {
			return false;
		}

		return $image_info[2] ?? false;
	}

	/**
	 * POST the image to the Hugging Face image-classification API at NSFWImageModerationClassifierUrl.
	 */
	private function classify_with_service( string $file_path ): ClassificationResult {
		$image_bytes = file_get_contents( $file_path );
		if ( $image_bytes === false || $image_bytes === '' ) {
			return $this->unavailable_result( 'reason=empty-file' );
		}

		$url = $this->options->get( 'NSFWImageModerationClassifierUrl' );
		$timeout = (int)$this->options->get( 'NSFWImageModerationTimeout' );
		$threshold = (float)$this->options->get( 'NSFWImageModerationNsfwThreshold' );
		$api_key = (string)$this->options->get( 'NSFWImageModerationApiKey' );

		$headers = [ 'Content-Type' => 'application/json', 'Accept' => 'application/json' ];

		if ( $api_key !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $api_key;
		}

		try {
			$client = $this->http_request_factory->createGuzzleClient( [
				'timeout' => $timeout,
				'connect_timeout' => min( 5, $timeout ),
			] );
			$response = $client->post( $url, [
				'headers' => $headers,
				'json' => HfImageClassification::request_payload( $image_bytes ),
			] );
			$body = (string)$response->getBody();
			$data = json_decode( $body, true );
			$result = HfImageClassification::from_response( $data, $threshold );

			if ( $result === null ) {
				$this->logger()->warning( 'Classifier returned an unexpected payload' );

				return $this->unavailable_result(
					'reason=unexpected-payload status=' . $response->getStatusCode()
					. ' body=' . $this->truncate( $body )
				);
			}

			return new ClassificationResult(
				$result->rejected,
				$result->message_key,
				$result->label,
				$result->score,
				$result->scores,
				'threshold=' . $threshold
			);
		} catch ( Throwable $e ) {
			$this->logger()->warning( 'Classifier request failed: {message}', [ 'message' => $e->getMessage() ] );

			return $this->unavailable_result(
				'reason=request-failed error=' . $this->truncate( $e->getMessage() )
			);
		}
	}

	public function result_for_unavailable( string $detail, string $file_path = '', string $mime = '', string $filename = '' ): ClassificationResult {
		$result = $this->unavailable_result( $detail );
		$this->log_result( $result, $file_path, $mime, $filename );

		return $result;
	}

	private function unavailable_result( string $detail = '' ): ClassificationResult {
		$fail_closed = (bool)$this->options->get( 'NSFWImageModerationFailClosed' );
		$parts = [ $fail_closed ? 'fail=closed' : 'fail=open' ];
		if ( $detail !== '' ) {
			$parts[] = $detail;
		}

		return new ClassificationResult(
			$fail_closed,
			$fail_closed ? 'nsfwimagemoderation-upload-unavailable' : '',
			'',
			null,
			[],
			implode( ' ', $parts )
		);
	}

	private function log_result( ClassificationResult $result, string $file_path, string $mime, string $filename ): void {
		$this->last_result = $result;

		if ( !$this->debug_enabled() ) {
			return;
		}

		$this->logger()->info( 'classified {summary}', [
			'summary' => implode( ' ', array_filter( [
				'file=' . ( $filename !== '' ? $filename : basename( $file_path ) ),
				$mime !== '' ? 'mime=' . $mime : '',
				'rejected=' . ( $result->rejected ? '1' : '0' ),
				$result->debug_summary()
			] ) ),
		] );
	}

	private function truncate( string $value ): string {
		$value = str_replace( [ "\r", "\n" ], ' ', $value );
		if ( strlen( $value ) <= 300 ) {
			return $value;
		}

		return substr( $value, 0, 300 ) . '...';
	}

	private function logger(): LoggerInterface {
		return LoggerFactory::getInstance( 'NSFWImageModeration' );
	}

}
