<?php
/**
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

/**
 * Add the MakeCore page under the Settings menu.
 */
function fMcAddMenu() {
    add_options_page('MakeCore', 'MakeCore', cMcCap, 'makecore',
        'fMcRenderPage');
}

/**
 * Post and page statuses MakeCore considers. Includes trash.
 */
function fMcStatusList() {
    return array('publish', 'future', 'draft', 'pending', 'private',
        'trash');
}

/**
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

/**
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

/**
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

/**
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

/**
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

/**
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

/**
 * Build the delete list for one type: every URL of type pType that
 * is not on the matching keep list, sorted by URL.
 */
function fMcMakeList($pKeepText, $pType, &$pBad) {
    $tKeep = array_merge(fMcKeepIds($pKeepText, $pType, $pBad),
        fMcForcedKeep());
    $tOut = array();
    foreach (fMcAllIds(array($pType)) as $tId) {
        if (in_array($tId, $tKeep)) {
            continue;
        }
        $tOut[] = get_permalink($tId);
    }
    sort($tOut);
    return implode("\n", $tOut);
}

/**
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

/**
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

/**
 * Read one textarea from POST, unslashed and sanitized.
 */
function fMcPostText($pName) {
    if (! isset($_POST[$pName])) {
        return '';
    }
    return sanitize_textarea_field(wp_unslash($_POST[$pName]));
}

/**
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
        $gMcDelPost = '';
        $gMcDelPage = '';
        $gMcNotice[] = array('notice-success',
            sprintf('Deleted %d posts and pages, and %d revisions'
                . ' from what remains.', $tNum, $tRev));
    }

    if (! empty($tBad)) {
        $gMcNotice[] = array('notice-warning',
            'Skipped these lines: ' . implode(', ', $tBad));
    }
}

/**
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
        . ' the Delete Posts and Delete Pages boxes? This cannot be'
        . ' undone.';
    ?>
    <div class="wrap">
        <h1>MakeCore <?php echo esc_html(cMcVersion); ?></h1>
        <p>List the posts and pages to keep, then review what is
        left before deleting. Deletion is permanent, and revisions
        of the surviving posts and pages are removed as well. Back
        up the database first.</p>

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
            <p class="description">List fills this box with every
            post not kept. Edit it if you want, Delete acts on
            exactly what is here.</p>
            <textarea name="mcDelPosts" rows="15" cols="100"
                class="large-text code"
                style="overflow:auto;"><?php
                echo esc_textarea($gMcDelPost); ?></textarea>

            <h2>Delete Pages</h2>
            <p class="description">List fills this box with every
            page not kept. Edit it if you want, Delete acts on
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
