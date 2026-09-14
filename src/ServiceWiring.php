<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\NSFWImageModeration\ImageClassifier;
use MediaWiki\MediaWikiServices;

return [
	'NSFWImageModeration.ImageClassifier' => static function ( MediaWikiServices $services ): ImageClassifier {
		return new ImageClassifier(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				ImageClassifier::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},
];
