<?php
/*
 * Plugin Name: MakeCore
 * Plugin URI: https://github.com/TurtleEngr/WP-make-core/tree/main
 * Description: Make the current WordPress site ready to be a core site.
 * Version:     VERSION
 * Text Domain: makecore
 * Author:      TurtleEngr
 * Author URI: https://github.com/TurtleEngr
 * License:     GPL-2.0
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (! defined('ABSPATH')) {
    exit;
}

define('cMcVersion', 'VERSION');
define('cMcOption', 'makecore_keep_lists');
define('cMcCap', 'manage_options');
define('cMcNonce', 'makecore_action');

$gMcNotice = array();  // Messages shown at the top of the page
$gMcDelPost = null;    // Contents of the "Delete Posts" box
$gMcDelPage = null;    // Contents of the "Delete Pages" box

add_action('admin_menu', 'fMcAddMenu');

/*
 * Add the MakeCore page under the Settings menu.
 */
function fMcAddMenu() {
    add_options_page('MakeCore', 'MakeCore', cMcCap, 'makecore',
        'fMcRenderPage');
}

/*
 * Post and page statuses MakeCore considers. Includes trash.
 */
function fMcStatusList() {
    return array('publish', 'future', 'draft', 'pending', 'private',
        'trash');
}

/*
 * IDs that are never listed or deleted: the static front page and
 * the posts page, if they are set.
 */
function fMcForcedKeep() {
    $tKeep = array();
    $tId = (int) get_option('page_on_front');
    if ($tId > 0) {
        $tKeep[] = $tId;
    }
    $tId = (int) get_option('page_for_posts');
    if ($tId > 0) {
        $tKeep[] = $tId;
    }
    return $tKeep;
}

/*
 * Split textarea text into a list of trimmed URLs. Blank lines and
 * lines starting with "#" are ignored.
 */
function fMcParseUrls($pText) {
    $tList = array();
    foreach (explode("\n", $pText) as $tLine) {
        $tLine = trim($tLine);
        if ($tLine === '' || substr($tLine, 0, 1) === '#') {
            continue;
        }
        $tList[] = $tLine;
    }
    return $tList;
}

/*
 * Look up a post or page ID by its slug, in any status. Used for
 * drafts and trashed items, which url_to_postid() cannot resolve.
 */
function fMcSlugToId($pUrl) {
    global $wpdb;

    $tPath = parse_url($pUrl, PHP_URL_PATH);
    if (empty($tPath)) {
        $tPath = $pUrl;
    }
    $tSlug = rawurldecode(basename(untrailingslashit($tPath)));
    if ($tSlug === '') {
        return 0;
    }
    $tId = $wpdb->get_var($wpdb->prepare(
        "SELECT ID FROM $wpdb->posts WHERE post_name = %s"
        . " AND post_type IN ('post','page') ORDER BY ID LIMIT 1",
        $tSlug));

    return $tId ? (int) $tId : 0;
}

/*
 * Resolve one URL to a post or page ID. Returns 0 if not found.
 */
function fMcResolveUrl($pUrl) {
    $tQuery = parse_url($pUrl, PHP_URL_QUERY);
    if (! empty($tQuery)) {
        $tArg = array();
        parse_str($tQuery, $tArg);
        if (! empty($tArg['p'])) {
            return (int) $tArg['p'];
        }
        if (! empty($tArg['page_id'])) {
            return (int) $tArg['page_id'];
        }
    }
    $tId = url_to_postid($pUrl);
    if ($tId > 0) {
        return $tId;
    }
    return fMcSlugToId($pUrl);
}

/*
 * Strip the scheme and domain from a URL, leaving the path. Any
 * query string is kept, so plain permalinks ("/?p=12") still
 * resolve. For example:
 *   https://example.com/2026/05/my-post/  ->  /2026/05/my-post/
 */
function fMcUrlPath($pUrl) {
    $tPath = parse_url($pUrl, PHP_URL_PATH);
    if (empty($tPath)) {
        $tPath = '/';
    }
    $tQuery = parse_url($pUrl, PHP_URL_QUERY);
    if (! empty($tQuery)) {
        $tPath .= '?' . $tQuery;
    }
    return $tPath;
}

/*
 * Every ID of the given post types, in all statuses. pType is an
 * array, such as array('post') or array('post', 'page').
 */
function fMcAllIds($pType) {
    return get_posts(array(
        'post_type' => $pType,
        'post_status' => fMcStatusList(),
        'numberposts' => -1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
}

/*
 * Resolve keep-list text to IDs of type pType ('post' or 'page').
 * URLs that do not resolve, or that resolve to the other type, are
 * added to pBad.
 */
function fMcKeepIds($pText, $pType, &$pBad) {
    $tIds = array();
    foreach (fMcParseUrls($pText) as $tUrl) {
        $tId = fMcResolveUrl($tUrl);
        if ($tId <= 0) {
            $pBad[] = $tUrl . ' (no matching post or page)';
            continue;
        }
        if (get_post_type($tId) !== $pType) {
            $pBad[] = $tUrl . ' (not a ' . $pType . ')';
            continue;
        }
        $tIds[] = $tId;
    }
    return $tIds;
}

/*
 * Build the delete list for one type: the path of every item of
 * type pType that is not on the matching keep list, sorted by path.
 */
function fMcMakeList($pKeepText, $pType, &$pBad) {
    $tKeep = array_merge(fMcKeepIds($pKeepText, $pType, $pBad),
        fMcForcedKeep());
    $tOut = array();
    foreach (fMcAllIds(array($pType)) as $tId) {
        if (in_array($tId, $tKeep)) {
            continue;
        }
        $tOut[] = fMcUrlPath(get_permalink($tId));
    }
    sort($tOut);
    return implode("\n", $tOut);
}

/*
 * Permanently delete the posts and pages named in pText. Returns the
 * number deleted. Lines that could not be used are added to pBad.
 */
function fMcDeleteUrls($pText, &$pBad) {
    $tKeep = fMcForcedKeep();
    $tCount = 0;
    foreach (fMcParseUrls($pText) as $tUrl) {
        $tId = fMcResolveUrl($tUrl);
        if ($tId <= 0) {
            $pBad[] = $tUrl . ' (no matching post or page)';
            continue;
        }
        $tType = get_post_type($tId);
        if ($tType !== 'post' && $tType !== 'page') {
            $pBad[] = $tUrl . ' (not a post or page)';
            continue;
        }
        if (in_array($tId, $tKeep)) {
            $pBad[] = $tUrl . ' (front page or posts page, skipped)';
            continue;
        }
        if (wp_delete_post($tId, true)) {
            $tCount++;
        } else {
            $pBad[] = $tUrl . ' (delete failed)';
        }
    }
    return $tCount;
}

/*
 * Delete every revision of the remaining posts and pages. Returns
 * the number of revisions deleted.
 */
function fMcPurgeRevisions() {
    $tCount = 0;
    foreach (fMcAllIds(array('post', 'page')) as $tId) {
        $tRevList = wp_get_post_revisions($tId,
            array('fields' => 'ids'));
        foreach ($tRevList as $tRev) {
            if (wp_delete_post_revision($tRev)) {
                $tCount++;
            }
        }
    }
    return $tCount;
}

/*
 * Every attachment ID. Attachments use the "inherit" status, so
 * fMcStatusList() does not apply to them.
 */
function fMcAllMediaIds() {
    return get_posts(array(
        'post_type' => 'attachment',
        'post_status' => 'any',
        'numberposts' => -1,
        'fields' => 'ids',
        'orderby' => 'ID',
        'order' => 'ASC',
    ));
}

/*
 * The content and excerpt of every remaining post and page, joined
 * into one string. This is what is searched for media references.
 */
function fMcRemainText() {
    $tText = '';
    foreach (fMcAllIds(array('post', 'page')) as $tId) {
        $tPost = get_post($tId);
        if ($tPost) {
            $tText .= $tPost->post_content . "\n"
                . $tPost->post_excerpt . "\n";
        }
    }
    return $tText;
}

/*
 * Attachment IDs that are never deleted: the site icon, the site
 * logo, and the header and background images. Deleting these would
 * break the look of the core site.
 */
function fMcForcedKeepMedia() {
    $tKeep = array();
    $tKeep[] = (int) get_option('site_icon');
    $tKeep[] = (int) get_option('site_logo');
    $tKeep[] = (int) get_theme_mod('custom_logo');

    $tData = get_theme_mod('header_image_data');
    if (is_object($tData) && ! empty($tData->attachment_id)) {
        $tKeep[] = (int) $tData->attachment_id;
    }
    foreach (array('header_image', 'background_image') as $tMod) {
        $tUrl = get_theme_mod($tMod);
        if (! empty($tUrl) && $tUrl !== 'remove-header') {
            $tKeep[] = (int) attachment_url_to_postid($tUrl);
        }
    }

    return array_filter($tKeep);
}

/*
 * Attachment IDs referenced by ID in the remaining posts and pages:
 * featured images, "wp-image-NNN" classes, and gallery shortcodes.
 * Being uploaded to a post (post_parent) does not count.
 */
function fMcUsedMediaIds($pText) {
    $tUsed = array();
    foreach (fMcAllIds(array('post', 'page')) as $tId) {
        $tThumb = (int) get_post_thumbnail_id($tId);
        if ($tThumb > 0) {
            $tUsed[] = $tThumb;
        }
    }

    $tMatch = array();
    preg_match_all('/wp-image-(\d+)/', $pText, $tMatch);
    foreach ($tMatch[1] as $tNum) {
        $tUsed[] = (int) $tNum;
    }

    $tMatch = array();
    preg_match_all('/\[gallery[^\]]*\bids=["\']([\d,\s]+)["\']/',
        $pText, $tMatch);
    foreach ($tMatch[1] as $tList) {
        foreach (explode(',', $tList) as $tNum) {
            $tUsed[] = (int) trim($tNum);
        }
    }

    return $tUsed;
}

/*
 * True if the attachment's file name appears in pText as a file
 * name. The name must be followed by its extension, optionally with
 * a size suffix in between, so "photo.jpg" is matched by
 * "photo-300x200.jpg" and "photo-scaled.jpg" but not by the bare
 * word "photo" in a sentence. A .webp or .avif copy made by an
 * image optimizer also counts.
 */
function fMcFileInText($pId, $pText) {
    $tFile = get_post_meta($pId, '_wp_attached_file', true);
    if (empty($tFile)) {
        return false;
    }
    $tName = basename($tFile);
    $tExt = '';
    $tDot = strrpos($tName, '.');
    if ($tDot !== false) {
        $tExt = substr($tName, $tDot + 1);
        $tName = substr($tName, 0, $tDot);
    }
    if ($tName === '' || $tExt === '') {
        return false;
    }
    $tPat = '/' . preg_quote($tName, '/')
        . '(?:-\d+x\d+|-scaled|-rotated|-e\d+)*'
        . '\.(?:' . preg_quote($tExt, '/') . '|webp|avif)/i';

    return preg_match($tPat, $pText) === 1;
}

/*
 * Delete every media file that no remaining post or page refers to.
 * The file and all of its generated sizes are removed. Returns the
 * number of attachments deleted.
 *
 * Call this after the posts and pages have been deleted, so that
 * media used only by them is seen as unused.
 */
function fMcPurgeMedia() {
    $tText = fMcRemainText();
    $tKeep = array_merge(fMcForcedKeepMedia(),
        fMcUsedMediaIds($tText));
    $tCount = 0;
    foreach (fMcAllMediaIds() as $tId) {
        if (in_array($tId, $tKeep)) {
            continue;
        }
        if (fMcFileInText($tId, $tText)) {
            continue;
        }
        if (wp_delete_attachment($tId, true)) {
            $tCount++;
        }
    }
    return $tCount;
}

/*
 * File name extensions the uploads sweep is allowed to delete.
 * Anything else (.php, .htaccess, .css, .js, .zip, .log) is left
 * alone, so protective files, plugin output, and backups survive.
 */
function fMcMediaExt() {
    return array('jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif',
        'bmp', 'tif', 'tiff', 'ico', 'heic', 'heif', 'svg', 'svgz',
        'mp3', 'm4a', 'ogg', 'oga', 'wav', 'flac', 'mp4', 'm4v',
        'mov', 'wmv', 'avi', 'mpg', 'mpeg', 'ogv', 'webm', '3gp',
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'odt',
        'psd');
}

/*
 * Directory names the uploads sweep never descends into. These hold
 * plugin data, form uploads, caches, and, on a multisite network,
 * the media belonging to the other sites.
 */
function fMcSkipDirs() {
    return array('sites', 'woocommerce_uploads', 'wc-logs',
        'updraft', 'backwpup', 'ai1wm-backups', 'backupbuddy',
        'wpvivid', 'elementor', 'et-cache', 'cache', 'gravity_forms',
        'wpforms', 'formidable', 'wp-personal-data-exports');
}

/*
 * Every file on disk claimed by a surviving attachment: the
 * original, each generated size, the pre-scale original, and any
 * .webp or .avif copy an image optimizer made alongside them.
 * Returned as a lookup keyed by normalized full path.
 */
function fMcKeptFiles() {
    $tKeep = array();
    foreach (fMcAllMediaIds() as $tId) {
        $tFile = get_attached_file($tId);
        if (empty($tFile)) {
            continue;
        }
        $tList = array($tFile);
        $tDir = dirname($tFile);
        $tMeta = wp_get_attachment_metadata($tId);
        if (! empty($tMeta['original_image'])) {
            $tList[] = $tDir . '/' . $tMeta['original_image'];
        }
        if (! empty($tMeta['sizes'])) {
            foreach ($tMeta['sizes'] as $tSize) {
                if (! empty($tSize['file'])) {
                    $tList[] = $tDir . '/' . $tSize['file'];
                }
            }
        }
        foreach ($tList as $tPath) {
            $tPath = wp_normalize_path($tPath);
            $tBase = preg_replace('/\.[^.\/]+$/', '', $tPath);
            $tKeep[$tPath] = true;
            $tKeep[$tPath . '.webp'] = true;
            $tKeep[$tPath . '.avif'] = true;
            $tKeep[$tBase . '.webp'] = true;
            $tKeep[$tBase . '.avif'] = true;
        }
    }
    return $tKeep;
}

/*
 * Delete every file in the uploads tree that no surviving
 * attachment claims. This is what catches files that were never in
 * the media library, left-overs from attachments deleted long ago,
 * and thumbnail sizes WordPress no longer tracks.
 *
 * Only the extensions in fMcMediaExt() are removed, and the
 * directories in fMcSkipDirs() are skipped whole. Returns the
 * number of files deleted.
 *
 * Call this last, after fMcPurgeMedia(), so that the files of the
 * attachments it deleted are already gone.
 */
function fMcSweepUploads() {
    $tUp = wp_upload_dir();
    if (! empty($tUp['error']) || empty($tUp['basedir'])
        || ! is_dir($tUp['basedir'])) {
        return 0;
    }
    $tKeep = fMcKeptFiles();
    $tSkip = fMcSkipDirs();
    $tExt = fMcMediaExt();
    $tCount = 0;

    $tDir = new RecursiveDirectoryIterator($tUp['basedir'],
        FilesystemIterator::SKIP_DOTS);
    $tFilter = new RecursiveCallbackFilterIterator($tDir,
        function ($pItem) use ($tSkip) {
            if ($pItem->isDir()) {
                return ! in_array(
                    strtolower($pItem->getFilename()), $tSkip);
            }
            return true;
        });

    foreach (new RecursiveIteratorIterator($tFilter) as $tItem) {
        if (! $tItem->isFile() || $tItem->isLink()) {
            continue;
        }
        $tPath = wp_normalize_path($tItem->getPathname());
        if (isset($tKeep[$tPath])) {
            continue;
        }
        $tDot = strrpos($tItem->getFilename(), '.');
        if ($tDot === false) {
            continue;
        }
        $tThis = strtolower(
            substr($tItem->getFilename(), $tDot + 1));
        if (! in_array($tThis, $tExt)) {
            continue;
        }
        if (@unlink($tPath)) {
            $tCount++;
        }
    }
    return $tCount;
}

/*
 * Remove the directories left empty by the sweep, such as the year
 * and month folders whose files are all gone. The uploads
 * directory itself is kept, and the directories in fMcSkipDirs()
 * are neither entered nor removed. Returns the number removed.
 *
 * Call this after fMcSweepUploads(). Directories are tried deepest
 * first, so a month folder is gone before its year folder is
 * tried, and the year folder can go in the same pass.
 */
function fMcPruneDirs() {
    $tUp = wp_upload_dir();
    if (! empty($tUp['error']) || empty($tUp['basedir'])
        || ! is_dir($tUp['basedir'])) {
        return 0;
    }
    $tSkip = fMcSkipDirs();
    $tList = array();

    $tDir = new RecursiveDirectoryIterator($tUp['basedir'],
        FilesystemIterator::SKIP_DOTS);
    $tFilter = new RecursiveCallbackFilterIterator($tDir,
        function ($pItem) use ($tSkip) {
            if ($pItem->isDir()) {
                return ! in_array(
                    strtolower($pItem->getFilename()), $tSkip);
            }
            return true;
        });
    $tIter = new RecursiveIteratorIterator($tFilter,
        RecursiveIteratorIterator::SELF_FIRST);

    foreach ($tIter as $tItem) {
        if ($tItem->isDir() && ! $tItem->isLink()) {
            $tList[] = wp_normalize_path($tItem->getPathname());
        }
    }

    usort($tList, function ($pA, $pB) {
        return substr_count($pB, '/') - substr_count($pA, '/');
    });

    $tCount = 0;
    foreach ($tList as $tPath) {
        if (@rmdir($tPath)) {
            $tCount++;
        }
    }
    return $tCount;
}

/*
 * Read one textarea from POST, unslashed and sanitized.
 */
function fMcPostText($pName) {
    if (! isset($_POST[$pName])) {
        return '';
    }
    return sanitize_textarea_field(wp_unslash($_POST[$pName]));
}

/*
 * Handle the List and Delete buttons. Sets the globals used by
 * fMcRenderPage().
 */
function fMcHandlePost() {
    global $gMcNotice, $gMcDelPost, $gMcDelPage;

    if (empty($_POST['mcAction'])) {
        return;
    }
    check_admin_referer(cMcNonce, 'mcNonce');
    if (! current_user_can(cMcCap)) {
        wp_die('You do not have permission to use MakeCore.');
    }

    $tKeepPost = fMcPostText('mcKeepPosts');
    $tKeepPage = fMcPostText('mcKeepPages');
    $gMcDelPost = fMcPostText('mcDelPosts');
    $gMcDelPage = fMcPostText('mcDelPages');
    update_option(cMcOption,
        array('posts' => $tKeepPost, 'pages' => $tKeepPage));

    $tBad = array();
    $tAction = sanitize_key(wp_unslash($_POST['mcAction']));

    if ($tAction === 'list') {
        $gMcDelPost = fMcMakeList($tKeepPost, 'post', $tBad);
        $gMcDelPage = fMcMakeList($tKeepPage, 'page', $tBad);
        $gMcNotice[] = array('notice-info',
            sprintf('%d posts and %d pages listed for deletion.',
                count(fMcParseUrls($gMcDelPost)),
                count(fMcParseUrls($gMcDelPage))));
    } elseif ($tAction === 'delete') {
        $tNum = fMcDeleteUrls($gMcDelPost . "\n" . $gMcDelPage,
            $tBad);
        $tRev = fMcPurgeRevisions();
        $tMedia = fMcPurgeMedia();
        $tFile = fMcSweepUploads();
        $tDir = fMcPruneDirs();
        $gMcDelPost = '';
        $gMcDelPage = '';
        $gMcNotice[] = array('notice-success',
            sprintf('Deleted %d posts and pages, and %d revisions'
                . ' from what remains. Removed %d unused media'
                . ' files, %d orphan files, and %d empty folders'
                . ' from the uploads directory.',
                $tNum, $tRev, $tMedia, $tFile, $tDir));
    }

    if (! empty($tBad)) {
        $gMcNotice[] = array('notice-warning',
            'Skipped these lines: ' . implode(', ', $tBad));
    }
}

/*
 * Draw the MakeCore settings page.
 */
function fMcRenderPage() {
    global $gMcNotice, $gMcDelPost, $gMcDelPage;

    if (! current_user_can(cMcCap)) {
        wp_die('You do not have permission to use MakeCore.');
    }
    fMcHandlePost();

    $tSaved = get_option(cMcOption,
        array('posts' => '', 'pages' => ''));
    $tKeepPost = isset($_POST['mcKeepPosts'])
        ? fMcPostText('mcKeepPosts') : $tSaved['posts'];
    $tKeepPage = isset($_POST['mcKeepPages'])
        ? fMcPostText('mcKeepPages') : $tSaved['pages'];
    if ($gMcDelPost === null) {
        $gMcDelPost = '';
    }
    if ($gMcDelPage === null) {
        $gMcDelPage = '';
    }
    $tConfirm = 'Permanently delete every post and page listed in'
        . ' the Delete Posts and Delete Pages boxes, every media'
        . ' file that is left unused, and every unclaimed file in'
        . ' the uploads directory? This cannot be undone.';
    ?>
    <div class="wrap">
        <h1>MakeCore <?php echo esc_html(cMcVersion); ?></h1>
        <p>List the posts and pages to keep, then review what is
        left before deleting.<br> <b>Deletion is permanent,</b> and revisions
        of the surviving posts and pages are removed as well, along
        with any media file they no longer refer to and any
        unclaimed file left in the uploads directory.</p>
        <p><b>For help <a
        href="https://github.com/TurtleEngr/WP-make-core/blob/main/README.md"
        target="_blank">Click Here</a></b></p>

        <?php foreach ($gMcNotice as $tNote) { ?>
            <div class="notice <?php echo esc_attr($tNote[0]); ?>">
                <p><?php echo esc_html($tNote[1]); ?></p>
            </div>
        <?php } ?>

        <form method="post">
            <?php wp_nonce_field(cMcNonce, 'mcNonce'); ?>

            <h2>Keep Posts</h2>
            <p class="description">One post URL per line.</p>
            <textarea name="mcKeepPosts" rows="8" cols="100"
                class="large-text code"><?php
                echo esc_textarea($tKeepPost); ?></textarea>

            <h2>Keep Pages</h2>
            <p class="description">One page URL per line. The front
            page and the posts page are always kept.</p>
            <textarea name="mcKeepPages" rows="8" cols="100"
                class="large-text code"><?php
                echo esc_textarea($tKeepPage); ?></textarea>

            <h2>Delete Posts</h2>
            <p class="description">List fills this box with the path
            of every post not kept, one per line.  Edit it if you
            want, Delete acts on exactly what is here.</p>
            <textarea name="mcDelPosts" rows="15" cols="100"
                class="large-text code"
                style="overflow:auto;"><?php
                echo esc_textarea($gMcDelPost); ?></textarea>

            <h2>Delete Pages</h2>
            <p class="description">List fills this box with the
            path of every page not kept, one per line, without the
            domain name. Edit it if you want, Delete acts on
            exactly what is here.</p>
            <textarea name="mcDelPages" rows="15" cols="100"
                class="large-text code"
                style="overflow:auto;"><?php
                echo esc_textarea($gMcDelPage); ?></textarea>

            <p class="submit">
                <button type="submit" name="mcAction" value="list"
                    class="button">List</button><br>
                <button type="submit" name="mcAction" value="delete"
                    class="button button-primary"
                    onclick="return confirm('<?php
                        echo esc_js($tConfirm); ?>');">Delete
                </button>
            </p>
        </form>
    </div>
    <?php
}
