<?php
// +--------------------------------------------------------------------------+
// | glFusion CMS                                                             |
// +--------------------------------------------------------------------------+
// | index.php                                                                |
// |                                                                          |
// | glFusion homepage.                                                       |
// +--------------------------------------------------------------------------+
// | $Id::                                                                   $|
// +--------------------------------------------------------------------------+
// | Copyright (C) 2008-2011 by the following authors:                        |
// |                                                                          |
// | Mark R. Evans          mark AT glfusion DOT org                          |
// |                                                                          |
// | Based on the Geeklog CMS                                                 |
// | Copyright (C) 2000-2008 by the following authors:                        |
// |                                                                          |
// | Authors: Tony Bibbs        - tony@tonybibbs.com                          |
// |          Mark Limburg      - mlimburg@users.sourceforge.net              |
// |          Jason Whittenburg - jwhitten@securitygeeks.com                  |
// |          Dirk Haun         - dirk@haun-online.de                         |
// +--------------------------------------------------------------------------+
// |                                                                          |
// | This program is free software; you can redistribute it and/or            |
// | modify it under the terms of the GNU General Public License              |
// | as published by the Free Software Foundation; either version 2           |
// | of the License, or (at your option) any later version.                   |
// |                                                                          |
// | This program is distributed in the hope that it will be useful,          |
// | but WITHOUT ANY WARRANTY; without even the implied warranty of           |
// | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the            |
// | GNU General Public License for more details.                             |
// |                                                                          |
// | You should have received a copy of the GNU General Public License        |
// | along with this program; if not, write to the Free Software Foundation,  |
// | Inc., 59 Temple Place - Suite 330, Boston, MA  02111-1307, USA.          |
// |                                                                          |
// +--------------------------------------------------------------------------+

if (!@file_exists('siteconfig.php') ) {
    header("Location:admin/install/index.php");
    exit;
}

require_once 'lib-common.php';
USES_lib_story();

$newstories = false;
$displayall = false;
$microsummary = false;
if (isset ($_GET['display'])) {
    if (($_GET['display'] == 'new') && (empty ($topic))) {
        $newstories = true;
    } else if (($_GET['display'] == 'all') && (empty ($topic))) {
        $displayall = true;
    } else if ($_GET['display'] == 'microsummary') {
        $microsummary = true;
    }
}

// Retrieve the archive topic - currently only one supported
$archivetid = DB_getItem ($_TABLES['topics'], 'tid', "archive_flag=1");

// Microsummary support:
// see: http://wiki.mozilla.org/Microsummaries
if( $microsummary ) {
    $sql = " (UNIX_TIMESTAMP(s.date) <= NOW()) AND (draft_flag <> 1)";

    if (empty ($topic)) {
        $sql .= COM_getLangSQL ('tid', 'AND', 's');
    }

    // if a topic was provided only select those stories.
    if (!empty($topic)) {
        $sql .= " AND s.tid = '".DB_escapeString($topic)."' ";
    } elseif (!$newstories) {
        $sql .= " AND frontpage <> 0 ";
    }

    if ($topic != $archivetid) {
        $sql .= " AND s.tid != '".DB_escapeString($archivetid)."' ";
    }

    $sql .= COM_getPermSQL ('AND', 0, 2, 's');
    $sql .= COM_getTopicSQL ('AND', 0, 's') . ' ';

    $msql = "SELECT s.*, UNIX_TIMESTAMP(s.date) AS unixdate, "
            . "UNIX_TIMESTAMP(s.expire) as expireunix, u.uid, u.username, "
            . "u.fullname, u.photo, t.topic, t.imageurl "
            . "FROM {$_TABLES['stories']} AS s LEFT JOIN {$_TABLES['users']} AS u "
            . "ON s.uid=u.uid LEFT JOIN  {$_TABLES['topics']} AS t ON "
            . "s.tid=t.tid WHERE "
            . $sql . " ORDER BY featured DESC, date DESC LIMIT 0, 1";

    $result = DB_query($msql);

    if ( $A = DB_fetchArray( $result ) ) {
        $pagetitle = $_CONF['microsummary_short'].$A['title'];
    } else {
        if(isset( $_CONF['pagetitle'] )) {
            $pagetitle = $_CONF['pagetitle'];
        }
        if( empty( $pagetitle )) {
            if( empty( $topic )) {
                $pagetitle = $_CONF['site_slogan'];
            } else {
                $pagetitle = DB_getItem( $_TABLES['topics'], 'topic',
                                                       "tid = '".DB_escapeString($topic)."'" );
            }
        }
        $pagetitle = $_CONF['site_name'] . ' - ' . $pagetitle;
    }
    die($pagetitle);
}


$page = 1;
if (isset ($_GET['page'])) {
    $page = (int) COM_applyFilter ($_GET['page'], true);
    if ($page == 0) {
        $page = 1;
    }
}

$display = '';

if (!$newstories && !$displayall) {
    // give plugins a chance to replace this page entirely
    $newcontent = PLG_showCenterblock (CENTERBLOCK_FULLPAGE, $page, $topic);
    if (!empty ($newcontent)) {
        echo $newcontent;
        exit;
    }
}

$ratedIds = array();
if ( $_CONF['rating_enabled'] != 0 ) {
    $ratedIds = RATING_getRatedIds('article');
}

if($topic) {
    $header = '<link rel="microsummary" href="' . $_CONF['site_url']
            . '/index.php?display=microsummary&amp;topic=' . urlencode($topic)
            . '" title="Microsummary" />';
} else {
    $header = '<link rel="microsummary" href="' . $_CONF['site_url']
            . '/index.php?display=microsummary" title="Microsummary" />';
}
$display .= COM_siteHeader('menu', '', $header);

$display .= glfusion_UpgradeCheck();

$display .= glfusion_SecurityCheck();

$msg = COM_getMessage();
if ( $msg > 0 ) {
    $plugin = '';
    if (isset ($_GET['plugin'])) {
        $plugin = COM_applyFilter ($_GET['plugin']);
    }
    $display .= COM_showMessage ($msg, $plugin);
}

// Show any Plugin formatted blocks
// Requires a plugin to have a function called plugin_centerblock_<plugin_name>
$displayBlock = PLG_showCenterblock (CENTERBLOCK_TOP, $page, $topic); // top blocks
if (!empty ($displayBlock)) {
    $display .= $displayBlock;
    // Check if theme has added the template which allows the centerblock
    // to span the top over the rightblocks
    if (file_exists($_CONF['path_layout'] . 'topcenterblock-span.thtml')) {
            $topspan = new Template($_CONF['path_layout']);
            $topspan->set_file (array ('topspan'=>'topcenterblock-span.thtml'));
            $topspan->parse ('output', 'topspan');
            $display .= $topspan->finish ($topspan->get_var('output'));
            $GLOBALS['centerspan'] = true;
    }
}

if (!COM_isAnonUser()) {
    $U['maxstories'] = $_USER['maxstories'];
    $U['aids'] = $_USER['aids'];
    $U['tids'] = $_USER['tids'];
} else {
    $U['maxstories'] = 0;
    $U['aids'] = '';
    $U['tids'] = '';
}

$topiclimit = 0;
$story_sort = 'date';
$story_sort_dir = 'DESC';

if ( !empty($topic) ) {
    $result = DB_query("SELECT limitnews,sort_by,sort_dir FROM {$_TABLES['topics']} WHERE tid='".DB_escapeString($topic)."'");
    if ( $result ) {
        list($topiclimit, $story_sort, $story_sort_dir) = DB_fetchArray($result);
    }
}

$maxstories = 0;
if ($U['maxstories'] >= $_CONF['minnews']) {
    $maxstories = $U['maxstories'];
}
if ((!empty ($topic)) && ($maxstories == 0)) {
    if ($topiclimit >= $_CONF['minnews']) {
        $maxstories = $topiclimit;
    }
}
if ($maxstories == 0) {
    $maxstories = $_CONF['limitnews'];
}

$limit = $maxstories;
if ($limit < 1) {
    $limit = 1;
}

// glFusion now allows for articles to be published in the future.  Because of
// this, we need to check to see if we need to rebuild the RDF file in the case
// that any such articles have now been published
COM_rdfUpToDateCheck();

// For similar reasons, we need to see if there are currently two featured
// articles.  Can only have one but you can have one current featured article
// and one for the future...this check will set the latest one as featured
// solely
//STORY_featuredCheck();

// Scan for any stories that have expired and should be archived or deleted
$asql = "SELECT sid,tid,title,expire,statuscode FROM {$_TABLES['stories']} ";
$asql .= 'WHERE (expire <= NOW()) AND (statuscode = ' . STORY_DELETE_ON_EXPIRE;
if (empty ($archivetid)) {
    $asql .= ')';
} else {
    $asql .= ' OR statuscode = ' . STORY_ARCHIVE_ON_EXPIRE . ") AND tid != '".DB_escapeString($archivetid)."'";
}
$expiresql = DB_query ($asql);
while (list ($sid, $expiretopic, $title, $expire, $statuscode) = DB_fetchArray ($expiresql)) {
    if ($statuscode == STORY_ARCHIVE_ON_EXPIRE) {
        if (!empty ($archivetid) ) {
            COM_errorLOG("Archive Story: $sid, Topic: $archivetid, Title: $title, Expired: $expire");
            DB_query ("UPDATE {$_TABLES['stories']} SET tid = '".DB_escapeString($archivetid)."', frontpage = '0', featured = '0' WHERE sid='".DB_escapeString($sid)."'");
            CACHE_remove_instance('story_'.$sid);
            CACHE_remove_instance('whatsnew');
        }
    } else if ($statuscode == STORY_DELETE_ON_EXPIRE) {
        COM_errorLOG("Delete Story and comments: $sid, Topic: $expiretopic, Title: $title, Expired: $expire");
        STORY_deleteImages ($sid);
        DB_query("DELETE FROM {$_TABLES['comments']} WHERE sid='".DB_escapeString($sid)."' AND type = 'article'");
        DB_query("DELETE FROM {$_TABLES['stories']} WHERE sid='".DB_escapeString($sid)."'");
        CACHE_remove_instance('story_'.$sid);
        CACHE_remove_instance('whatsnew');
    }
}

$sql = " (date <= NOW()) AND (draft_flag = 0)";

if (empty ($topic)) {
    $sql .= COM_getLangSQL ('tid', 'AND', 's');
}

// if a topic was provided only select those stories.
if (!empty($topic)) {
    $sql .= " AND s.tid = '".DB_escapeString($topic)."' ";
} elseif (!$newstories) {
    $sql .= " AND frontpage = 1 ";
}

if ($topic != $archivetid) {
    $sql .= " AND s.tid != '".DB_escapeString($archivetid)."' ";
}

$sql .= COM_getPermSQL ('AND', 0, 2, 's');

if (!empty($U['aids'])) {
    $sql .= " AND s.uid NOT IN (" . str_replace( ' ', ",", $U['aids'] ) . ") ";
}

if (!empty($U['tids'])) {
    $sql .= " AND s.tid NOT IN ('" . str_replace( ' ', "','", $U['tids'] ) . "') ";
}

$sql .= COM_getTopicSQL ('AND', 0, 's') . ' ';

if ($newstories) {
    $sql .= "AND (date >= (date_sub(NOW(), INTERVAL {$_CONF['newstoriesinterval']} SECOND))) ";
}

$offset = intval(($page - 1) * $limit);
$userfields = 'u.uid, u.username, u.fullname';
if ($_CONF['allow_user_photo'] == 1) {
    $userfields .= ', u.photo';
    if ($_CONF['use_gravatar']) {
        $userfields .= ', u.email';
    }
}

if ( !empty($topic) ) {
    switch ( $story_sort ) {
        case 0 :    // date
            $orderBy = ' date ';
            break;
        case 1 :    // title
            $orderBy = ' title ';
            break;
        case 2 :    // ID
            $orderBy = ' sid ';
            break;
        default :
            $orderBy = ' date ';
            break;
    }
    switch ( $story_sort_dir ) {
        case 'DESC' :
            $orderBy = $orderBy . ' DESC ';
            break;
        case 'ASC' :
            $orderBy = $orderBy . ' ASC ';
            break;
        default :
            $orderBy = $orderBy . ' DESC ';
            break;
    }
} else {
    $orderBy = ' date DESC';
}

$msql = "SELECT s.*, UNIX_TIMESTAMP(s.date) AS unixdate, "
         . 'UNIX_TIMESTAMP(s.expire) as expireunix, '
         . $userfields . ", t.topic, t.imageurl "
         . "FROM {$_TABLES['stories']} AS s LEFT JOIN {$_TABLES['users']} AS u ON s.uid=u.uid "
         . "LEFT JOIN {$_TABLES['topics']} AS t on s.tid=t.tid WHERE "
         . $sql . "ORDER BY featured DESC," . $orderBy . " LIMIT $offset, $limit";

$result = DB_query ($msql);

$nrows = DB_numRows ($result);

$data = DB_query ("SELECT COUNT(*) AS count FROM {$_TABLES['stories']} AS s WHERE" . $sql);
$D = DB_fetchArray ($data);
$num_pages = ceil ($D['count'] / $limit);

if ( $A = DB_fetchArray( $result ) ) {

    $story = new Story();
    $story->loadFromArray($A);
    if ( $_CONF['showfirstasfeatured'] == 1 ) {
        $story->_featured = 1;
    }

    // display first article
    if ($story->DisplayElements('featured') == 1) {
        $display .= STORY_renderArticle ($story, 'y');
        $display .= PLG_showCenterblock (CENTERBLOCK_AFTER_FEATURED, $page, $topic);
    } else {
        $display .= PLG_showCenterblock (CENTERBLOCK_AFTER_FEATURED, $page, $topic);
        $display .= STORY_renderArticle ($story, 'y');
    }

    // get remaining stories
    while ($A = DB_fetchArray ($result)) {
        $story = new Story();
        $story->loadFromArray($A);
        $display .= STORY_renderArticle ($story, 'y');
    }

    // get plugin center blocks that follow articles
    $display .= PLG_showCenterblock (CENTERBLOCK_BOTTOM, $page, $topic); // bottom blocks

    // Print Google-like paging navigation
    if (!isset ($_CONF['hide_main_page_navigation']) ||
            ($_CONF['hide_main_page_navigation'] == 0)) {
        if (empty ($topic)) {
            $base_url = $_CONF['site_url'] . '/index.php';
            if ($newstories) {
                $base_url .= '?display=new';
            }
        } else {
            $base_url = $_CONF['site_url'] . '/index.php?topic=' . $topic;
        }
        $display .= COM_printPageNavigation ($base_url, $page, $num_pages);
    }
} else { // no stories to display
    $display .= PLG_showCenterblock (CENTERBLOCK_AFTER_FEATURED, $page, $topic);
    $display .= PLG_showCenterblock (CENTERBLOCK_BOTTOM, $page, $topic); // bottom blocks
    if ( (!isset ($_CONF['hide_no_news_msg']) ||
            ($_CONF['hide_no_news_msg'] == 0)) && $display == '') {
        // If there's still nothing to display, show any default centerblocks.
        $display .= PLG_showCenterblock(CENTERBLOCK_NONEWS, $page, $topic);
        if ($display == '') {
            // If there's *still* nothing to show, show the stock message
            $display .= COM_startBlock ($LANG05[1], '',
                    COM_getBlockTemplate ('_msg_block', 'header')) . $LANG05[2];
            if (!empty ($topic)) {
                $topicname = DB_getItem ($_TABLES['topics'], 'topic',
                                         "tid = '".DB_escapeString($topic)."'");
                $display .= sprintf ($LANG05[3], $topicname);
            }
            $display .= COM_endBlock (COM_getBlockTemplate ('_msg_block', 'footer'));
        }
    }
}

$display .= COM_siteFooter (true); // The true value enables right hand blocks.

// Output page
echo $display;

?>