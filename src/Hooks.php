<?php

namespace MediaWiki\Extension\NSFWImageModeration;

use MediaWiki\Api\Hook\APIAfterExecuteHook;
use MediaWiki\Context\RequestContext;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Html\Html;
use MediaWiki\Upload\Hook\UploadCompleteHook;
use MediaWiki\Upload\Hook\UploadVerifyFileHook;

class Hooks implements UploadVerifyFileHook, UploadCompleteHook, BeforePageDisplayHook, APIAfterExecuteHook {

	private const SESSION_KEY = 'nsfwimagemoderation-debug';

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
			$error = $this->image_classifier->rejection_error( 'nsfwimagemoderation-upload-unavailable', 'reason=unreadable-temp-file' );

			return;
		}

		$result = $this->image_classifier->classify( $file_path, (string)$mime, (string)$upload->getDesiredDestName() );
		if ( $result->rejected ) {
			$error = $this->image_classifier->rejection_error(
				$result->message_key,
				$result->debug_summary()
			);
		}
	}

	/** @inheritDoc */
	public function onUploadComplete( $uploadBase ) {
		$summary = $this->image_classifier->last_approved_debug_summary();
		if ( $summary === null ) {
			return;
		}

		$session = RequestContext::getMain()->getRequest()->getSession();
		$session->persist();
		$session->set( self::SESSION_KEY, $summary );
	}

	/** @inheritDoc */
	public function onBeforePageDisplay( $out, $skin ): void {
		$session = $out->getRequest()->getSession();
		$summary = $session->get( self::SESSION_KEY );
		if ( !is_string( $summary ) || $summary === '' ) {
			return;
		}

		$session->remove( self::SESSION_KEY );
		$out->prependHTML( Html::noticeBox(
			wfMessage( 'nsfwimagemoderation-debug-approved', $summary )->escaped(),
			'nsfwimagemoderation-debug'
		) );
	}

	/** @inheritDoc */
	public function onAPIAfterExecute( $module ) {
		if ( $module->getModuleName() !== 'upload' ) {
			return;
		}

		$summary = $this->image_classifier->last_approved_debug_summary();
		if ( $summary === null ) {
			return;
		}

		$module->getResult()->addValue( null, 'nsfwimagemoderation', [ 'debug' => $summary, 'rejected' => false ] );
	}

}
