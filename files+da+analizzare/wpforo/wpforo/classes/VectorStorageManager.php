<?php

namespace wpforo\classes;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Vector Storage Manager - Unified Abstraction Layer
 *
 * Central manager that routes all vector storage operations to the appropriate
 * backend (Local WordPress DB or Cloud Storage) based on the current storage mode.
 *
 * All AI features that use indexed content should go through this abstraction:
 * - Content indexing
 * - Semantic search
 * - Statistics and status
 * - Similar content recommendations
 *
 * @since 3.0.0
 */
class VectorStorageManager {

	/**
	 * Storage mode constants
	 */
	const MODE_LOCAL = 'local';
	const MODE_CLOUD = 'cloud';

	/**
	 * Local storage instance
	 *
	 * @var VectorStorageLocal|null
	 */
	private $local_storage = null;

	/**
	 * AI Client instance (for cloud operations)
	 *
	 * @var AIClient|null
	 */
	private $ai_client = null;

	/**
	 * Current board ID
	 *
	 * @var int
	 */
	private $board_id = 0;

	/**
	 * Cached storage mode
	 *
	 * @var string|null
	 */
	private $storage_mode = null;

	/**
	 * Singleton instance
	 *
	 * @var VectorStorageManager|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance
	 *
	 * @return VectorStorageManager
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->board_id = WPF()->board->get_current( 'boardid' );
		$this->register_cron_hooks();
	}

	/**
	 * Register cron hooks for local storage maintenance
	 */
	private function register_cron_hooks() {
		// Register cleanup action - must be done here so callback exists when cron fires
		add_action( 'wpforo_ai_cleanup_expired_cache', [ $this, 'cleanup_expired_cache' ] );

		// Schedule if not already scheduled
		if ( ! wp_next_scheduled( 'wpforo_ai_cleanup_expired_cache' ) ) {
			wp_schedule_event( time(), 'hourly', 'wpforo_ai_cleanup_expired_cache' );
		}
	}

	/**
	 * Cleanup expired cache entries (cron callback)
	 */
	public function cleanup_expired_cache() {
		if ( $this->is_local_mode() ) {
			$local = $this->get_local_storage();
			$local->cleanup_expired_cache();
		}
	}

	/**
	 * Get the current storage mode for the board
	 *
	 * @param int|null $board_id Optional board ID (uses current if not specified)
	 * @return string 'local' or 'cloud'
	 */
	public function get_storage_mode( $board_id = null ) {
		if ( $board_id === null ) {
			$board_id = $this->board_id;
		}

		// Return cached value if same board
		if ( $this->storage_mode !== null && $board_id === $this->board_id ) {
			return $this->storage_mode;
		}

		$this->storage_mode = get_option( 'wpforo_ai_storage_mode_' . $board_id, self::MODE_LOCAL );
		return $this->storage_mode;
	}

	/**
	 * Check if using local storage mode
	 *
	 * @param int|null $board_id Optional board ID
	 * @return bool
	 */
	public function is_local_mode( $board_id = null ) {
		return $this->get_storage_mode( $board_id ) === self::MODE_LOCAL;
	}

	/**
	 * Check if using cloud storage mode
	 *
	 * @param int|null $board_id Optional board ID
	 * @return bool
	 */
	public function is_cloud_mode( $board_id = null ) {
		return $this->get_storage_mode( $board_id ) === self::MODE_CLOUD;
	}

	/**
	 * Get local storage instance
	 *
	 * @return VectorStorageLocal
	 */
	public function get_local_storage() {
		if ( $this->local_storage === null ) {
			$this->local_storage = new VectorStorageLocal();
		}
		return $this->local_storage;
	}

	/**
	 * Get AI client instance (for cloud operations)
	 *
	 * @return AIClient
	 */
	public function get_ai_client() {
		if ( $this->ai_client === null ) {
			$this->ai_client = WPF()->ai_client;
		}
		return $this->ai_client;
	}

	/**
	 * Set the board context
	 *
	 * @param int $board_id Board ID
	 * @return $this
	 */
	public function for_board( $board_id ) {
		$this->board_id = (int) $board_id;
		$this->storage_mode = null; // Reset cache
		return $this;
	}

	/**
	 * Reset cached storage mode
	 *
	 * Call this after changing the storage mode option to ensure
	 * subsequent calls use the new mode.
	 *
	 * @return void
	 */
	public function reset_storage_mode_cache() {
		$this->storage_mode = null;
	}

	// =========================================================================
	// STATISTICS & STATUS
	// =========================================================================

	/**
	 * Get indexing statistics
	 *
	 * Returns unified statistics regardless of storage mode.
	 *
	 * @return array {
	 *     @type int    $total_indexed     Total number of indexed items
	 *     @type int    $total_topics      Total topics with embeddings
	 *     @type int    $indexing_progress Progress percentage (0-100)
	 *     @type bool   $is_indexing       Whether indexing is in progress
	 *     @type string $last_indexed_at   ISO 8601 timestamp of last index
	 *     @type string $storage_mode      Current storage mode
	 *     @type string $storage_size      Storage size (local only)
	 * }
	 */
	public function get_indexing_stats() {
		if ( $this->is_local_mode() ) {
			return $this->get_local_stats();
		} else {
			return $this->get_cloud_stats();
		}
	}

	/**
	 * Get local storage statistics
	 *
	 * @return array
	 */
	private function get_local_stats() {
		$local = $this->get_local_storage();
		$stats = $local->get_stats();

		// Check for pending WP Cron jobs
		$pending_jobs = $this->get_pending_cron_jobs();

		return [
			'total_indexed'     => (int) ( $stats['total_embeddings'] ?? 0 ),
			'total_topics'      => (int) ( $stats['total_topics'] ?? 0 ),
			'indexing_progress' => 0, // Local doesn't track progress the same way
			'is_indexing'       => $pending_jobs['has_pending_jobs'],
			'last_indexed_at'   => $stats['last_indexed_at'] ?? null,
			'storage_mode'      => self::MODE_LOCAL,
			'storage_size'      => $stats['storage_size_mb'] ?? '0',
			'storage_size_mb'   => $stats['storage_size_mb'] ?? '0',
		];
	}

	/**
	 * Get cloud storage statistics
	 *
	 * @return array
	 */
	private function get_cloud_stats() {
		global $wpdb;

		$ai_client = $this->get_ai_client();
		$rag_status = $ai_client->get_rag_status( $this->board_id );

		if ( is_wp_error( $rag_status ) ) {
			$rag_status = [];
		}

		// Check for pending WP Cron jobs
		$pending_jobs = $this->get_pending_cron_jobs();
		$is_indexing = ( $rag_status['is_indexing'] ?? false ) || $pending_jobs['has_pending_jobs'];

		// Use local cloud column for accurate count (reflects manual changes)
		$total_indexed = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `" . WPF()->tables->topics . "` WHERE `cloud` = 1"
		);

		return [
			'total_indexed'     => $total_indexed,
			'total_topics'      => $total_indexed,
			'indexing_progress' => (int) ( $rag_status['indexing_progress'] ?? 0 ),
			'is_indexing'       => $is_indexing,
			'last_indexed_at'   => $rag_status['last_indexed_at'] ?? null,
			'storage_mode'      => self::MODE_CLOUD,
			'storage_size'      => null, // Cloud doesn't expose size
			'storage_size_mb'   => null,
		];
	}

	/**
	 * Get pending WP Cron jobs info
	 *
	 * @return array
	 */
	public function get_pending_cron_jobs() {
		$ai_client = $this->get_ai_client();
		return $ai_client->get_pending_cron_jobs();
	}

	/**
	 * Get storage recommendation for current forum size
	 *
	 * @return array {
	 *     @type string $status  'good', 'warning', or 'critical'
	 *     @type string $message Human-readable recommendation
	 *     @type string $icon    Dashicon name
	 * }
	 */
	public function get_storage_recommendation() {
		$local = $this->get_local_storage();
		return $local->get_storage_recommendation();
	}

	/**
	 * Get indexed counts grouped by forum
	 *
	 * @return array Forum ID => count mapping
	 */
	public function get_indexed_counts_by_forum() {
		if ( $this->is_local_mode() ) {
			return $this->get_local_indexed_counts_by_forum();
		} else {
			return $this->get_cloud_indexed_counts_by_forum();
		}
	}

	/**
	 * Get local indexed counts by forum
	 *
	 * @return array
	 */
	private function get_local_indexed_counts_by_forum() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT forumid, COUNT(DISTINCT topicid) as count
			 FROM " . WPF()->tables->ai_embeddings . "
			 GROUP BY forumid",
			ARRAY_A
		);

		$counts = [];
		foreach ( $results as $row ) {
			$counts[ (int) $row['forumid'] ] = (int) $row['count'];
		}

		return $counts;
	}

	/**
	 * Get cloud indexed counts by forum
	 *
	 * Uses the local `cloud` column from wpforo_topics table which is
	 * synced from the cloud API when switching storage modes.
	 *
	 * @return array
	 */
	private function get_cloud_indexed_counts_by_forum() {
		global $wpdb;

		$results = $wpdb->get_results(
			"SELECT forumid, COUNT(*) as count
			 FROM " . WPF()->tables->topics . "
			 WHERE `cloud` = 1
			 GROUP BY forumid",
			ARRAY_A
		);

		$counts = [];
		foreach ( $results as $row ) {
			$counts[ (int) $row['forumid'] ] = (int) $row['count'];
		}

		return $counts;
	}

	// =========================================================================
	// INDEXING OPERATIONS
	// =========================================================================

	/**
	 * Index a single topic with all its posts
	 *
	 * @param int   $topicid   Topic ID
	 * @param array $options   Optional indexing options
	 * @return array|WP_Error Result or error
	 */
	public function index_topic( $topicid, $options = [] ) {
		if ( $this->is_local_mode() ) {
			return $this->index_topic_local( $topicid, $options );
		} else {
			return $this->index_topic_cloud( $topicid, $options );
		}
	}

	/**
	 * Index topic to local storage
	 *
	 * @param int   $topicid Topic ID
	 * @param array $options Options
	 * @return array|WP_Error
	 */
	private function index_topic_local( $topicid, $options = [] ) {
		$topic = WPF()->topic->get_topic( $topicid );
		if ( ! $topic ) {
			return new \WP_Error( 'topic_not_found', wpforo_phrase( 'Topic not found', false ) );
		}

		// Skip private topics - they should never be indexed
		if ( ! empty( $topic['private'] ) ) {
			return new \WP_Error( 'private_topic', wpforo_phrase( 'Private topics cannot be indexed', false ) );
		}

		// Skip unapproved topics
		if ( isset( $topic['status'] ) && (int) $topic['status'] !== 0 ) {
			return new \WP_Error( 'unapproved_topic', wpforo_phrase( 'Unapproved topics cannot be indexed', false ) );
		}

		// Get all posts for this topic ordered by creation date
		$posts = WPF()->post->get_posts( [
			'topicid' => $topicid,
			'orderby' => 'created',
			'order'   => 'ASC',
		] );
		if ( empty( $posts ) ) {
			return new \WP_Error( 'no_posts', wpforo_phrase( 'No posts found for topic', false ) );
		}

		// Check if image indexing is enabled (Professional+ plans)
		$ai_client          = $this->get_ai_client();
		$include_images     = $ai_client->is_image_indexing_enabled();
		$images_processed   = 0;

		$local = $this->get_local_storage();
		$indexed_count = 0;
		$errors = [];
		$is_first = true;

		foreach ( $posts as $post ) {
			// Mark first post for special handling
			$post['is_first_post'] = $is_first;
			$is_first = false;

			// Generate content for embedding
			$content = $this->prepare_content_for_embedding( $post, $topic );

			// Extract images if image indexing is enabled
			$images = [];
			if ( $include_images && ! empty( $post['body'] ) ) {
				$images = $ai_client->extract_post_images( $post['body'] );
			}

			// Use content hash for deduplication
			// Include image count in hash so re-index happens when images are added/removed
			$hash_input   = $content . '|images:' . count( $images );
			$content_hash = md5( $hash_input );

			// Check if already indexed with same content
			$existing = $local->get_embedding( $post['postid'] );
			if ( $existing && $existing['content_hash'] === $content_hash ) {
				continue; // Skip, already indexed
			}

			// Generate embedding via cloud API
			// If images provided, API will process them and return processed_content
			if ( ! empty( $images ) ) {
				$embedding_result = $this->generate_embedding(
					$content,
					$images,
					$topic['title'],
					true // Return full response to get processed_content
				);

				if ( is_wp_error( $embedding_result ) ) {
					$errors[] = $embedding_result->get_error_message();
					continue;
				}

				// Track image processing stats
				if ( ! empty( $embedding_result['image_processing']['images_processed'] ) ) {
					$images_processed += $embedding_result['image_processing']['images_processed'];
				}

				// Use processed_content for preview if available
				$preview_content = $embedding_result['processed_content'] ?? $content;
				$embedding       = $embedding_result['embedding'];
			} else {
				// No images, simple embedding generation
				$embedding = $this->generate_embedding( $content );
				if ( is_wp_error( $embedding ) ) {
					$errors[] = $embedding->get_error_message();
					continue;
				}
				$preview_content = $content;
			}

			// Store locally
			$result = $local->store_embedding(
				$topicid,
				$post['postid'],
				$topic['forumid'],
				$post['userid'],
				$embedding,
				$content_hash,
				mb_substr( strip_tags( $preview_content ), 0, 510 )
			);

			if ( $result ) {
				$indexed_count++;
			}
		}

		$response = [
			'success'       => true,
			'indexed_count' => $indexed_count,
			'total_posts'   => count( $posts ),
			'errors'        => $errors,
		];

		// Add image processing stats if images were processed
		if ( $images_processed > 0 ) {
			$response['images_processed'] = $images_processed;
		}

		return $response;
	}

	/**
	 * Index topic to cloud storage
	 *
	 * @param int   $topicid Topic ID
	 * @param array $options Options
	 * @return array|WP_Error
	 */
	private function index_topic_cloud( $topicid, $options = [] ) {
		$ai_client = $this->get_ai_client();

		// Use existing cloud indexing flow via cron
		$topic = WPF()->topic->get_topic( $topicid );
		if ( ! $topic ) {
			return new \WP_Error( 'topic_not_found', wpforo_phrase( 'Topic not found', false ) );
		}

		// Skip private topics - they should never be indexed
		if ( ! empty( $topic['private'] ) ) {
			return new \WP_Error( 'private_topic', wpforo_phrase( 'Private topics cannot be indexed', false ) );
		}

		// Skip unapproved topics
		if ( isset( $topic['status'] ) && (int) $topic['status'] !== 0 ) {
			return new \WP_Error( 'unapproved_topic', wpforo_phrase( 'Unapproved topics cannot be indexed', false ) );
		}

		// Queue for background processing (existing behavior)
		$result = $ai_client->queue_topic_for_indexing( $topicid, $this->board_id );

		return $result;
	}

	/**
	 * Index multiple topics using batch embedding API
	 *
	 * Efficient method that collects all posts from multiple topics,
	 * generates embeddings in a single API call, and stores locally.
	 * This matches the cloud indexing pattern for efficiency.
	 *
	 * @param array $topic_ids Array of topic IDs to index
	 * @param array $options   Optional indexing options
	 * @return array Result with counts
	 */
	public function index_topics_batch_local( $topic_ids, $options = [] ) {
		if ( empty( $topic_ids ) ) {
			return [
				'success'        => true,
				'indexed_count'  => 0,
				'skipped_count'  => 0,
				'total_posts'    => 0,
				'errors'         => [],
			];
		}

		$local = $this->get_local_storage();
		$ai_client = $this->get_ai_client();

		// Check if image indexing is enabled (Professional+ plans)
		$include_images = $ai_client->is_image_indexing_enabled();

		// Collect all posts from all topics
		$items_to_embed = [];       // Items without images (batch endpoint)
		$items_with_images = [];    // Items with images (single endpoint)
		$post_metadata = [];        // Metadata for storing after embedding
		$skipped_count = 0;
		$topics_with_embeddings = [];  // Topics that have at least one indexed post

		foreach ( $topic_ids as $topicid ) {
			$topic = WPF()->topic->get_topic( $topicid );
			if ( ! $topic ) {
				continue;
			}

			// Skip private topics - they should never be indexed
			if ( ! empty( $topic['private'] ) ) {
				$skipped_count++;
				continue;
			}

			// Skip unapproved topics
			if ( isset( $topic['status'] ) && (int) $topic['status'] !== 0 ) {
				$skipped_count++;
				continue;
			}

			$posts = WPF()->post->get_posts( [
				'topicid' => $topicid,
				'orderby' => 'created',
				'order'   => 'ASC',
			] );

			if ( empty( $posts ) ) {
				continue;
			}

			$is_first = true;
			foreach ( $posts as $post ) {
				$post['is_first_post'] = $is_first;
				$is_first = false;

				// Prepare content for embedding
				$content = $this->prepare_content_for_embedding( $post, $topic );
				$post_id = $post['postid'];

				// Extract images if image indexing is enabled
				$images = [];
				if ( $include_images && ! empty( $post['body'] ) ) {
					$images = $ai_client->extract_post_images( $post['body'] );
				}

				// Include image count in hash so re-index happens when images are added/removed
				$hash_input   = $content . '|images:' . count( $images );
				$content_hash = md5( $hash_input );

				// Check if already indexed with same content (deduplication)
				$existing = $local->get_embedding( $post_id );
				if ( $existing && $existing['content_hash'] === $content_hash ) {
					$skipped_count++;
					// Track that this topic has at least one indexed post
					$topics_with_embeddings[ $topicid ] = true;
					continue;
				}

				// Store metadata for later
				$item_id = 'post_' . $post_id;
				$post_metadata[ $item_id ] = [
					'topicid'       => $topicid,
					'postid'        => $post_id,
					'forumid'       => $topic['forumid'],
					'userid'        => $post['userid'],
					'content_hash'  => $content_hash,
					'preview'       => mb_substr( strip_tags( $content ), 0, 510 ),
					'topic_title'   => $topic['title'],
				];

				// Separate posts with images from posts without
				if ( ! empty( $images ) ) {
					$items_with_images[] = [
						'id'      => $item_id,
						'content' => $content,
						'images'  => $images,
					];
				} else {
					$items_to_embed[] = [
						'id'      => $item_id,
						'content' => $content,
					];
				}
			}
		}

		// If nothing to embed, update indexed hashes for topics with existing embeddings and return
		if ( empty( $items_to_embed ) && empty( $items_with_images ) ) {
			// Still update indexed hashes for topics that have existing embeddings
			// This ensures stats show correct count even if re-indexing finds no changes
			if ( ! empty( $topics_with_embeddings ) ) {
				$this->update_topics_indexed_hash( array_keys( $topics_with_embeddings ) );
			}

			return [
				'success'        => true,
				'indexed_count'  => 0,
				'skipped_count'  => $skipped_count,
				'total_posts'    => $skipped_count,
				'errors'         => [],
				'message'        => sprintf( wpforo_phrase( 'All %d posts already indexed (unchanged).', false ), $skipped_count ),
			];
		}

		$all_results = [];
		$errors = [];
		$total_credits_used = 0;
		$images_processed = 0;

		// =================================================================
		// STEP 1: Process posts WITH images via single endpoint
		// Single endpoint supports image analysis, batch endpoint does not
		// =================================================================
		if ( ! empty( $items_with_images ) ) {
			\wpforo_ai_log( 'debug', sprintf(
				'Processing %d posts with images via single endpoint',
				count( $items_with_images )
			), 'VectorStorage' );

			foreach ( $items_with_images as $item ) {
				$item_id = $item['id'];
				$meta = $post_metadata[ $item_id ] ?? null;

				if ( ! $meta ) {
					continue;
				}

				// Generate embedding with images via single endpoint
				$embedding_result = $this->generate_embedding(
					$item['content'],
					$item['images'],
					$meta['topic_title'],
					true // Return full response to get processed_content
				);

				if ( is_wp_error( $embedding_result ) ) {
					$errors[] = sprintf( 'Failed to embed %s: %s', $item_id, $embedding_result->get_error_message() );
					continue;
				}

				// Track image processing stats
				if ( ! empty( $embedding_result['image_processing']['images_processed'] ) ) {
					$images_processed += $embedding_result['image_processing']['images_processed'];
				}

				// Track credits from API response
				if ( isset( $embedding_result['credits_used'] ) ) {
					$total_credits_used += (int) $embedding_result['credits_used'];
				}

				// Use processed_content for preview if available (includes image descriptions)
				$preview_content = $embedding_result['processed_content'] ?? $item['content'];

				// Add to results in same format as batch endpoint
				$all_results[] = [
					'id'        => $item_id,
					'success'   => true,
					'embedding' => $embedding_result['embedding'],
					'preview'   => mb_substr( strip_tags( $preview_content ), 0, 510 ),
				];

				// Update metadata preview with image-enhanced content
				$post_metadata[ $item_id ]['preview'] = mb_substr( strip_tags( $preview_content ), 0, 510 );
			}
		}

		// =================================================================
		// STEP 2: Process posts WITHOUT images via batch endpoint
		// More efficient for text-only content
		// =================================================================
		if ( ! empty( $items_to_embed ) ) {
			// Chunk items to max 100 per API call (API limit)
			$item_chunks = array_chunk( $items_to_embed, 100, true );
			$api_call_index = 0;

			// Count unique topics that have text-only items (for credit calculation)
			$text_only_topic_ids = array_unique( array_map( function( $item ) use ( $post_metadata ) {
				return $post_metadata[ $item['id'] ]['topicid'] ?? 0;
			}, $items_to_embed ) );

			// Exclude topics that already had image posts processed (they were already charged)
			$image_topic_ids = array_unique( array_map( function( $item ) use ( $post_metadata ) {
				return $post_metadata[ $item['id'] ]['topicid'] ?? 0;
			}, $items_with_images ) );
			$text_only_topic_ids = array_diff( $text_only_topic_ids, $image_topic_ids );
			$actual_new_topics_count = count( $text_only_topic_ids );

			foreach ( $item_chunks as $chunk ) {
				// For credit charging: only charge topic_count on first chunk to avoid double-charging
				// Subsequent chunks are "free" since they're part of the same topic batch
				$chunk_topic_count = ( $api_call_index === 0 ) ? $actual_new_topics_count : 0;

				// DEBUG: Log what we're passing
				\wpforo_ai_log( 'debug', sprintf(
					'index_topics_batch_local chunk %d: batch_topics=%d, new_topics=%d, chunk_items=%d, chunk_topic_count=%d',
					$api_call_index,
					count( $topic_ids ),
					$actual_new_topics_count,
					count( $chunk ),
					$chunk_topic_count
				), 'VectorStorage' );

				$response = $ai_client->generate_embeddings_batch( array_values( $chunk ), $chunk_topic_count );

				if ( is_wp_error( $response ) ) {
					$errors[] = sprintf( 'Chunk %d failed: %s', $api_call_index + 1, $response->get_error_message() );
				} elseif ( ! empty( $response['results'] ) ) {
					$all_results = array_merge( $all_results, $response['results'] );
					// Accumulate credits from each successful API call
					$credits_from_api = (int) ( $response['credits_used'] ?? 0 );
					$total_credits_used += $credits_from_api;

					\wpforo_ai_log( 'debug', sprintf(
						'API response chunk %d: credits_used=%d, successful=%d, failed=%d',
						$api_call_index,
						$credits_from_api,
						$response['successful_items'] ?? 0,
						$response['failed_items'] ?? 0
					), 'VectorStorage' );
				}

				$api_call_index++;
			}
		}

		// If all processing failed, return error
		$total_items = count( $items_to_embed ) + count( $items_with_images );
		if ( empty( $all_results ) && ! empty( $errors ) ) {
			// Log error to AILogs database
			if ( isset( WPF()->ai_logs ) ) {
				$user_type = AILogs::USER_TYPE_USER;
				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					$user_type = AILogs::USER_TYPE_CRON;
				} elseif ( ! get_current_user_id() ) {
					$user_type = AILogs::USER_TYPE_SYSTEM;
				}

				WPF()->ai_logs->log( [
					'action_type'      => AILogs::ACTION_CONTENT_INDEXING,
					'user_type'        => $user_type,
					'credits_used'     => 0,
					'status'           => AILogs::STATUS_ERROR,
					'content_type'     => 'topic',
					'request_summary'  => sprintf( 'Local indexing: %d topics', count( $topic_ids ) ),
					'error_message'    => implode( '; ', array_slice( $errors, 0, 5 ) ), // First 5 errors
					'extra_data'       => wp_json_encode( [
						'storage_mode' => 'local',
						'topic_ids'    => array_slice( $topic_ids, 0, 20 ),
						'errors_count' => count( $errors ),
					] ),
				] );
			}

			return [
				'success'        => false,
				'indexed_count'  => 0,
				'skipped_count'  => $skipped_count,
				'total_posts'    => $total_items + $skipped_count,
				'errors'         => $errors,
			];
		}

		// Store all embeddings locally
		$indexed_count = 0;

		// Track which topics had posts successfully indexed
		$topics_indexed = [];

		foreach ( $all_results as $result ) {
			$item_id = $result['id'];

			if ( ! $result['success'] || empty( $result['embedding'] ) ) {
				$errors[] = sprintf( 'Failed to embed %s: %s', $item_id, $result['error'] ?? 'Unknown error' );
				continue;
			}

			$meta = $post_metadata[ $item_id ] ?? null;
			if ( ! $meta ) {
				continue;
			}

			$stored = $local->store_embedding(
				$meta['topicid'],
				$meta['postid'],
				$meta['forumid'],
				$meta['userid'],
				$result['embedding'],
				$meta['content_hash'],
				$meta['preview']
			);

			if ( $stored ) {
				$indexed_count++;
				// Track this topic as having indexed posts
				$topics_indexed[ $meta['topicid'] ] = true;
			}
		}

		// Update indexed hash in wpforo_topics table for all topics with indexed content
		// This includes both newly indexed topics AND topics with existing embeddings (skipped)
		// Needed for:
		// 1. Statistics to show correct indexed topic count
		// 2. Summarization feature to know which topics have indexed content
		// 3. Deduplication - to skip unchanged topics on re-indexing
		$all_topics_with_content = array_keys( $topics_indexed + $topics_with_embeddings );
		if ( ! empty( $all_topics_with_content ) ) {
			$this->update_topics_indexed_hash( $all_topics_with_content );
		}

		$response = [
			'success'        => true,
			'indexed_count'  => $indexed_count,
			'skipped_count'  => $skipped_count,
			'total_posts'    => $total_items + $skipped_count,
			'credits_used'   => $total_credits_used > 0 ? $total_credits_used : count( $topic_ids ),
			'errors'         => $errors,
		];

		// Add image processing stats if images were processed
		if ( $images_processed > 0 ) {
			$response['images_processed'] = $images_processed;
		}

		// Log to AILogs database for tracking
		if ( $indexed_count > 0 && isset( WPF()->ai_logs ) ) {
			$user_type = AILogs::USER_TYPE_USER;
			if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
				$user_type = AILogs::USER_TYPE_CRON;
			} elseif ( ! get_current_user_id() ) {
				$user_type = AILogs::USER_TYPE_SYSTEM;
			}

			WPF()->ai_logs->log( [
				'action_type'      => AILogs::ACTION_CONTENT_INDEXING,
				'user_type'        => $user_type,
				'credits_used'     => $response['credits_used'] ?? 0,
				'status'           => AILogs::STATUS_SUCCESS,
				'content_type'     => 'topic',
				'request_summary'  => sprintf( 'Local indexing: %d topics', count( $topic_ids ) ),
				'response_summary' => sprintf(
					'Indexed %d posts from %d topics (%d skipped)',
					$indexed_count,
					count( $all_topics_with_content ),
					$skipped_count
				),
				'extra_data'       => wp_json_encode( [
					'storage_mode'    => 'local',
					'topic_ids'       => array_slice( $topic_ids, 0, 20 ), // Limit to first 20 for log size
					'topics_indexed'  => count( $all_topics_with_content ),
					'posts_indexed'   => $indexed_count,
					'skipped'         => $skipped_count,
					'errors_count'    => count( $errors ),
				] ),
			] );
		}

		return $response;
	}

	/**
	 * Prepare content for embedding
	 *
	 * Combines post content with relevant metadata for better semantic matching.
	 *
	 * @param array $post  Post data
	 * @param array $topic Topic data
	 * @return string Prepared content
	 */
	public function prepare_content_for_embedding( $post, $topic ) {
		// Max content length for embedding API (Titan Embed v2 handles ~32K chars / 8K tokens)
		$max_content_length = 45000;

		$parts = [];

		// Add topic title for context (especially for first post)
		if ( isset( $post['is_first_post'] ) && $post['is_first_post'] ) {
			$parts[] = 'Topic: ' . $topic['title'];
		}

		// Add post content (cleaned)
		$content = $post['body'] ?? '';
		// Strip shortcodes (both WordPress and wpForo shortcodes like [attach]ID[/attach])
		$content = strip_shortcodes( $content );
		$content = preg_replace( '/\[(?:\/)?[a-zA-Z0-9_-]+(?:\s[^\]]*?)?\]/', '', $content );
		$content = wp_strip_all_tags( $content );
		$content = html_entity_decode( $content, ENT_QUOTES, 'UTF-8' );
		$content = preg_replace( '/\s+/', ' ', $content );
		$parts[] = trim( $content );

		// Add tags if available (first post only)
		if ( isset( $post['is_first_post'] ) && $post['is_first_post'] && ! empty( $topic['tags'] ) ) {
			$tags = is_array( $topic['tags'] ) ? implode( ', ', $topic['tags'] ) : $topic['tags'];
			$parts[] = 'Tags: ' . $tags;
		}

		// Repeat title at the end to increase its weight in the embedding.
		// This improves title-based search (topic suggestions) where users type
		// a short title and need to match against long content embeddings.
		if ( isset( $post['is_first_post'] ) && $post['is_first_post'] && ! empty( $topic['title'] ) ) {
			$parts[] = 'Topic: ' . $topic['title'];
		}

		$result = implode( "\n\n", array_filter( $parts ) );

		// Truncate to max length if needed (embedding model and API limits)
		if ( mb_strlen( $result ) > $max_content_length ) {
			$result = mb_substr( $result, 0, $max_content_length );
		}

		return $result;
	}

	/**
	 * Update indexed hash for topics after successful local indexing
	 *
	 * The indexed hash is an MD5 of "topicid_postcount" which changes when posts are added/removed.
	 * This enables:
	 * 1. Statistics to show correct indexed topic count
	 * 2. Summarization feature to use indexed content
	 * 3. Deduplication - skip unchanged topics on re-indexing
	 *
	 * @param array $topic_ids Array of topic IDs that were indexed
	 * @return int Number of topics updated
	 */
	private function update_topics_indexed_hash( $topic_ids ) {
		if ( empty( $topic_ids ) ) {
			return 0;
		}

		global $wpdb;

		// Build CASE statement for indexed hash updates
		// Hash is MD5 of "topicid_postcount"
		$topic_ids = array_map( 'intval', $topic_ids );
		$ids_list = implode( ',', $topic_ids );

		// Update indexed hash and local column in a single query
		// The hash is calculated as MD5(topicid + '_' + posts)
		$updated = $wpdb->query(
			"UPDATE `" . WPF()->tables->topics . "`
			SET `indexed` = MD5(CONCAT(topicid, '_', posts)),
			    `local` = 1
			WHERE topicid IN ($ids_list)"
		);

		if ( $updated > 0 ) {
			// Clear topic cache to reflect indexed status
			wpforo_clean_cache( 'topic' );
		}

		return (int) $updated;
	}

	/**
	 * Generate embedding vector for content
	 *
	 * Uses the cloud API to generate embeddings (Bedrock Titan).
	 * This is used for both local and cloud storage modes.
	 *
	 * Supports multimodal image indexing (Professional+ plans):
	 * - Pass images array with URLs from site domain
	 * - Images are processed by vision models on Lambda
	 * - Returns full response including processed_content if images were processed
	 *
	 * @param string $content       Content to embed
	 * @param array  $images        Optional. Array of image data for multimodal indexing
	 * @param string $topic_context Optional. Topic title for better image descriptions
	 * @param bool   $full_response Optional. Return full response instead of just embedding
	 * @return array|WP_Error Vector array (default) or full response array if $full_response=true
	 */
	public function generate_embedding( $content, $images = [], $topic_context = '', $full_response = false ) {
		$ai_client = $this->get_ai_client();

		// Call cloud API to generate embedding (with optional images)
		$result = $ai_client->generate_embedding( $content, $images, $topic_context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! isset( $result['embedding'] ) || ! is_array( $result['embedding'] ) ) {
			return new \WP_Error( 'invalid_embedding', wpforo_phrase( 'Invalid embedding response from API', false ) );
		}

		// Return full response if requested (for image processing info)
		if ( $full_response ) {
			return $result;
		}

		return $result['embedding'];
	}

	/**
	 * Delete embeddings for a topic
	 *
	 * @param int $topicid Topic ID
	 * @return bool|WP_Error
	 */
	public function delete_topic_embeddings( $topicid ) {
		if ( $this->is_local_mode() ) {
			$local = $this->get_local_storage();
			return $local->delete_topic_embeddings( $topicid );
		} else {
			$ai_client = $this->get_ai_client();
			return $ai_client->delete_topic_from_index( $topicid, $this->board_id );
		}
	}

	/**
	 * Delete embedding for a single post
	 *
	 * @param int $postid Post ID
	 * @return bool|WP_Error
	 */
	public function delete_post_embedding( $postid ) {
		if ( $this->is_local_mode() ) {
			$local = $this->get_local_storage();
			return $local->delete_embedding( $postid );
		} else {
			$ai_client = $this->get_ai_client();
			return $ai_client->delete_post_from_index( $postid, $this->board_id );
		}
	}

	/**
	 * Clear all embeddings for current board
	 *
	 * @return bool|WP_Error
	 */
	public function clear_all_embeddings() {
		if ( $this->is_local_mode() ) {
			global $wpdb;
			$wpdb->query( "TRUNCATE TABLE " . WPF()->tables->ai_embeddings );
			$wpdb->query( "TRUNCATE TABLE " . WPF()->tables->ai_embeddings_cache );
			// Also clear indexed status and local column in topics table so topics can be re-indexed
			WPF()->db->query(
				"UPDATE `" . WPF()->tables->topics . "` SET `indexed` = NULL, `local` = 0 WHERE `indexed` IS NOT NULL OR `local` = 1"
			);
			wpforo_clean_cache( 'topic' );
			return true;
		} else {
			$ai_client = $this->get_ai_client();
			return $ai_client->clear_rag_database( $this->board_id );
		}
	}

	/**
	 * Ingest multiple topics
	 *
	 * For local mode, indexes topics directly.
	 * For cloud mode, sends to cloud API.
	 *
	 * @param array $topic_ids     Array of topic IDs
	 * @param int   $chunk_size    Chunk size (used for cloud mode)
	 * @param int   $overlap_percent Overlap percentage (used for cloud mode)
	 * @return array|WP_Error Result array or error
	 */
	public function ingest_topics( $topic_ids, $chunk_size = 512, $overlap_percent = 20 ) {
		if ( $this->is_local_mode() ) {
			return $this->ingest_topics_local( $topic_ids );
		} else {
			return $this->ingest_topics_cloud( $topic_ids, $chunk_size, $overlap_percent );
		}
	}

	/**
	 * Ingest topics locally via WP Cron batches
	 *
	 * Uses batch embedding API for efficiency - multiple topics processed
	 * in a single API call, matching cloud indexing pattern.
	 *
	 * @param array $topic_ids Topic IDs to index
	 * @return array Result with success status and scheduled counts
	 */
	private function ingest_topics_local( $topic_ids ) {
		$total_topics = count( $topic_ids );

		if ( empty( $topic_ids ) ) {
			return [
				'success'       => true,
				'topics_queued' => 0,
				'message'       => wpforo_phrase( 'No topics to index.', false ),
			];
		}

		// Check available credits before starting
		$ai_client = $this->get_ai_client();
		$status = $ai_client->get_tenant_status( true ); // Force fresh status
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$credits_available = isset( $status['subscription']['credits_remaining'] )
			? (int) $status['subscription']['credits_remaining']
			: 0;

		if ( $credits_available <= 0 ) {
			return new \WP_Error(
				'no_credits',
				wpforo_phrase( 'No credits available for indexing. Please wait for your monthly credit reset or upgrade your plan.', false )
			);
		}

		// Use self-rescheduling queue pattern:
		// - Store all topic IDs in a queue (option)
		// - Schedule ONE cron job
		// - Job processes a batch, then reschedules itself if more remain
		// This avoids overwhelming WP Cron with hundreds of jobs
		$queue_key = 'wpforo_ai_indexing_queue_' . $this->board_id;

		// Get existing queue and merge (in case of concurrent requests)
		$existing_queue = get_option( $queue_key, [] );
		$merged_queue = array_unique( array_merge( $existing_queue, $topic_ids ) );
		update_option( $queue_key, $merged_queue, false ); // No autoload

		// Schedule ONE job to start processing (if not already scheduled)
		$cron_hook = 'wpforo_ai_process_queue';
		$cron_args = [ $this->board_id ];

		if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
			wp_schedule_single_event( time() + 5, $cron_hook, $cron_args );
		}

		return [
			'success'          => true,
			'threads_indexed'  => 0, // Will be processed async
			'posts_indexed'    => 0,
			'posts_unchanged'  => 0,
			'errors'           => [],
			'credits_consumed' => 0, // Credits consumed during cron processing
			'topics_queued'    => $total_topics,
			'batches_queued'   => 1, // Always just 1 job that self-reschedules
			'message'          => sprintf(
				wpforo_phrase( 'Indexing queued! %d topics will be processed in batches. Processing starts in 5 seconds.', false ),
				$total_topics
			),
			'stats' => [
				'deduplication' => [
					'posts_unchanged' => 0,
				],
			],
		];
	}

	/**
	 * Ingest topics to cloud storage via batch+cron pattern
	 *
	 * For small batches (within pagination_size), processes synchronously.
	 * For large batches, processes the first batch synchronously and schedules
	 * remaining batches via WP Cron using the same wpforo_ai_process_batch hook
	 * that reindex_all_topics() uses. This prevents Lambda's 6 MB request limit
	 * from being exceeded when indexing thousands of topics.
	 *
	 * All existing UI infrastructure (progress polling, stop button, auto-refresh)
	 * works automatically because we reuse the same cron hook.
	 *
	 * @param array $topic_ids       Array of topic IDs
	 * @param int   $chunk_size      Chunk size for text splitting in tokens
	 * @param int   $overlap_percent Overlap percentage for chunking
	 * @return array|WP_Error Result array or error
	 */
	private function ingest_topics_cloud( $topic_ids, $chunk_size = 512, $overlap_percent = 20 ) {
		$total_topics = count( $topic_ids );

		if ( empty( $topic_ids ) ) {
			return [
				'success'       => true,
				'topics_queued' => 0,
				'message'       => wpforo_phrase( 'No topics to index.', false ),
			];
		}

		$ai_client = $this->get_ai_client();

		// Check if database clearing is in progress
		if ( $ai_client->is_clearing_in_progress() ) {
			$remaining = $ai_client->get_clearing_time_remaining();
			$minutes = ceil( $remaining / 60 );
			return new \WP_Error(
				'clearing_in_progress',
				sprintf(
					wpforo_phrase( 'Database clearing is in progress. Please wait approximately %d minute(s) before starting new indexing.', false ),
					$minutes
				)
			);
		}

		// Check available credits before starting
		$status = $ai_client->get_tenant_status( true );
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$credits_available = isset( $status['subscription']['credits_remaining'] )
			? (int) $status['subscription']['credits_remaining']
			: 0;

		if ( $credits_available <= 0 ) {
			return new \WP_Error(
				'no_credits',
				wpforo_phrase( 'No credits available for indexing. Please wait for your monthly credit reset or upgrade your plan.', false )
			);
		}

		// Limit topics to available credits
		$topics_limited = false;
		$skipped_topics = 0;
		if ( $total_topics > $credits_available ) {
			$topic_ids = array_slice( $topic_ids, 0, $credits_available );
			$topics_limited = true;
			$skipped_topics = $total_topics - $credits_available;
			$total_topics = count( $topic_ids );
		}

		// Split into batches using pagination_size setting (same as reindex_all_topics)
		$pagination_size = (int) wpforo_get_option( 'ai_pagination_size', 20 );
		$api_batches = array_chunk( $topic_ids, $pagination_size );
		$total_batches = count( $api_batches );

		// Process first batch synchronously
		$first_batch = array_shift( $api_batches );
		$response = $ai_client->ingest_topics( $first_batch, $chunk_size, $overlap_percent );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// If only one batch, return immediately (no cron needed)
		if ( empty( $api_batches ) ) {
			$response['topics_queued'] = $total_topics;
			return $response;
		}

		// Schedule remaining batches via wpforo_ai_process_batch cron hook
		// This is the SAME hook that reindex_all_topics() uses, so all existing
		// UI infrastructure (progress polling, stop button, auto-refresh) works automatically
		$scheduled_count = 0;
		$batch_interval = $ai_client->get_cron_interval_for_plan();

		// Reset array keys after array_shift
		$api_batches = array_values( $api_batches );

		foreach ( $api_batches as $batch_index => $batch ) {
			$scheduled_time = time() + ( ( $batch_index + 1 ) * $batch_interval );
			$cron_args = [
				[
					'topic_ids'       => $batch,
					'chunk_size'      => $chunk_size,
					'overlap_percent' => $overlap_percent,
				]
			];

			$result = wp_schedule_single_event( $scheduled_time, 'wpforo_ai_process_batch', $cron_args );

			if ( $result !== false ) {
				$scheduled_count++;
			}
		}

		// Clear cached status to force refresh
		$ai_client->clear_rag_status_cache();

		// Build message
		if ( $topics_limited ) {
			$message = sprintf(
				wpforo_phrase( 'Indexing started: %1$d topics queued (limited by %2$d available credits). %3$d topics skipped.', false ),
				$total_topics,
				$credits_available,
				$skipped_topics
			);
		} else {
			$message = sprintf(
				wpforo_phrase( 'Indexing started: %d topics queued in %d batches.', false ),
				$total_topics,
				$total_batches
			);
		}

		return [
			'success'          => true,
			'message'          => $message,
			'topics_queued'    => $total_topics,
			'topics_limited'   => $topics_limited,
			'skipped_topics'   => $skipped_topics,
			'total_batches'    => $total_batches,
			'first_batch_sent' => count( $first_batch ),
			'scheduled_crons'  => $scheduled_count,
		];
	}

	/**
	 * Queue a single topic for auto-indexing
	 *
	 * Used for automatic indexing when:
	 * - A new approved topic is created
	 * - An unapproved topic is approved
	 *
	 * Adds the topic to the existing queue and schedules the cron processor
	 * if not already scheduled. This is a lightweight operation.
	 *
	 * @param int $topicid Topic ID to queue
	 * @return bool True if queued successfully
	 */
	public function queue_topic_for_auto_indexing( $topicid ) {
		// Check if auto-indexing is enabled for this board
		$auto_indexing_enabled = (bool) wpforo_get_option( 'ai_auto_indexing_enabled', 0 );
		if ( ! $auto_indexing_enabled ) {
			return false;
		}

		// Check if AI service is available
		$ai_client = $this->get_ai_client();
		if ( ! $ai_client->is_service_available() ) {
			return false;
		}

		// Verify topic exists and is approved (status = 0) and not private
		$topic = WPF()->topic->get_topic( $topicid );
		if ( ! $topic ) {
			return false;
		}

		// Only index approved (status=0), non-private topics
		if ( intval( wpfval( $topic, 'status' ) ) !== 0 || intval( wpfval( $topic, 'private' ) ) === 1 ) {
			return false;
		}

		// Use mode-specific queue key to ensure topics are indexed in the correct mode
		// This prevents cloud topics from being processed by local indexing and vice versa
		$storage_mode = $this->get_storage_mode();
		$queue_key = 'wpforo_ai_indexing_queue_' . $storage_mode . '_' . $this->board_id;

		// Get existing queue and add this topic (avoid duplicates)
		$existing_queue = get_option( $queue_key, [] );
		if ( ! in_array( $topicid, $existing_queue, true ) ) {
			$existing_queue[] = $topicid;
			update_option( $queue_key, $existing_queue, false ); // No autoload
		}

		// Schedule the mode-specific cron processor if not already scheduled
		$cron_hook = 'wpforo_ai_process_queue_' . $storage_mode;
		$cron_args = [ $this->board_id ];

		if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
			// Schedule to run in 30 seconds (gives time for multiple topics to accumulate)
			wp_schedule_single_event( time() + 30, $cron_hook, $cron_args );
		}

		$this->log_info( 'topic_queued_for_auto_indexing', [
			'topicid'      => $topicid,
			'queue_size'   => count( $existing_queue ),
			'storage_mode' => $storage_mode,
			'queue_key'    => $queue_key,
		] );

		return true;
	}

	/**
	 * Get topics that need indexing based on current storage mode
	 *
	 * Finds topics where the relevant indexed column is 0:
	 * - Local mode: topics with local = 0
	 * - Cloud mode: topics with cloud = 0
	 *
	 * Only returns approved (status=0), non-private topics.
	 *
	 * @param int $limit Maximum number of topics to return (default 100)
	 * @return array Array of topic IDs
	 */
	public function get_pending_topics_for_indexing( $limit = 100 ) {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';

		$topic_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT topicid FROM `" . WPF()->tables->topics . "`
				WHERE `{$column}` = 0
				AND `status` = 0
				AND `private` = 0
				ORDER BY topicid ASC
				LIMIT %d",
				$limit
			)
		);

		return array_map( 'intval', $topic_ids );
	}

	/**
	 * Process pending topics (daily cron job handler)
	 *
	 * Finds topics with local=0 or cloud=0 (based on storage mode)
	 * and queues them for indexing using mode-specific queue.
	 *
	 * @return array Result with counts
	 */
	public function cron_process_pending_topics() {
		// Check if auto-indexing is enabled for this board
		$auto_indexing_enabled = (bool) wpforo_get_option( 'ai_auto_indexing_enabled', 0 );
		if ( ! $auto_indexing_enabled ) {
			return [
				'success' => false,
				'message' => 'Auto-indexing is disabled',
				'topics_queued' => 0,
			];
		}

		// Check if AI service is available
		$ai_client = $this->get_ai_client();
		if ( ! $ai_client->is_service_available() ) {
			return [
				'success' => false,
				'message' => 'AI service not available',
				'topics_queued' => 0,
			];
		}

		// Get current storage mode - this determines which column to check
		$storage_mode = $this->get_storage_mode();

		// Get pending topics (limit to 500 per day to avoid overloading)
		// This checks local=0 for local mode, cloud=0 for cloud mode
		$pending_topics = $this->get_pending_topics_for_indexing( 500 );

		if ( empty( $pending_topics ) ) {
			return [
				'success' => true,
				'message' => 'No pending topics found',
				'topics_queued' => 0,
				'storage_mode' => $storage_mode,
			];
		}

		// Use mode-specific queue key to ensure topics are indexed in the correct mode
		$queue_key = 'wpforo_ai_indexing_queue_' . $storage_mode . '_' . $this->board_id;

		// Get existing queue and merge
		$existing_queue = get_option( $queue_key, [] );
		$merged_queue = array_unique( array_merge( $existing_queue, $pending_topics ) );
		update_option( $queue_key, $merged_queue, false );

		// Schedule the mode-specific cron processor if not already scheduled
		$cron_hook = 'wpforo_ai_process_queue_' . $storage_mode;
		$cron_args = [ $this->board_id ];

		if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
			wp_schedule_single_event( time() + 5, $cron_hook, $cron_args );
		}

		$this->log_info( 'daily_pending_topics_queued', [
			'topics_found'   => count( $pending_topics ),
			'queue_size'     => count( $merged_queue ),
			'storage_mode'   => $storage_mode,
			'queue_key'      => $queue_key,
		] );

		return [
			'success'       => true,
			'message'       => sprintf( 'Queued %d pending topics for %s indexing', count( $pending_topics ), $storage_mode ),
			'topics_queued' => count( $pending_topics ),
			'storage_mode'  => $storage_mode,
		];
	}

	/**
	 * Process the indexing queue (called by WP Cron)
	 *
	 * Self-rescheduling pattern: processes one batch at a time,
	 * then reschedules itself if more topics remain.
	 *
	 * @param int $board_id Board ID
	 * @return void
	 */
	public function cron_process_queue( $board_id = 0 ) {
		// Set the board context to ensure board-specific options are read correctly
		WPF()->change_board( $board_id );

		$lock_key = 'wpforo_ai_indexing_lock_' . $board_id;
		$queue_key = 'wpforo_ai_indexing_queue_' . $board_id;
		$batch_size = (int) wpforo_get_option( 'ai_pagination_size', 20 ); // Topics per batch from settings

		// Check if AJAX is actively processing - if so, skip this cron run
		// AJAX is faster and more reliable, cron is just a fallback for closed pages
		$existing_lock = get_transient( $lock_key );
		if ( $existing_lock === 'ajax' ) {
			// AJAX is handling the queue - don't interfere
			// Reschedule cron as backup in case AJAX stops
			$cron_hook = 'wpforo_ai_process_queue';
			$cron_args = [ $board_id ];
			if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
				wp_schedule_single_event( time() + 60, $cron_hook, $cron_args );
			}
			return;
		}

		// Set lock for cron processing
		set_transient( $lock_key, 'cron', 300 );

		// Get pending topics from queue
		$pending_topics = get_option( $queue_key, [] );

		if ( empty( $pending_topics ) ) {
			// Queue is empty, nothing to do
			delete_option( $queue_key );
			delete_transient( $lock_key );
			return;
		}

		// Take the next batch
		$batch = array_slice( $pending_topics, 0, $batch_size );
		$remaining = array_slice( $pending_topics, $batch_size );

		// Update queue with remaining topics BEFORE processing
		// This prevents re-processing if cron runs twice
		if ( ! empty( $remaining ) ) {
			update_option( $queue_key, $remaining, false );
		} else {
			delete_option( $queue_key );
		}

		// Use the batch embedding method with saved settings
		$chunk_size = (int) wpforo_get_option( 'ai_chunk_size', 512 );
		$overlap_percent = (int) wpforo_get_option( 'ai_overlap_percent', 20 );
		$result = $this->index_topics_batch_local( $batch, [
			'chunk_size'      => $chunk_size,
			'overlap_percent' => $overlap_percent,
		] );

		// Release lock after processing
		delete_transient( $lock_key );

		// If more topics remain, reschedule ourselves
		if ( ! empty( $remaining ) ) {
			$cron_hook = 'wpforo_ai_process_queue';
			$cron_args = [ $board_id ];

			// Schedule next batch in 30 seconds
			if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
				wp_schedule_single_event( time() + 30, $cron_hook, $cron_args );
			}
		}
	}

	/**
	 * Process a mode-specific auto-indexing queue (called by WP Cron)
	 *
	 * This processes topics that were queued for a specific storage mode,
	 * ensuring they are indexed with the correct method regardless of
	 * what the current storage mode is set to.
	 *
	 * @param int    $board_id Board ID
	 * @param string $mode     Storage mode ('local' or 'cloud')
	 * @return void
	 */
	public function cron_process_queue_mode( $board_id = 0, $mode = 'local' ) {
		// Set the board context to ensure board-specific options are read correctly
		WPF()->change_board( $board_id );

		$lock_key = 'wpforo_ai_indexing_lock_' . $mode . '_' . $board_id;
		$queue_key = 'wpforo_ai_indexing_queue_' . $mode . '_' . $board_id;
		$batch_size = (int) wpforo_get_option( 'ai_pagination_size', 20 ); // Topics per batch from settings

		// Check if already processing
		$existing_lock = get_transient( $lock_key );
		if ( $existing_lock ) {
			// Already processing - reschedule as backup
			$cron_hook = 'wpforo_ai_process_queue_' . $mode;
			$cron_args = [ $board_id ];
			if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
				wp_schedule_single_event( time() + 60, $cron_hook, $cron_args );
			}
			return;
		}

		// Set lock for processing
		set_transient( $lock_key, 'cron', 300 );

		// Get pending topics from mode-specific queue
		$pending_topics = get_option( $queue_key, [] );

		if ( empty( $pending_topics ) ) {
			// Queue is empty, nothing to do
			delete_option( $queue_key );
			delete_transient( $lock_key );
			return;
		}

		// Take the next batch
		$batch = array_slice( $pending_topics, 0, $batch_size );
		$remaining = array_slice( $pending_topics, $batch_size );

		// Update queue with remaining topics BEFORE processing
		if ( ! empty( $remaining ) ) {
			update_option( $queue_key, $remaining, false );
		} else {
			delete_option( $queue_key );
		}

		// Get chunk settings from options
		$chunk_size = (int) wpforo_get_option( 'ai_chunk_size', 512 );
		$overlap_percent = (int) wpforo_get_option( 'ai_overlap_percent', 20 );

		// Process using the specified mode
		if ( $mode === 'local' ) {
			// Use local batch embedding
			$result = $this->index_topics_batch_local( $batch, [
				'chunk_size'      => $chunk_size,
				'overlap_percent' => $overlap_percent,
			] );
		} else {
			// Use cloud indexing via ingest_topics
			$ai_client = $this->get_ai_client();
			$result = $ai_client->ingest_topics( $batch, $chunk_size, $overlap_percent );
		}

		$this->log_info( 'cron_process_queue_mode_complete', [
			'mode'       => $mode,
			'batch_size' => count( $batch ),
			'remaining'  => count( $remaining ),
			'result'     => is_wp_error( $result ) ? $result->get_error_message() : 'success',
		] );

		// Release lock after processing
		delete_transient( $lock_key );

		// If more topics remain, reschedule ourselves
		if ( ! empty( $remaining ) ) {
			$cron_hook = 'wpforo_ai_process_queue_' . $mode;
			$cron_args = [ $board_id ];

			// Schedule next batch in 30 seconds
			if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
				wp_schedule_single_event( time() + 30, $cron_hook, $cron_args );
			}
		}
	}

	/**
	 * Reindex all topics
	 *
	 * For local mode, schedules WP Cron jobs to index in batches.
	 * For cloud mode, sends to cloud API.
	 *
	 * @param int $chunk_size      Chunk size
	 * @param int $overlap_percent Overlap percentage
	 * @return array|WP_Error Result or error
	 */
	public function reindex_all_topics( $chunk_size = 512, $overlap_percent = 20 ) {
		if ( $this->is_local_mode() ) {
			return $this->reindex_all_topics_local();
		} else {
			$ai_client = $this->get_ai_client();
			return $ai_client->reindex_all_topics( $chunk_size, $overlap_percent );
		}
	}

	/**
	 * Clear local indexed status for all topics
	 *
	 * Sets `local` column to 0 for all topics without deleting actual embeddings.
	 * Used when user wants to force re-index all topics.
	 *
	 * @return int Number of topics updated
	 */
	private function clear_topics_local_indexed_status() {
		global $wpdb;

		$updated = $wpdb->query(
			"UPDATE `" . WPF()->tables->topics . "` SET `local` = 0 WHERE `local` = 1"
		);

		if ( $updated > 0 ) {
			wpforo_clean_cache( 'topic' );
		}

		return (int) $updated;
	}

	/**
	 * Reindex all topics locally via WP Cron
	 *
	 * Uses batch embedding API for efficiency.
	 * Supports incremental indexing: only indexes topics with local=0.
	 * If all topics are already indexed, clears status and re-indexes all.
	 *
	 * @return array Result with queued count
	 */
	private function reindex_all_topics_local() {
		// Get unindexed topics (local = 0)
		$unindexed_topic_ids = $this->get_unindexed_topic_ids();
		$unindexed_count = count( $unindexed_topic_ids );

		// Determine if we're doing incremental indexing or full re-index
		$is_reindex_all = ( $unindexed_count === 0 );

		if ( $is_reindex_all ) {
			// All topics are indexed - user wants to re-index everything
			// Clear status first so all topics become "unindexed"
			$this->clear_topics_local_indexed_status();

			// Now get all topic IDs (they're all local=0 now)
			$topic_ids = $this->get_unindexed_topic_ids();
		} else {
			// Some topics need indexing - only index those (don't clear status)
			$topic_ids = $unindexed_topic_ids;
		}

		$total_topics = count( $topic_ids );

		if ( $total_topics === 0 ) {
			return [
				'success'       => true,
				'topics_queued' => 0,
				'message'       => wpforo_phrase( 'No topics found to index.', false ),
			];
		}

		// Check available credits before starting (same as cloud mode)
		$ai_client = $this->get_ai_client();
		$status = $ai_client->get_tenant_status( true ); // Force fresh status
		if ( is_wp_error( $status ) ) {
			return $status;
		}

		$credits_available = isset( $status['subscription']['credits_remaining'] )
			? (int) $status['subscription']['credits_remaining']
			: 0;

		if ( $credits_available <= 0 ) {
			return new \WP_Error(
				'no_credits',
				wpforo_phrase( 'No credits available for indexing. Please wait for your monthly credit reset or upgrade your plan.', false )
			);
		}

		// Warn if credits are low but still proceed (deduplication may reduce actual usage)
		$credits_warning = null;
		if ( $credits_available < $total_topics ) {
			$credits_warning = sprintf(
				wpforo_phrase( 'Note: You have %d credits but %d topics to index. Unchanged topics will be skipped, but new topics may not all be indexed.', false ),
				$credits_available,
				$total_topics
			);
		}

		// Use self-rescheduling queue pattern:
		// - Store all topic IDs in a queue (option)
		// - Schedule ONE cron job
		// - Job processes a batch (pagination_size topics), then reschedules itself if more remain
		// This avoids overwhelming WP Cron with hundreds of jobs for large forums
		$queue_key = 'wpforo_ai_indexing_queue_' . $this->board_id;

		// Clear any existing queue and set new one
		update_option( $queue_key, $topic_ids, false ); // No autoload

		// Schedule ONE job to start processing (if not already scheduled)
		$cron_hook = 'wpforo_ai_process_queue';
		$cron_args = [ $this->board_id ];

		if ( ! wp_next_scheduled( $cron_hook, $cron_args ) ) {
			wp_schedule_single_event( time() + 5, $cron_hook, $cron_args );
		}

		$batch_size = (int) wpforo_get_option( 'ai_pagination_size', 20 );
		$message = sprintf(
			wpforo_phrase( 'Indexing queued! %d topics will be processed in batches of %d. Processing starts in 5 seconds.', false ),
			$total_topics,
			$batch_size
		);

		// Append credits warning if applicable
		if ( $credits_warning ) {
			$message .= ' ' . $credits_warning;
		}

		return [
			'success'           => true,
			'topics_queued'     => $total_topics,
			'total_topics'      => $total_topics,
			'batches_queued'    => 1, // Just ONE self-rescheduling job
			'credits_available' => $credits_available,
			'message'           => $message,
		];
	}

	// =========================================================================
	// SEMANTIC SEARCH
	// =========================================================================

	/**
	 * Perform semantic search
	 *
	 * @param string $query   Search query
	 * @param int    $limit   Maximum results
	 * @param array  $filters Optional filters (forumid, userid, etc.)
	 * @return array|WP_Error Search results or error
	 */
	public function semantic_search( $query, $limit = 10, $filters = [] ) {
		if ( $this->is_local_mode() ) {
			return $this->semantic_search_local( $query, $limit, $filters );
		} else {
			return $this->semantic_search_cloud( $query, $limit, $filters );
		}
	}

	/**
	 * Perform local semantic search
	 *
	 * @param string $query   Search query
	 * @param int    $limit   Maximum results
	 * @param array  $filters Filters
	 * @return array|WP_Error
	 */
	private function semantic_search_local( $query, $limit = 10, $filters = [] ) {
		// Generate embedding for query
		$query_embedding = $this->generate_embedding( $query );
		if ( is_wp_error( $query_embedding ) ) {
			return $query_embedding;
		}

		$local = $this->get_local_storage();
		$results = $local->semantic_search( $query_embedding, $limit, $filters );

		if ( is_wp_error( $results ) ) {
			return $results;
		}

		// Format results to match cloud response format
		return $this->format_search_results( $results );
	}

	/**
	 * Perform cloud semantic search
	 *
	 * @param string $query   Search query
	 * @param int    $limit   Maximum results
	 * @param array  $filters Filters
	 * @return array|WP_Error
	 */
	private function semantic_search_cloud( $query, $limit = 10, $filters = [] ) {
		$ai_client = $this->get_ai_client();
		return $ai_client->semantic_search( $query, $limit, $filters );
	}

	/**
	 * Format local search results to match cloud response format
	 *
	 * @param array $results Raw local results
	 * @return array Formatted results
	 */
	private function format_search_results( $results ) {
		$formatted = [
			'results' => [],
			'total'   => count( $results ),
		];

		foreach ( $results as $result ) {
			// Get full topic and post data
			$topic = WPF()->topic->get_topic( $result['topicid'] );
			$post = WPF()->post->get_post( $result['postid'] );

			if ( ! $topic || ! $post ) {
				continue;
			}

			$formatted['results'][] = [
				'topic_id'    => (int) $result['topicid'],
				'post_id'     => (int) $result['postid'],
				'forum_id'    => (int) $result['forumid'],
				'title'       => $topic['title'] ?? '',
				'content'     => $result['content_preview'] ?? strip_tags( $post['body'] ),
				'score'       => (float) $result['similarity'],
				'url'         => WPF()->topic->get_url( $result['topicid'] ),
				'post_url'    => WPF()->post->get_url( $result['postid'] ),
				'created'     => $post['created'] ?? null,
				'user_id'     => (int) ( $result['userid'] ?? 0 ),
			];
		}

		return $formatted;
	}

	// =========================================================================
	// SIMILAR CONTENT
	// =========================================================================

	/**
	 * Find similar topics/posts
	 *
	 * @param string $type      'topic' or 'post'
	 * @param int    $id        Topic or post ID
	 * @param int    $limit     Maximum results
	 * @param bool   $use_cache Whether to use cache
	 * @return array|WP_Error
	 */
	public function find_similar( $type, $id, $limit = 5, $use_cache = true ) {
		if ( $this->is_local_mode() ) {
			$local = $this->get_local_storage();
			return $local->find_similar( $type, $id, $limit, ! $use_cache );
		} else {
			$ai_client = $this->get_ai_client();
			return $ai_client->find_similar_topics( $id, $limit );
		}
	}

	// =========================================================================
	// UTILITY METHODS
	// =========================================================================

	/**
	 * Check if content is indexed
	 *
	 * @param int    $postid Post ID
	 * @param string $content_hash Optional content hash to check freshness
	 * @return bool
	 */
	public function is_indexed( $postid, $content_hash = null ) {
		if ( $this->is_local_mode() ) {
			$local = $this->get_local_storage();
			$existing = $local->get_embedding( $postid );

			if ( ! $existing ) {
				return false;
			}

			if ( $content_hash !== null ) {
				return $existing['content_hash'] === $content_hash;
			}

			return true;
		} else {
			// For cloud, we'd need to check via API
			// For now, assume indexed if we've indexed before
			return false; // Let cloud handle deduplication
		}
	}

	/**
	 * Get the current storage mode label
	 *
	 * @return string Human-readable label
	 */
	public function get_storage_mode_label() {
		if ( $this->is_local_mode() ) {
			return wpforo_phrase( 'Local (WordPress)', false );
		} else {
			return wpforo_phrase( 'Cloud (gVectors)', false );
		}
	}

	/**
	 * Sync local indexed status in wpforo_topics table
	 *
	 * Queries wpforo_ai_embeddings table for all unique topic IDs and
	 * updates the `local` column in wpforo_topics accordingly.
	 * Called when switching to local storage mode.
	 *
	 * @return array {
	 *     @type int $updated Number of topics marked as indexed
	 *     @type int $cleared Number of topics marked as not indexed
	 * }
	 */
	public function sync_local_indexed_status() {
		global $wpdb;

		// Use the embeddings table directly from WPF tables
		$embeddings_table = WPF()->tables->ai_embeddings;

		// Get all unique topic IDs from embeddings table
		$indexed_topic_ids = $wpdb->get_col(
			"SELECT DISTINCT topicid FROM `{$embeddings_table}` WHERE topicid > 0"
		);

		$updated = 0;
		$cleared = 0;

		if ( ! empty( $indexed_topic_ids ) ) {
			// Set local=1 for topics that are indexed
			$placeholders = implode( ',', array_fill( 0, count( $indexed_topic_ids ), '%d' ) );
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `" . WPF()->tables->topics . "` SET `local` = 1 WHERE topicid IN ($placeholders)",
					$indexed_topic_ids
				)
			);

			// Set local=0 for topics that are NOT indexed
			$cleared = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `" . WPF()->tables->topics . "` SET `local` = 0 WHERE topicid NOT IN ($placeholders)",
					$indexed_topic_ids
				)
			);
		} else {
			// No indexed topics - set all to 0
			$cleared = $wpdb->query(
				"UPDATE `" . WPF()->tables->topics . "` SET `local` = 0 WHERE `local` = 1"
			);
		}

		return [
			'updated' => (int) $updated,
			'cleared' => (int) $cleared,
			'indexed_count' => count( $indexed_topic_ids )
		];
	}

	/**
	 * Sync cloud indexed status in wpforo_topics table
	 *
	 * Calls the /v1/rag/indexed-topics API endpoint to get all indexed
	 * topic IDs and updates the `cloud` column in wpforo_topics accordingly.
	 * Called when switching to cloud storage mode.
	 *
	 * @return array|WP_Error {
	 *     @type int $updated Number of topics marked as indexed
	 *     @type int $cleared Number of topics marked as not indexed
	 * }
	 */
	public function sync_cloud_indexed_status() {
		global $wpdb;

		// Get indexed topic IDs from cloud API
		$indexed_topic_ids = $this->get_cloud_indexed_topic_ids();

		if ( is_wp_error( $indexed_topic_ids ) ) {
			return $indexed_topic_ids;
		}

		$updated = 0;
		$cleared = 0;

		if ( ! empty( $indexed_topic_ids ) ) {
			// Set cloud=1 for topics that are indexed
			$placeholders = implode( ',', array_fill( 0, count( $indexed_topic_ids ), '%d' ) );
			$updated = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `" . WPF()->tables->topics . "` SET `cloud` = 1 WHERE topicid IN ($placeholders)",
					$indexed_topic_ids
				)
			);

			// Set cloud=0 for topics that are NOT indexed
			$cleared = $wpdb->query(
				$wpdb->prepare(
					"UPDATE `" . WPF()->tables->topics . "` SET `cloud` = 0 WHERE topicid NOT IN ($placeholders)",
					$indexed_topic_ids
				)
			);
		} else {
			// No indexed topics - set all to 0
			$cleared = $wpdb->query(
				"UPDATE `" . WPF()->tables->topics . "` SET `cloud` = 0 WHERE `cloud` = 1"
			);
		}

		// Clear topic cache to ensure wpforo_topic() returns fresh cloud values
		// This is critical for force_reindex to work correctly
		if ( $updated > 0 || $cleared > 0 ) {
			wpforo_clean_cache( 'topic' );
		}

		return [
			'updated' => (int) $updated,
			'cleared' => (int) $cleared,
			'indexed_count' => count( $indexed_topic_ids )
		];
	}

	/**
	 * Get all indexed topic IDs from cloud storage
	 *
	 * Calls the /v1/rag/indexed-topics API endpoint.
	 *
	 * @return array|WP_Error Array of topic IDs or error
	 */
	public function get_cloud_indexed_topic_ids() {
		$ai_client = $this->get_ai_client();
		if ( ! $ai_client ) {
			return new \WP_Error( 'no_ai_client', wpforo_phrase( 'AI client not available', false ) );
		}

		$response = $ai_client->api_get( '/rag/indexed-topics' );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( ! isset( $response['topic_ids'] ) || ! is_array( $response['topic_ids'] ) ) {
			return new \WP_Error( 'invalid_response', wpforo_phrase( 'Invalid response from API', false ) );
		}

		return $response['topic_ids'];
	}

	/**
	 * Mark a topic as indexed in the current storage mode
	 *
	 * Updates the `local` or `cloud` column based on current mode.
	 *
	 * @param int $topicid Topic ID
	 * @return bool Success
	 */
	public function mark_topic_indexed( $topicid ) {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';

		return $wpdb->update(
			WPF()->tables->topics,
			[ $column => 1 ],
			[ 'topicid' => $topicid ],
			[ '%d' ],
			[ '%d' ]
		) !== false;
	}

	/**
	 * Mark a topic as not indexed in the current storage mode
	 *
	 * Updates the `local` or `cloud` column based on current mode.
	 *
	 * @param int $topicid Topic ID
	 * @return bool Success
	 */
	public function mark_topic_not_indexed( $topicid ) {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';

		return $wpdb->update(
			WPF()->tables->topics,
			[ $column => 0 ],
			[ 'topicid' => $topicid ],
			[ '%d' ],
			[ '%d' ]
		) !== false;
	}

	/**
	 * Get topics that are not indexed in the current storage mode
	 *
	 * Uses the `local` or `cloud` column based on current mode.
	 *
	 * @param int $limit Maximum number of topics to return (0 = no limit)
	 * @param int $offset Offset for pagination
	 * @return array Array of topic IDs
	 */
	public function get_unindexed_topic_ids( $limit = 0, $offset = 0 ) {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';

		// Exclude private topics (private = 1) and unapproved topics (status != 0)
		$sql = "SELECT topicid FROM `" . WPF()->tables->topics . "` WHERE `{$column}` = 0 AND `status` = 0 AND `private` = 0 ORDER BY topicid ASC";

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( " LIMIT %d OFFSET %d", $limit, $offset );
		}

		return array_map( 'intval', $wpdb->get_col( $sql ) );
	}

	/**
	 * Count topics that are not indexed in the current storage mode
	 *
	 * @return int Count of unindexed topics
	 */
	public function count_unindexed_topics() {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';

		// Exclude private topics (private = 1) and unapproved topics (status != 0)
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM `" . WPF()->tables->topics . "` WHERE `{$column}` = 0 AND `status` = 0 AND `private` = 0"
		);
	}

	// =========================================================================
	// INDEXING STATUS ANALYSIS
	// =========================================================================

	/**
	 * Get a detailed breakdown of topics by indexing status
	 *
	 * Analyzes all topics and categorizes them by why they are or aren't indexed.
	 * This helps users understand why some topics might not appear in AI search.
	 *
	 * Categories:
	 * - indexed: Successfully indexed in current storage mode
	 * - pending: Eligible for indexing but not yet indexed
	 * - private: Private topics (excluded from indexing)
	 * - unapproved: Unapproved topics (excluded from indexing)
	 *
	 * @return array Breakdown with counts for each category
	 */
	public function get_indexing_status_breakdown() {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';
		$topics_table = WPF()->tables->topics;

		// Get counts for each category in a single query
		$results = $wpdb->get_row(
			"SELECT
				COUNT(*) as total,
				SUM(CASE WHEN `{$column}` = 1 AND `status` = 0 AND `private` = 0 THEN 1 ELSE 0 END) as indexed,
				SUM(CASE WHEN `{$column}` = 0 AND `status` = 0 AND `private` = 0 THEN 1 ELSE 0 END) as pending,
				SUM(CASE WHEN `private` = 1 THEN 1 ELSE 0 END) as private_topics,
				SUM(CASE WHEN `status` != 0 AND `private` = 0 THEN 1 ELSE 0 END) as unapproved
			FROM `{$topics_table}`",
			ARRAY_A
		);

		if ( ! $results ) {
			return [
				'total'           => 0,
				'indexed'         => 0,
				'pending'         => 0,
				'private'         => 0,
				'unapproved'      => 0,
				'storage_mode'    => $this->get_storage_mode(),
			];
		}

		return [
			'total'           => (int) $results['total'],
			'indexed'         => (int) $results['indexed'],
			'pending'         => (int) $results['pending'],
			'private'         => (int) $results['private_topics'],
			'unapproved'      => (int) $results['unapproved'],
			'storage_mode'    => $this->get_storage_mode(),
		];
	}

	/**
	 * Get sample topics that are pending indexing
	 *
	 * Returns a limited sample of topics that should be indexed but aren't.
	 * Useful for debugging why topics aren't being picked up.
	 *
	 * @param int $limit Maximum number of topics to return
	 * @return array Array of topic data with basic info
	 */
	public function get_pending_topics_sample( $limit = 10 ) {
		global $wpdb;

		$column = $this->is_local_mode() ? 'local' : 'cloud';
		$topics_table = WPF()->tables->topics;

		$topics = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT topicid, title, forumid, posts, created, modified
				FROM `{$topics_table}`
				WHERE `{$column}` = 0
				AND `status` = 0
				AND `private` = 0
				ORDER BY topicid DESC
				LIMIT %d",
				$limit
			),
			ARRAY_A
		);

		return $topics ?: [];
	}

	// =========================================================================
	// DEBUG LOGGING
	// =========================================================================

	/**
	 * Check if debug mode is enabled
	 *
	 * @return bool True if debug mode is enabled
	 */
	private function is_debug_mode() {
		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WPFORO_AI_DEBUG' ) && WPFORO_AI_DEBUG;
	}

	/**
	 * Log informational message (only in debug mode)
	 *
	 * @param string $context  Log context identifier
	 * @param array  $data     Optional data to log
	 */
	private function log_info( $context, $data = [] ) {
		if ( ! $this->is_debug_mode() ) {
			return;
		}

		\wpforo_ai_log( 'info', sprintf(
			'%s | Data: %s',
			$context,
			wp_json_encode( $data )
		), 'VectorStorage' );
	}
}
