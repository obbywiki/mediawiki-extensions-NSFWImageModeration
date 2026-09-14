<?php

namespace MediaWiki\Extension\NSFWImageModeration;

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Logger\LoggerFactory;
use Throwable;

class ImageClassifier {

	public const CONSTRUCTOR_OPTIONS = [
		'NSFWImageModerationClassifierUrl',
		'NSFWImageModerationAlwaysReject',
		'NSFWImageModerationTimeout',
		'NSFWImageModerationNsfwThreshold',
		'NSFWImageModerationApiKey'
	];

	public function __construct(
		private readonly HttpRequestFactory $http_request_factory,
		private readonly ServiceOptions $options,
	) {
		$this->options->assertRequiredOptions( self::CONSTRUCTOR_OPTIONS );
	}

	public function classify( string $file_path ): ClassificationResult {
		if ( $this->options->get( 'NSFWImageModerationAlwaysReject' ) ) {
			return new ClassificationResult( true, 'nsfwimagemoderation-upload-rejected', 'nsfw', 1.0 );
		}

		return $this->classify_with_service( $file_path );
	}

	/**
	 * POST the image to the Hugging Face image-classification API at NSFWImageModerationClassifierUrl.
	 */
	private function classify_with_service( string $file_path ): ClassificationResult {
		$image_bytes = file_get_contents( $file_path );
		if ( $image_bytes === false || $image_bytes === '' ) {
			return $this->unavailable_result();
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
			$client = $this->http_request_factory->createGuzzleClient( [ 'timeout' => $timeout, 'connect_timeout' => min( 5, $timeout ) ] );
			$response = $client->post( $url, [ 'headers' => $headers, 'json' => HfImageClassification::request_payload( $image_bytes ) ] );
			$data = json_decode( (string)$response->getBody(), true );
			$result = HfImageClassification::from_response( $data, $threshold );

			if ( $result === null ) {
				$this->logger()->warning( 'Classifier returned an unexpected payload' );

				return $this->unavailable_result();
			}

			return $result;
		} catch ( Throwable $e ) {
			$this->logger()->warning( 'Classifier request failed: {message}', [ 'message' => $e->getMessage() ] );

			return $this->unavailable_result();
		}
	}

	private function unavailable_result(): ClassificationResult {
		return new ClassificationResult( true, 'nsfwimagemoderation-upload-unavailable' );
	}

	private function logger() {
		return LoggerFactory::getInstance( 'NSFWImageModeration' );
	}

}
