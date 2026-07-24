<?php
/**
 * Export/import stores as a single portable JSON file (including photos).
 *
 * @package ProductStoreLocator
 */

namespace ProductStoreLocator;

defined( 'ABSPATH' ) || exit;

/**
 * Class ImportExport
 *
 * Adds a "Import / Export" admin screen so stores (with their featured image
 * and logo) can be moved between sites — e.g. staging to production — as one
 * self-contained JSON file, with no dependency on the source site staying online.
 */
final class ImportExport {

	/**
	 * Settings page slug.
	 */
	private const PAGE = 'store-locator-import-export';

	/**
	 * Export file format version. Bump only if the JSON structure changes
	 * in a way older code can't read.
	 */
	private const EXPORT_VERSION = 1;

	/**
	 * Meta keys that are never copied verbatim between sites because their
	 * values (attachment IDs) are meaningless outside the site that made them.
	 */
	private const NON_PORTABLE_META = array( 'store_logo_id' );

	/**
	 * Hook suffix of this page, used to scope asset enqueuing.
	 *
	 * @var string
	 */
	private string $hook_suffix = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_psl_export', array( $this, 'handle_export' ) );
		add_action( 'admin_post_psl_import', array( $this, 'handle_import' ) );
	}

	/**
	 * Add the Import / Export subpage under the Store Locator menu.
	 *
	 * @return void
	 */
	public function add_menu(): void {
		$this->hook_suffix = (string) add_submenu_page(
			CPT::MENU_SLUG,
			__( 'Import / Export', 'product-store-locator' ),
			__( 'Import / Export', 'product-store-locator' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the shared admin stylesheet on this screen only.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( $hook !== $this->hook_suffix ) {
			return;
		}
		wp_enqueue_style(
			'psl-admin',
			PSL_PLUGIN_URL . 'assets/css/psl-admin.css',
			array(),
			Plugin::asset_version( 'assets/css/psl-admin.css' )
		);
	}

	/**
	 * Render the Import / Export screen.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'product-store-locator' ) );
		}

		$counts = wp_count_posts( CPT::POST_TYPE );
		$total  = 0;
		foreach ( array( 'publish', 'draft', 'pending', 'private' ) as $status ) {
			$total += (int) ( $counts->$status ?? 0 );
		}

		$result = isset( $_GET['psl_import_result'] ) ? sanitize_text_field( wp_unslash( $_GET['psl_import_result'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<?php if ( $result ) : ?>
				<?php $this->render_result_notice( $result ); ?>
			<?php endif; ?>

			<div class="psl-io card" style="max-width:820px;padding:16px 20px;margin:16px 0;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Export stores', 'product-store-locator' ); ?></h2>
				<p>
					<?php
					printf(
						/* translators: %d: number of stores. */
						esc_html(
							_n(
								'Download all %d store as a single JSON file — including featured photos and logos, fully self-contained.',
								'Download all %d stores as a single JSON file — including featured photos and logos, fully self-contained.',
								$total,
								'product-store-locator'
							)
						),
						(int) $total
					);
					?>
				</p>
				<p>
					<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psl_export' ), 'psl_export' ) ); ?>">
						<?php esc_html_e( 'Download export (.json)', 'product-store-locator' ); ?>
					</a>
				</p>
				<p class="description"><?php esc_html_e( 'Handy for moving stores from a staging site to production, or keeping a backup before big changes.', 'product-store-locator' ); ?></p>
			</div>

			<div class="psl-io card" style="max-width:820px;padding:16px 20px;margin:16px 0;">
				<h2 style="margin-top:0;"><?php esc_html_e( 'Import stores', 'product-store-locator' ); ?></h2>
				<p><?php esc_html_e( 'Upload a JSON file exported from this plugin (this site or another). Stores are matched by Google Place ID, or by exact name if there is no Place ID — a match updates that store in place; everything else is added as new. Nothing is ever deleted.', 'product-store-locator' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'psl_import' ); ?>
					<input type="hidden" name="action" value="psl_import" />
					<p>
						<input type="file" name="psl_import_file" accept="application/json,.json" required />
					</p>
					<p>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Import stores', 'product-store-locator' ); ?></button>
					</p>
				</form>
				<p class="description"><?php esc_html_e( 'Re-importing photos/logos adds fresh copies to the Media Library rather than reusing the old ones.', 'product-store-locator' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the success/error notice after an import redirect.
	 *
	 * @param string $result 'ok' or 'error'.
	 * @return void
	 */
	private function render_result_notice( string $result ): void {
		if ( 'error' === $result ) {
			$message = isset( $_GET['psl_import_message'] )
				? sanitize_text_field( wp_unslash( $_GET['psl_import_message'] ) )
				: __( 'Import failed.', 'product-store-locator' );
			printf( '<div class="notice notice-error is-dismissible"><p>%s</p></div>', esc_html( $message ) );
			return;
		}

		$created = isset( $_GET['created'] ) ? (int) $_GET['created'] : 0;
		$updated = isset( $_GET['updated'] ) ? (int) $_GET['updated'] : 0;
		$skipped = isset( $_GET['skipped'] ) ? (int) $_GET['skipped'] : 0;

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html(
				sprintf(
					/* translators: 1: number added, 2: number updated, 3: number skipped. */
					__( 'Import complete: %1$d added, %2$d updated, %3$d skipped.', 'product-store-locator' ),
					$created,
					$updated,
					$skipped
				)
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	/**
	 * Stream a JSON export of every store to the browser.
	 *
	 * @return void
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'product-store-locator' ) );
		}
		check_admin_referer( 'psl_export' );

		$posts = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$stores = array();
		foreach ( $posts as $post ) {
			$stores[] = $this->export_store( $post );
		}

		$payload = array(
			'plugin'         => 'product-store-locator',
			'export_version' => self::EXPORT_VERSION,
			'plugin_version' => PSL_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'site_url'       => home_url(),
			'stores'         => $stores,
		);

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="store-locator-export-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $payload );
		exit;
	}

	/**
	 * Build the exportable array for one store post.
	 *
	 * @param \WP_Post $post Store post.
	 * @return array<string, mixed>
	 */
	private function export_store( \WP_Post $post ): array {
		$meta = array();
		foreach ( array_keys( CPT::meta_fields() ) as $key ) {
			if ( in_array( $key, self::NON_PORTABLE_META, true ) ) {
				continue;
			}
			$meta[ $key ] = get_post_meta( $post->ID, $key, true );
		}

		$store = array(
			'title'  => $post->post_title,
			'status' => $post->post_status,
			'meta'   => $meta,
		);

		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		if ( $thumb_id ) {
			$image = $this->export_attachment( $thumb_id );
			if ( $image ) {
				$store['featured_image'] = $image;
			}
		}

		$logo_id = (int) get_post_meta( $post->ID, 'store_logo_id', true );
		if ( $logo_id ) {
			$image = $this->export_attachment( $logo_id );
			if ( $image ) {
				$store['logo'] = $image;
			}
		}

		return $store;
	}

	/**
	 * Read an attachment's file off disk and base64-encode it for export.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{filename:string,mime:string,data:string}|null
	 */
	private function export_attachment( int $attachment_id ): ?array {
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return null;
		}

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $contents ) {
			return null;
		}

		return array(
			'filename' => wp_basename( $file ),
			'mime'     => (string) ( get_post_mime_type( $attachment_id ) ?: 'image/jpeg' ),
			'data'     => base64_encode( $contents ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		);
	}

	/* ---------------------------------------------------------------------
	 * Import
	 * ------------------------------------------------------------------ */

	/**
	 * Handle an uploaded export file: create/update stores from it.
	 *
	 * @return void
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'product-store-locator' ) );
		}
		check_admin_referer( 'psl_import' );

		if ( empty( $_FILES['psl_import_file']['tmp_name'] ) || UPLOAD_ERR_OK !== (int) ( $_FILES['psl_import_file']['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			$this->redirect_error( __( 'No file was uploaded, or the upload failed.', 'product-store-locator' ) );
		}

		$tmp_name = $_FILES['psl_import_file']['tmp_name'];

		if ( filesize( $tmp_name ) > 25 * MB_IN_BYTES ) {
			$this->redirect_error( __( 'The import file is too large (25MB max).', 'product-store-locator' ) );
		}

		$json = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $json, true );

		if ( ! is_array( $data ) || 'product-store-locator' !== ( $data['plugin'] ?? '' ) || empty( $data['stores'] ) || ! is_array( $data['stores'] ) ) {
			$this->redirect_error( __( 'This does not look like a valid Product Store Locator export file.', 'product-store-locator' ) );
		}

		if ( (int) ( $data['export_version'] ?? 0 ) > self::EXPORT_VERSION ) {
			$this->redirect_error( __( 'This export file was created by a newer version of the plugin. Please update the plugin first.', 'product-store-locator' ) );
		}

		// Best-effort: importing photos for many stores can take a while.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;

		foreach ( $data['stores'] as $store ) {
			$outcome = $this->import_store( $store );
			if ( 'created' === $outcome ) {
				++$created;
			} elseif ( 'updated' === $outcome ) {
				++$updated;
			} else {
				++$skipped;
			}
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'              => self::PAGE,
					'psl_import_result' => 'ok',
					'created'           => $created,
					'updated'           => $updated,
					'skipped'           => $skipped,
				),
				admin_url( 'edit.php?post_type=' . CPT::POST_TYPE )
			)
		);
		exit;
	}

	/**
	 * Redirect back to the import page with an error message.
	 *
	 * @param string $message Human-readable error.
	 * @return void
	 */
	private function redirect_error( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'               => self::PAGE,
					'psl_import_result'  => 'error',
					'psl_import_message' => rawurlencode( $message ),
				),
				admin_url( 'edit.php?post_type=' . CPT::POST_TYPE )
			)
		);
		exit;
	}

	/**
	 * Create or update one store from its exported array.
	 *
	 * @param mixed $store Decoded store entry.
	 * @return string 'created', 'updated', or 'skipped'.
	 */
	private function import_store( $store ): string {
		if ( ! is_array( $store ) || empty( $store['title'] ) ) {
			return 'skipped';
		}

		$title = sanitize_text_field( (string) $store['title'] );
		$meta  = is_array( $store['meta'] ?? null ) ? $store['meta'] : array();

		$existing_id = $this->find_existing_store( $title, $meta );

		$allowed_status = array( 'publish', 'draft', 'pending', 'private' );
		$status         = in_array( $store['status'] ?? '', $allowed_status, true ) ? $store['status'] : 'publish';

		$postarr = array(
			'post_type'   => CPT::POST_TYPE,
			'post_title'  => $title,
			'post_status' => $status,
		);

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
			$outcome       = 'updated';
		} else {
			$post_id = wp_insert_post( $postarr, true );
			$outcome = 'created';
		}

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 'skipped';
		}

		$post_id = (int) $post_id;

		$this->apply_meta( $post_id, $meta );

		if ( ! empty( $store['featured_image'] ) && is_array( $store['featured_image'] ) ) {
			$this->import_attachment_image( $post_id, $store['featured_image'], true );
		}
		if ( ! empty( $store['logo'] ) && is_array( $store['logo'] ) ) {
			$this->import_attachment_image( $post_id, $store['logo'], false );
		}

		return $outcome;
	}

	/**
	 * Find a store already on this site matching the imported one.
	 *
	 * Prefers an exact Google Place ID match (stable, unambiguous); falls
	 * back to an exact title match when there is no Place ID to compare.
	 *
	 * @param string               $title Store title.
	 * @param array<string, mixed> $meta  Imported meta values.
	 * @return int Existing post ID, or 0 if none found.
	 */
	private function find_existing_store( string $title, array $meta ): int {
		$place_id = isset( $meta['store_place_id'] ) ? sanitize_text_field( (string) $meta['store_place_id'] ) : '';

		if ( '' !== $place_id ) {
			$found = get_posts(
				array(
					'post_type'      => CPT::POST_TYPE,
					'post_status'    => 'any',
					'posts_per_page' => 1,
					'meta_key'       => 'store_place_id',
					'meta_value'     => $place_id,
					'fields'         => 'ids',
				)
			);
			if ( $found ) {
				return (int) $found[0];
			}
		}

		$found = get_posts(
			array(
				'post_type'      => CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'title'          => $title,
				'fields'         => 'ids',
			)
		);

		return $found ? (int) $found[0] : 0;
	}

	/**
	 * Sanitize and save the imported meta fields (excluding non-portable ones).
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $meta    Imported meta values.
	 * @return void
	 */
	private function apply_meta( int $post_id, array $meta ): void {
		foreach ( CPT::meta_fields() as $key => $config ) {
			if ( in_array( $key, self::NON_PORTABLE_META, true ) || ! array_key_exists( $key, $meta ) ) {
				continue;
			}
			$value = call_user_func( $config['sanitize'], $meta[ $key ] );
			update_post_meta( $post_id, $key, $value );
		}
	}

	/**
	 * Decode a base64 image from the export and sideload it as the store's
	 * featured image or logo.
	 *
	 * @param int                 $post_id     Post ID.
	 * @param array<string, mixed> $image      {filename, mime, data} from the export.
	 * @param bool                $as_featured True for the featured image, false for the logo meta.
	 * @return void
	 */
	private function import_attachment_image( int $post_id, array $image, bool $as_featured ): void {
		if ( empty( $image['data'] ) || empty( $image['filename'] ) ) {
			return;
		}

		$binary = base64_decode( (string) $image['data'], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $binary || '' === $binary ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		$tmp = wp_tempnam( (string) $image['filename'] );
		if ( ! $tmp ) {
			return;
		}
		file_put_contents( $tmp, $binary ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

		$filename  = sanitize_file_name( (string) $image['filename'] );
		$attach_id = Plugin::sideload_attachment( $tmp, $filename, $post_id, get_the_title( $post_id ) );

		if ( is_wp_error( $attach_id ) ) {
			return;
		}

		if ( $as_featured ) {
			set_post_thumbnail( $post_id, $attach_id );
		} else {
			update_post_meta( $post_id, 'store_logo_id', $attach_id );
		}
	}
}
