<?php

namespace MediaWiki\Extension\NSFWImageModeration;

class ClassificationResult {

	public function __construct(
		public readonly bool $rejected,
		public readonly string $message_key,
		public readonly string $label = '',
		public readonly ?float $score = null,
	) {
	}

}
