<?php

namespace MediaWiki\Extension\NSFWImageModeration;

use MediaWiki\Upload\Hook\UploadVerifyFileHook;

class Hooks implements UploadVerifyFileHook {

	public function __construct(
		private readonly ImageClassifier $image_classifier,
	) {
	}

	/** @inheritDoc */
	public function onUploadVerifyFile( $upload, $mime, &$error ) {
		if ( !str_starts_with( (string)$mime, 'image/' ) ) {
			return;
		}

		$file_path = $upload->getTempPath();
		if ( $file_path === '' || !is_readable( $file_path ) ) {
			$error = [ 'nsfwimagemoderation-upload-unavailable' ];
			return;
		}

		$result = $this->image_classifier->classify( $file_path );
		if ( $result->rejected ) {
			$error = [ $result->message_key ];
		}
	}

}
