<?php

namespace MediaWiki\Extension\NSFWImageModeration;

class ClassificationResult {

	/**
	 * @param list<array{label: string, score: float}> $scores
	 */
	public function __construct(
		public readonly bool $rejected,
		public readonly string $message_key,
		public readonly string $label = '',
		public readonly ?float $score = null,
		public readonly array $scores = [],
		public readonly string $detail = '',
	) {
	}

	public function debug_summary(): string {
		$parts = [];
		if ( $this->detail !== '' ) {
			$parts[] = $this->detail;
		}
		if ( $this->label !== '' ) {
			$parts[] = 'label=' . $this->label;
		}
		if ( $this->score !== null ) {
			$parts[] = 'score=' . self::format_score( $this->score );
		}
		if ( $this->scores !== [] ) {
			$score_parts = [];
			
			foreach ( $this->scores as $entry ) {
				$score_parts[] = $entry['label'] . '=' . self::format_score( $entry['score'] );
			}

			$parts[] = 'scores=' . implode( ',', $score_parts );
		}

		return $parts === [] ? 'no-details' : implode( ' ', $parts );
	}

	private static function format_score( float $score ): string {
		return rtrim( rtrim( sprintf( '%.4f', $score ), '0' ), '.' );
	}

}
