<?php
/**
 * Module Name: Image uploads
 * Description: Phone photos can be uploaded (up to 20 MB by default); WordPress scales them down to 2560 px and only the scaled copy is kept on disk.
 * Default: on
 * Requires:
 *
 * Replaces Lavandería's MU-plugin wp-content/mu-plugins/estrategia-web-subidas.php
 * (12-10-2026), with the same defaults: 20 MB upload limit, 2560 px
 * scale-down threshold (WordPress' big_image_size_threshold) and deleting the
 * huge original once WordPress has scaled it down. The three values are
 * changed in "Settings → Media → Image uploads". What each one does, and its
 * pitfalls, in includes/class-uploads.php.
 *
 * Why it ships on: it replaces what the site using it (Lavandería) ALREADY
 * did, and changes nothing visible on the front end. When deployed to a site
 * that already had its module list saved, it switches itself on (edge case in
 * includes/class-modules.php). WHEN IT IS PUBLISHED ON LAVANDERÍA, THE
 * DEPLOYER DELETES THE MU-PLUGIN: both at once break nothing (the limit stays
 * at the lower one and the original is already gone when the second one
 * runs), but it is the same work done twice and two places to look when
 * something fails.
 *
 * How to maintain:
 * - Who: whoever touches uploads. When: if WordPress changes its default
 *   threshold (2560 today), change DEFAULTS in the class, not here.
 * - Criterion: a new setting belongs here if it changes HOW an upload is
 *   stored (sizes, format, limit). Whatever changes how a photo is SERVED
 *   (sizes, lazy loading) belongs to whoever renders it (the carousel, the theme).
 * - Edge case, by name: WebP (webp module). With it, a SMALL JPEG, which
 *   WordPress does not scale down, also ends up with original_image (the
 *   uploaded .jpg, because the main file becomes .webp), and that .jpg is
 *   deleted too: that is what the MU-plugin did in production. To keep it,
 *   switch off "Delete the original" (or use the
 *   estrategia_web_uploads_delete_original filter).
 * - The pitfall: the webp module's test expects the .jpg as original_image;
 *   with this module on it no longer exists. That test switches deletion off
 *   at the start (see modules/webp/tests/webp.test.mjs).
 * - Tests: modules/uploads/tests/ (level 1, the values; level 2, a real
 *   upload).
 */

namespace EstrategiaWeb\Uploads;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/includes/class-uploads.php';

Uploads::boot();
