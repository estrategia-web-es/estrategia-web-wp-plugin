<?php
/**
 * «Forms → Forms» (1.7.0): the forms that exist on the site, the first item
 * of the Forms menu. Read only: a form is edited where it lives (the block,
 * in the editor), and this page says WHERE that is and what it does.
 *
 * What it lists: every post of ANY type (pages, posts, synced patterns
 * — wp_block —, template parts saved in the database…) in publish, draft,
 * pending, future or private, never revisions or trash, whose content has a
 * form block (estrategia-web-forms/form, or its pre-1.0.0 name
 * armazon-forms/form). One row per form block. Per form:
 * - its name in the submission log (the block's «title») and its identifier
 *   (formId) — the two things the Submissions filter shows;
 * - where it is: the post's title, type and status, with an «Edit» link
 *   (only if the user can edit it); for a synced pattern, also the pages
 *   that use it (posts whose content has <!-- wp:block {"ref":ID…);
 * - its notifications: how many, and each one's label and recipients as
 *   written in the block («{{email}}» markers shown as they are).
 * The fields and notifications are read with Definitions::build(), the SAME
 * function that makes the snapshot a submission is validated against, so
 * this page cannot tell a different story from the one the emails follow.
 *
 * WITH WPML: one row per form in the DEFAULT language, with the languages it
 * is also in (its translations that also have the form). A form that only
 * exists in a translation (the default-language page has none) is listed
 * once, under that translation. collapse() is that rule, pure.
 *
 * How to maintain:
 * - Who: whoever adds a place forms can live in, or a column. A new block
 *   attribute that matters to the email is read by Definitions, not here.
 * - Edge case, by name: a pattern INSIDE another pattern. Only the direct
 *   users are listed (the page that uses the outer pattern is not); one level
 *   covers every site today, and following the chain costs a query per level.
 * - Edge case: a form in a theme FILE (a pattern or template in PHP/HTML, not
 *   in the database) is not listed: there is no post to find. Its
 *   submissions still show in Submissions, with its identifier.
 * - The trap: the content search is a LIKE on post_content, which only
 *   narrows; what counts is parse_blocks() (a post that merely MENTIONS the
 *   block name in a paragraph has no form block and is not listed). And the
 *   «ref» match is checked with a regex for the same reason: ref 12 must not
 *   match ref 123.
 * - Tests: modules/forms/tests/forms-list.test.mjs (level 2: a form inside a
 *   synced pattern, the pages that use it, its notifications).
 */

namespace EstrategiaWeb\Forms;

defined( 'ABSPATH' ) || exit;

final class Forms_List {

	public const PAGE = 'estrategia-web-forms-list';

	/** The statuses a form can be in and still matter. */
	public const STATUSES = array( 'publish', 'draft', 'pending', 'future', 'private' );

	/** The block names: the current one and the pre-1.0.0 one (Legacy_Names). */
	private const BLOCKS = array( 'estrategia-web-forms/form', 'armazon-forms/form' );

	/**
	 * Every form block in the database, one row each.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function find(): array {
		global $wpdb;
		$likes = array();
		$args  = array();
		foreach ( self::BLOCKS as $name ) {
			$likes[] = 'post_content LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( '<!-- wp:' . $name ) . '%';
		}
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$sql      = "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status IN ($statuses) AND (" . implode( ' OR ', $likes ) . ') ORDER BY post_type, post_title, ID';
		$posts    = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( self::STATUSES, $args ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery

		$rows = array();
		foreach ( (array) $posts as $post ) {
			$content = str_replace( '<!-- wp:' . Legacy_Names::OLD . '/', '<!-- wp:' . Legacy_Names::NEW . '/', (string) $post->post_content );
			foreach ( self::form_blocks( parse_blocks( $content ) ) as $i => $block ) {
				$type       = \WP_Block_Type_Registry::get_instance()->get_registered( 'estrategia-web-forms/form' );
				$attributes = $type ? $type->prepare_attributes_for_render( (array) ( $block['attrs'] ?? array() ) ) : (array) ( $block['attrs'] ?? array() );
				$definition = Definitions::build( $attributes, (array) ( $block['innerBlocks'] ?? array() ) );
				$rows[]     = array(
					'post_id'       => (int) $post->ID,
					'post_title'    => (string) $post->post_title,
					'post_type'     => (string) $post->post_type,
					'post_status'   => (string) $post->post_status,
					'index'         => $i,
					'form_id'       => $definition['form_id'],
					'title'         => $definition['title'],
					'notifications' => $definition['notifications'],
				);
			}
		}
		return $rows;
	}

	/**
	 * The form blocks of a parsed tree, at any depth (in groups, columns…).
	 *
	 * @param array<int, array> $blocks parse_blocks().
	 * @return array<int, array>
	 */
	public static function form_blocks( array $blocks ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( 'estrategia-web-forms/form' === ( $block['blockName'] ?? '' ) ) {
				$found[] = $block;
				continue; // A form inside a form is not a thing.
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::form_blocks( $block['innerBlocks'] ) );
			}
		}
		return $found;
	}

	/**
	 * The posts that use a synced pattern.
	 *
	 * @return array<int, object{ID: int, post_title: string, post_type: string, post_status: string}>
	 */
	public static function pattern_users( int $pattern_id ): array {
		global $wpdb;
		$statuses = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		$like     = '%' . $wpdb->esc_like( '<!-- wp:block {"ref":' . $pattern_id ) . '%';
		$posts    = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_title, post_type, post_status, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND post_status IN ($statuses) AND post_content LIKE %s ORDER BY post_type, post_title, ID", array_merge( self::STATUSES, array( $like ) ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
		return array_values( array_filter( (array) $posts, static fn( $p ) => (bool) preg_match( '/<!--\s+wp:block\s+\{"ref":' . $pattern_id . '[,}]/', (string) $p->post_content ) ) );
	}

	/**
	 * WPML: one row per form in the default language, with the languages it
	 * is also in. Pure function.
	 *
	 * Each row brings 'lang' (its post's language) and 'group' (the id of
	 * its post's default-language translation, or its own id if it has none).
	 * Rows are the same form when they share group and form_id (or, without
	 * an identifier, their position in the post). The default-language row
	 * stays and gets 'translations' (the other languages, sorted); if there
	 * is none, the first one stays.
	 *
	 * @param array<int, array<string, mixed>> $rows    With lang and group.
	 * @param string                           $default Default language code.
	 * @return array<int, array<string, mixed>>
	 */
	public static function collapse( array $rows, string $default ): array {
		$groups = array();
		foreach ( $rows as $row ) {
			$key              = $row['group'] . '|' . ( '' !== (string) $row['form_id'] ? $row['form_id'] : '#' . $row['index'] );
			$groups[ $key ][] = $row;
		}
		$out = array();
		foreach ( $groups as $members ) {
			$main = $members[0];
			foreach ( $members as $member ) {
				if ( $member['lang'] === $default ) {
					$main = $member;
					break;
				}
			}
			$langs = array();
			foreach ( $members as $member ) {
				if ( $member !== $main && '' !== (string) $member['lang'] && $member['lang'] !== $main['lang'] ) {
					$langs[] = (string) $member['lang'];
				}
			}
			$langs = array_values( array_unique( $langs ) );
			sort( $langs );
			$main['translations'] = $langs;
			$out[]                = $main;
		}
		return $out;
	}

	/** The rows to show: find(), collapsed by language if WPML is on. */
	public static function rows(): array {
		$rows    = self::find();
		$default = Language::wpml_active() ? apply_filters( 'wpml_default_language', null ) : null;
		if ( ! is_string( $default ) || '' === $default ) {
			return array_map( static fn( $r ) => $r + array( 'translations' => array() ), $rows );
		}
		foreach ( $rows as $i => $row ) {
			$details              = apply_filters( 'wpml_post_language_details', null, $row['post_id'] );
			$original             = apply_filters( 'wpml_object_id', $row['post_id'], $row['post_type'], false, $default );
			$rows[ $i ]['lang']   = is_array( $details ) ? (string) ( $details['language_code'] ?? '' ) : '';
			$rows[ $i ]['group']  = $original ? (int) $original : $row['post_id'];
		}
		return self::collapse( $rows, $default );
	}

	/** A post as «Title (Type, Draft)» with an Edit link when the user may edit it. */
	private static function place( int $id, string $title, string $type, string $status ): string {
		$type_object   = get_post_type_object( $type );
		$status_object = get_post_status_object( $status );
		$label         = $type_object ? $type_object->labels->singular_name : $type;
		if ( 'publish' !== $status && $status_object ) {
			$label .= ', ' . $status_object->label;
		}
		/* translators: %d: post ID. */
		$html = '<strong>' . esc_html( '' !== $title ? $title : sprintf( __( '(no title, #%d)', 'estrategia-web' ), $id ) ) . '</strong> <span class="description">(' . esc_html( $label ) . ')</span>';
		$edit = get_edit_post_link( $id, 'raw' );
		if ( $edit ) {
			$html .= ' — <a href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit', 'estrategia-web' ) . '</a>';
		}
		return $html;
	}

	public static function render(): void {
		if ( ! current_user_can( Admin::capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to see this page.', 'estrategia-web' ), 403 );
		}
		$rows = self::rows();
		$wpml = Language::wpml_active();
		?>
		<div class="wrap estrategia-web-forms-list">
			<h1><?php esc_html_e( 'Forms', 'estrategia-web' ); ?></h1>
			<p><?php esc_html_e( 'The forms on this site and where they are. A form is edited in its block: open the page or pattern it is in. Its name and identifier are the ones the submissions log shows.', 'estrategia-web' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Form', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Where it is', 'estrategia-web' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Notifications', 'estrategia-web' ); ?></th>
						<?php if ( $wpml ) : ?>
							<th scope="col"><?php esc_html_e( 'Also in', 'estrategia-web' ); ?></th>
						<?php endif; ?>
					</tr>
				</thead>
				<tbody>
				<?php if ( ! $rows ) : ?>
					<tr><td colspan="<?php echo $wpml ? 4 : 3; ?>"><?php esc_html_e( 'There are no forms yet. Add a «Form» block to a page or a synced pattern.', 'estrategia-web' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr data-form-id="<?php echo esc_attr( $row['form_id'] ); ?>" data-post-id="<?php echo (int) $row['post_id']; ?>">
						<td>
							<strong class="estrategia-web-forms-list__title"><?php echo esc_html( '' !== $row['title'] ? $row['title'] : __( '(no name)', 'estrategia-web' ) ); ?></strong><br>
							<code class="estrategia-web-forms-list__id"><?php echo esc_html( '' !== $row['form_id'] ? $row['form_id'] : '—' ); ?></code>
						</td>
						<td class="estrategia-web-forms-list__where">
							<?php echo self::place( $row['post_id'], $row['post_title'], $row['post_type'], $row['post_status'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in place(). ?>
							<?php if ( 'wp_block' === $row['post_type'] ) : ?>
								<?php $users = self::pattern_users( $row['post_id'] ); ?>
								<div class="estrategia-web-forms-list__users">
									<?php if ( $users ) : ?>
										<?php esc_html_e( 'Used in:', 'estrategia-web' ); ?>
										<ul style="margin:.3em 0 0 1.2em;list-style:disc">
											<?php foreach ( $users as $user ) : ?>
												<li data-post-id="<?php echo (int) $user->ID; ?>"><?php echo self::place( (int) $user->ID, (string) $user->post_title, (string) $user->post_type, (string) $user->post_status ); // phpcs:ignore WordPress.Security.EscapeOutput ?></li>
											<?php endforeach; ?>
										</ul>
									<?php else : ?>
										<span class="description"><?php esc_html_e( 'No page uses this pattern.', 'estrategia-web' ); ?></span>
									<?php endif; ?>
								</div>
							<?php endif; ?>
						</td>
						<td class="estrategia-web-forms-list__notifications">
							<?php
							$count = count( $row['notifications'] );
							/* translators: %d: number of email notifications. */
							echo esc_html( sprintf( _n( '%d notification', '%d notifications', $count, 'estrategia-web' ), $count ) );
							?>
							<?php if ( $count ) : ?>
								<ul style="margin:.3em 0 0 1.2em;list-style:disc">
									<?php foreach ( $row['notifications'] as $n => $notification ) : ?>
										<li>
											<?php
											/* translators: %d: position of the notification in the form. */
											echo esc_html( '' !== $notification['label'] ? $notification['label'] : sprintf( __( 'Notification %d', 'estrategia-web' ), $n + 1 ) );
											?>
											→ <code><?php echo esc_html( '' !== $notification['to'] ? $notification['to'] : '—' ); ?></code>
										</li>
									<?php endforeach; ?>
								</ul>
							<?php endif; ?>
						</td>
						<?php if ( $wpml ) : ?>
							<td><?php echo esc_html( $row['translations'] ? strtoupper( implode( ', ', $row['translations'] ) ) : '—' ); ?></td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
