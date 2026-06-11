<?php
/**
 * Runs real-provider AI Spam Review evaluations.
 *
 * @since 1.9.0
 */

use Exception as BaseException;
use RuntimeException as BaseRuntimeException;

if ( ! defined( 'ABSPATH' ) ) {
	echo "This script must be run through WP-CLI eval-file.\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI-only guard output.
	exit( 1 );
}

/**
 * Parses eval-file arguments.
 *
 * @since 1.9.0
 *
 * @param array $argv Raw CLI arguments.
 *
 * @return array Parsed arguments.
 */
function gf_zero_spam_ai_eval_parse_args( $argv ) {
	$parsed = [
		'providers'  => [],
		'repeats'    => 1,
		'corpus'     => __DIR__ . '/ai-review-corpus.sample.json',
		'output'     => '',
		'delay_ms'   => 0,
		'retries'    => 0,
		'backoff_ms' => 2000,
	];

	foreach ( $argv as $arg ) {
		$arg = (string) $arg;

		if ( 0 === strpos( $arg, '--' ) ) {
			$arg = substr( $arg, 2 );
		}

		if ( false === strpos( $arg, '=' ) ) {
			continue;
		}

		$parts = explode( '=', $arg, 2 );
		$key   = $parts[0];
		$value = $parts[1] ?? '';

		if ( 'providers' === $key ) {
			$parsed['providers'] = gf_zero_spam_ai_eval_normalize_providers( $value );
			continue;
		}

		if ( 'repeats' === $key ) {
			$parsed['repeats'] = max( 1, (int) $value );
			continue;
		}

		if ( 'corpus' === $key ) {
			$parsed['corpus'] = $value;
			continue;
		}

		if ( 'output' === $key ) {
			$parsed['output'] = $value;
			continue;
		}

		if ( 'delay_ms' === $key ) {
			$parsed['delay_ms'] = max( 0, (int) $value );
			continue;
		}

		if ( 'retries' === $key ) {
			$parsed['retries'] = max( 0, (int) $value );
			continue;
		}

		if ( 'backoff_ms' === $key ) {
			$parsed['backoff_ms'] = max( 0, (int) $value );
		}
	}

	if ( empty( $parsed['providers'] ) ) {
		$parsed['providers'] = [ '' ];
	}

	return $parsed;
}

/**
 * Normalizes a comma-separated provider list.
 *
 * @since 1.9.0
 *
 * @param string $providers Provider list.
 *
 * @return string[] Provider IDs.
 */
function gf_zero_spam_ai_eval_normalize_providers( $providers ) {
	$providers = array_map( 'trim', explode( ',', (string) $providers ) );

	return array_values(
		array_filter(
			$providers,
			static function ( $provider ) {
				return '' !== $provider;
			}
		)
	);
}

/**
 * Loads and validates the evaluation corpus.
 *
 * @since 1.9.0
 *
 * @param string $path Corpus path.
 *
 * @throws BaseRuntimeException When the corpus cannot be loaded.
 *
 * @return array Corpus cases.
 */
function gf_zero_spam_ai_eval_load_corpus( $path ) {
	if ( '' === $path || ! is_readable( $path ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception is encoded in the JSON report.
		throw new BaseRuntimeException( 'Corpus file is not readable: ' . $path );
	}

	$contents = file_get_contents( $path );
	$decoded  = json_decode( (string) $contents, true );

	if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $decoded ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI exception is encoded in the JSON report.
		throw new BaseRuntimeException( 'Corpus file is not valid JSON: ' . json_last_error_msg() );
	}

	if ( ! isset( $decoded['cases'] ) || ! is_array( $decoded['cases'] ) ) {
		throw new BaseRuntimeException( 'Corpus file must contain a cases array.' );
	}

	return $decoded['cases'];
}

/**
 * Builds a synthetic Gravity Forms form and entry for a corpus case.
 *
 * @since 1.9.0
 *
 * @param array $case_data      Corpus case.
 * @param int   $form_id        Synthetic form ID.
 * @param int   $provider_index Provider index.
 *
 * @return array{form: array, entry: array}
 */
function gf_zero_spam_ai_eval_build_submission( $case_data, $form_id, $provider_index ) {
	$fields = [];
	$entry  = [
		'id'         => 0,
		'form_id'    => $form_id,
		'source_url' => 'https://example.test' . gf_zero_spam_ai_eval_source_path( $case_data ),
		'user_agent' => 'GF Zero Spam AI Eval Harness',
		'ip'         => '127.0.0.1',
	];

	$field_id = 1;

	foreach ( gf_zero_spam_ai_eval_case_fields( $case_data ) as $field ) {
		$fields[] = [
			'id'    => $field_id,
			'label' => (string) ( $field['label'] ?? 'Field ' . $field_id ),
			'type'  => (string) ( $field['type'] ?? 'text' ),
		];

		$entry[ (string) $field_id ] = (string) ( $field['value'] ?? '' );
		++$field_id;
	}

	$form = [
		'id'                             => $form_id,
		'title'                          => (string) ( $case_data['form_title'] ?? 'AI Evaluation Form' ),
		'fields'                         => $fields,
		'enableGFZeroSpam'               => '1',
		'enableGFZeroSpamAI'             => '1',
		'enableGFZeroSpamAIRescue'       => '1',
		'gfZeroSpamAIMaxCallsPerHour'    => '0',
		'gfZeroSpamAIExcludedFields'     => [],
		'gfZeroSpamAIEvaluationProvider' => (string) $provider_index,
	];

	return [
		'form'  => $form,
		'entry' => $entry,
	];
}

/**
 * Gets normalized corpus fields.
 *
 * @since 1.9.0
 *
 * @param array $case_data Corpus case.
 *
 * @return array[] Case fields.
 */
function gf_zero_spam_ai_eval_case_fields( $case_data ) {
	if ( isset( $case_data['fields'] ) && is_array( $case_data['fields'] ) ) {
		return $case_data['fields'];
	}

	return [];
}

/**
 * Gets a normalized source path for a corpus case.
 *
 * @since 1.9.0
 *
 * @param array $case_data Corpus case.
 *
 * @return string Source path.
 */
function gf_zero_spam_ai_eval_source_path( $case_data ) {
	$source_path = (string) ( $case_data['source_path'] ?? '/' );

	if ( '' === $source_path ) {
		return '/';
	}

	return '/' === $source_path[0] ? $source_path : '/' . $source_path;
}

/**
 * Runs one provider/case/repeat evaluation.
 *
 * @since 1.9.0
 *
 * @param GF_Zero_Spam_AI_Review $review   AI review runtime.
 * @param array                  $case_data Corpus case.
 * @param string                 $provider Provider ID.
 * @param int                    $form_id  Synthetic form ID.
 * @param int                    $repeat   Repeat number.
 * @param int                    $index    Provider index.
 * @param int                    $attempt  Attempt number.
 *
 * @return array Evaluation record.
 */
function gf_zero_spam_ai_eval_run_case( $review, $case_data, $provider, $form_id, $repeat, $index, $attempt = 1 ) {
	$submission = gf_zero_spam_ai_eval_build_submission( $case_data, $form_id, $index );
	$started    = microtime( true );
	$result     = $review->classify( $submission['form'], $submission['entry'] );
	$latency    = (int) round( ( microtime( true ) - $started ) * 1000 );

	if ( null === $result ) {
		return [
			'case_id'       => (string) ( $case_data['id'] ?? '' ),
			'provider'      => $provider,
			'repeat'        => $repeat,
			'attempts'      => $attempt,
			'latency_ms'    => $latency,
			'result'        => null,
			'verdict'       => null,
			'error'         => 'classification_returned_null',
			'hard_error'    => true,
			'final_is_spam' => null,
		];
	}

	return [
		'case_id'       => (string) ( $case_data['id'] ?? '' ),
		'provider'      => $provider,
		'repeat'        => $repeat,
		'attempts'      => $attempt,
		'latency_ms'    => $latency,
		'result'        => [
			'is_spam'   => (bool) ( $result['is_spam'] ?? false ),
			'threshold' => isset( $result['threshold'] ) ? (float) $result['threshold'] : null,
		],
		'verdict'       => isset( $result['verdict'] ) && is_array( $result['verdict'] ) ? $result['verdict'] : null,
		'error'         => null,
		'hard_error'    => false,
		'final_is_spam' => (bool) ( $result['is_spam'] ?? false ),
	];
}

/**
 * Runs one evaluation with retry/backoff when no verdict is produced.
 *
 * @since 1.9.0
 *
 * @param GF_Zero_Spam_AI_Review $review         AI review runtime.
 * @param array                  $case_data      Corpus case.
 * @param string                 $provider       Provider ID.
 * @param int                    $provider_index Provider index.
 * @param int                    $case_index     Case index.
 * @param int                    $repeat         Repeat number.
 * @param int                    $retries        Maximum retry attempts.
 * @param int                    $backoff_ms     Base retry backoff in milliseconds.
 *
 * @return array Evaluation record.
 */
function gf_zero_spam_ai_eval_run_case_with_retries( $review, $case_data, $provider, $provider_index, $case_index, $repeat, $retries, $backoff_ms ) {
	$max_attempts = max( 1, $retries + 1 );
	$record       = [];

	for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
		if ( $attempt > 1 ) {
			gf_zero_spam_ai_eval_sleep_ms( $backoff_ms * ( 2 ** ( $attempt - 2 ) ) );
		}

		$form_id = gf_zero_spam_ai_eval_form_id( $provider_index, $case_index, $repeat, $attempt );
		$record  = gf_zero_spam_ai_eval_run_case( $review, $case_data, $provider, $form_id, $repeat, $provider_index, $attempt );

		if ( empty( $record['hard_error'] ) ) {
			return $record;
		}
	}

	return $record;
}

/**
 * Builds a synthetic form ID for a provider/case/repeat/attempt tuple.
 *
 * @since 1.9.0
 *
 * @param int $provider_index Provider index.
 * @param int $case_index     Case index.
 * @param int $repeat         Repeat number.
 * @param int $attempt        Attempt number.
 *
 * @return int Synthetic form ID.
 */
function gf_zero_spam_ai_eval_form_id( $provider_index, $case_index, $repeat, $attempt ) {
	return 900000 + ( $provider_index * 100000 ) + ( $case_index * 1000 ) + $repeat + ( ( $attempt - 1 ) * 10000000 );
}

/**
 * Sleeps for a number of milliseconds.
 *
 * @since 1.9.0
 *
 * @param int $milliseconds Milliseconds to sleep.
 *
 * @return void
 */
function gf_zero_spam_ai_eval_sleep_ms( $milliseconds ) {
	$milliseconds = max( 0, (int) $milliseconds );

	if ( $milliseconds < 1 ) {
		return;
	}

	usleep( $milliseconds * 1000 );
}

/**
 * Calculates provider metrics.
 *
 * @since 1.9.0
 *
 * @param array $cases            Corpus cases.
 * @param array $records          Evaluation records.
 * @param float $rescue_threshold Rescue confidence threshold.
 *
 * @return array Provider metrics.
 */
function gf_zero_spam_ai_eval_provider_metrics( $cases, $records, $rescue_threshold ) {
	$cases_by_id = [];

	foreach ( $cases as $case ) {
		$cases_by_id[ (string) ( $case['id'] ?? '' ) ] = $case;
	}

	$review_spam_total      = 0;
	$review_spam_correct    = 0;
	$review_ham_total       = 0;
	$review_ham_false_pos   = 0;
	$rescue_total           = 0;
	$rescue_above_threshold = 0;
	$rescue_spam_total      = 0;
	$rescue_false_rescues   = 0;

	foreach ( $records as $record ) {
		$case_id = (string) ( $record['case_id'] ?? '' );

		if ( ! isset( $cases_by_id[ $case_id ] ) ) {
			continue;
		}

		$case = $cases_by_id[ $case_id ];

		if ( isset( $case['include_in_metrics'] ) && false === (bool) $case['include_in_metrics'] ) {
			continue;
		}

		$scenario = (string) ( $case['scenario'] ?? 'review' );
		$label    = (string) ( $case['label'] ?? '' );

		if ( 'review' === $scenario && 'spam' === $label ) {
			++$review_spam_total;

			if ( ! empty( $record['final_is_spam'] ) ) {
				++$review_spam_correct;
			}
		}

		if ( 'review' === $scenario && 'ham' === $label ) {
			++$review_ham_total;

			if ( ! empty( $record['final_is_spam'] ) ) {
				++$review_ham_false_pos;
			}
		}

		if ( 'rescue' === $scenario && 'ham' === $label ) {
			++$rescue_total;
			$verdict = isset( $record['verdict'] ) && is_array( $record['verdict'] ) ? $record['verdict'] : [];

			if ( empty( $verdict['is_spam'] ) && isset( $verdict['confidence'] ) && (float) $verdict['confidence'] >= $rescue_threshold ) {
				++$rescue_above_threshold;
			}
		}

		if ( 'rescue' === $scenario && 'spam' === $label ) {
			++$rescue_spam_total;
			$verdict = isset( $record['verdict'] ) && is_array( $record['verdict'] ) ? $record['verdict'] : [];

			if ( empty( $verdict['is_spam'] ) && isset( $verdict['confidence'] ) && (float) $verdict['confidence'] >= $rescue_threshold ) {
				++$rescue_false_rescues;
			}
		}
	}

	return [
		'review_spam_recall'             => gf_zero_spam_ai_eval_ratio( $review_spam_correct, $review_spam_total ),
		'review_ham_false_positive_rate' => gf_zero_spam_ai_eval_ratio( $review_ham_false_pos, $review_ham_total ),
		'rescue_above_threshold_share'   => gf_zero_spam_ai_eval_ratio( $rescue_above_threshold, $rescue_total ),
		'rescue_false_rescue_rate'       => gf_zero_spam_ai_eval_ratio( $rescue_false_rescues, $rescue_spam_total ),
		'review_spam_cases'              => $review_spam_total,
		'review_ham_cases'               => $review_ham_total,
		'rescue_calibration_cases'       => $rescue_total,
		'rescue_spam_cases'              => $rescue_spam_total,
		'rescue_confidence_threshold'    => $rescue_threshold,
	];
}

/**
 * Calculates per-case confidence summaries.
 *
 * @since 1.9.0
 *
 * @param array $records Evaluation records.
 *
 * @return array Confidence summaries.
 */
function gf_zero_spam_ai_eval_confidence_summaries( $records ) {
	$values = [];

	foreach ( $records as $record ) {
		if ( empty( $record['verdict'] ) || ! is_array( $record['verdict'] ) || ! isset( $record['verdict']['confidence'] ) ) {
			continue;
		}

		$values[ (string) $record['case_id'] ][] = (float) $record['verdict']['confidence'];
	}

	$summaries = [];

	foreach ( $values as $case_id => $confidences ) {
		sort( $confidences, SORT_NUMERIC );
		$summaries[ $case_id ] = [
			'median' => gf_zero_spam_ai_eval_median( $confidences ),
			'min'    => min( $confidences ),
			'max'    => max( $confidences ),
		];
	}

	return $summaries;
}

/**
 * Calculates a safe ratio.
 *
 * @since 1.9.0
 *
 * @param int $numerator   Numerator.
 * @param int $denominator Denominator.
 *
 * @return float|null Ratio, or null when unavailable.
 */
function gf_zero_spam_ai_eval_ratio( $numerator, $denominator ) {
	if ( $denominator < 1 ) {
		return null;
	}

	return $numerator / $denominator;
}

/**
 * Calculates the median of numeric values.
 *
 * @since 1.9.0
 *
 * @param float[] $values Numeric values.
 *
 * @return float|null Median value.
 */
function gf_zero_spam_ai_eval_median( $values ) {
	$count = count( $values );

	if ( 0 === $count ) {
		return null;
	}

	$middle = (int) floor( $count / 2 );

	if ( 1 === $count % 2 ) {
		return $values[ $middle ];
	}

	return ( $values[ $middle - 1 ] + $values[ $middle ] ) / 2;
}

/**
 * Gets the current rescue confidence threshold for reporting.
 *
 * @since 1.9.0
 *
 * @param GF_Zero_Spam_AddOn $addon Add-on instance.
 *
 * @return float Rescue confidence threshold.
 */
function gf_zero_spam_ai_eval_rescue_threshold( $addon ) {
	$threshold = $addon->get_plugin_setting( 'gf_zero_spam_ai_rescue_confidence_threshold' );

	if ( ! is_numeric( $threshold ) ) {
		return GF_Zero_Spam_AI_Review::DEFAULT_RESCUE_CONFIDENCE_THRESHOLD;
	}

	$threshold = (float) $threshold;

	if ( $threshold < 0.5 || $threshold > 1 ) {
		return GF_Zero_Spam_AI_Review::DEFAULT_RESCUE_CONFIDENCE_THRESHOLD;
	}

	return $threshold;
}

/**
 * Writes the JSON report.
 *
 * @since 1.9.0
 *
 * @param array  $report Report data.
 * @param string $output Output path, or empty string for STDOUT.
 *
 * @throws BaseRuntimeException When the report cannot be encoded.
 *
 * @return void
 */
function gf_zero_spam_ai_eval_write_report( $report, $output ) {
	$json = wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

	if ( ! is_string( $json ) ) {
		throw new BaseRuntimeException( 'Could not encode JSON report.' );
	}

	if ( '' !== $output ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI report writer uses the explicit output path.
		file_put_contents( $output, $json . "\n" );
		return;
	}

	echo $json . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON report is machine-readable CLI output.
}

try {
	global $args, $argv;

	$raw_args = is_array( $args ) ? $args : [];

	if ( empty( $raw_args ) && is_array( $argv ) ) {
		$raw_args = $argv;
	}

	$args = gf_zero_spam_ai_eval_parse_args( $raw_args );

	if ( ! class_exists( 'GF_Zero_Spam_AddOn' ) || ! class_exists( 'GF_Zero_Spam_AI_Review' ) || ! class_exists( 'GF_Zero_Spam_AI_Review_Settings' ) ) {
		throw new BaseRuntimeException( 'Gravity Forms Zero Spam AI review classes are not loaded.' );
	}

	$cases            = gf_zero_spam_ai_eval_load_corpus( $args['corpus'] );
	$addon            = GF_Zero_Spam_AddOn::get_instance();
	$review           = new GF_Zero_Spam_AI_Review( $addon );
	$rescue_threshold = gf_zero_spam_ai_eval_rescue_threshold( $addon );
	$report           = [
		'generated_at'                => gmdate( 'c' ),
		'corpus'                      => $args['corpus'],
		'repeats'                     => $args['repeats'],
		'rescue_confidence_threshold' => $rescue_threshold,
		'providers'                   => [],
		'errors'                      => [],
	];

	// Force the shipped code default so evals are not affected by saved site settings.
	add_filter(
		'gf_zero_spam_ai_prompt',
		static function () {
			return GF_Zero_Spam_AI_Review_Settings::get_default_prompt();
		},
		PHP_INT_MAX,
		0
	);

	foreach ( $args['providers'] as $provider_index => $provider ) {
		$provider_filter = static function ( $provider_id, $context, $form, $entry ) use ( $provider ) {
			unset( $provider_id, $context, $form, $entry );

			return $provider;
		};
		$records         = [];

		add_filter( 'gf_zero_spam_ai_provider', $provider_filter, PHP_INT_MAX, 4 );

		for ( $repeat = 1; $repeat <= $args['repeats']; $repeat++ ) {
			foreach ( $cases as $case_index => $case ) {
				$records[] = gf_zero_spam_ai_eval_run_case_with_retries( $review, $case, (string) $provider, (int) $provider_index, (int) $case_index, $repeat, $args['retries'], $args['backoff_ms'] );
				gf_zero_spam_ai_eval_sleep_ms( $args['delay_ms'] );
			}
		}

		remove_filter( 'gf_zero_spam_ai_provider', $provider_filter, PHP_INT_MAX );

		foreach ( $records as $record ) {
			if ( ! empty( $record['hard_error'] ) ) {
				$report['errors'][] = [
					'provider' => $provider,
					'case_id'  => $record['case_id'],
					'error'    => $record['error'],
				];
			}
		}

		$report['providers'][ $provider ] = [
			'records'            => $records,
			'metrics'            => gf_zero_spam_ai_eval_provider_metrics( $cases, $records, $rescue_threshold ),
			'confidence_by_case' => gf_zero_spam_ai_eval_confidence_summaries( $records ),
		];
	}

	gf_zero_spam_ai_eval_write_report( $report, $args['output'] );

	if ( ! empty( $report['errors'] ) ) {
		exit( 1 );
	}
} catch ( BaseException $e ) {
	$error_report = [
		'generated_at' => gmdate( 'c' ),
		'errors'       => [
			[
				'error' => $e->getMessage(),
			],
		],
	];

	gf_zero_spam_ai_eval_write_report( $error_report, '' );
	exit( 1 );
}
