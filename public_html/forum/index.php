<?php
// +--------------------------------------------------------------------------+
// | Forum Plugin for glFusion CMS                                            |
// +--------------------------------------------------------------------------+
// | index.php                                                                |
// |                                                                          |
// | Main program to view forum                                               |
// +--------------------------------------------------------------------------+
// | $Id::                                                                   $|
// +--------------------------------------------------------------------------+
// | Copyright (C) 2008-2010 by the following authors:                        |
// |                                                                          |
// | Mark R. Evans          mark AT glfusion DOT org                          |
// |                                                                          |
// | Based on the Forum Plugin for Geeklog CMS                                |
// | Copyright (C) 2000-2008 by the following authors:                        |
// |                                                                          |
// | Authors: Blaine Lang       - blaine AT portalparts DOT com               |
// |                              www.portalparts.com                         |
// | Version 1.0 co-developer:    Matthew DeWyer, matt@mycws.com              |
// | Prototype & Concept :        Mr.GxBlock, www.gxblock.com                 |
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

require_once '../lib-common.php'; // Path to your lib-common.php
require_once $_CONF['path'] . 'plugins/forum/include/gf_format.php';

if (!in_array('forum', $_PLUGINS)) {
    COM_404();
    exit;
}

// Pass thru filter any get or post variables to only allow numeric values and remove any hostile data
$forum      = isset($_REQUEST['forum']) ? COM_applyFilter($_REQUEST['forum'],true) : 0;
$show       = isset($_REQUEST['show']) ? COM_applyFilter($_REQUEST['show'],true) : 0;
$page       = isset($_REQUEST['page']) ? COM_applyFilter($_REQUEST['page'],true) : 0;
$order      = isset($_REQUEST['order']) ? COM_applyFilter($_REQUEST['order'],true) : 0;
$prevorder  = isset($_REQUEST['prevorder']) ? COM_applyFilter($_REQUEST['prevorder']) : 0;
$direction  = isset($_REQUEST['direction']) ? COM_applyFilter($_REQUEST['direction']) : 'DESC';
$sort       = isset($_REQUEST['sort']) ? COM_applyFilter($_REQUEST['sort'],true) : 0;
$cat_id     = isset($_REQUEST['cat_id']) ? COM_applyFilter($_REQUEST['cat_id'],true) : 0;
$op         = isset($_REQUEST['op']) ? COM_applyFilter($_REQUEST['op']) : '';

//Check is anonymous users can access
if ($CONF_FORUM['registration_required'] && COM_isAnonUser()) {
    $display = COM_siteHeader();
    $display .= SEC_loginRequiredForm();
    $display .= COM_siteFooter();
    echo $display;
    exit;
}
$canPost = 0;
$todaysdate=date("l, F d, Y");

// Check to see if request to mark all topics read was requested
if (isset($_USER['uid']) && $_USER['uid'] > 1 && $op == 'markallread') {
    $now = time();
    $categories = array();
    if ($cat_id == 0) {
        $csql = DB_query("SELECT id FROM {$_TABLES['gf_categories']} ORDER BY id");
        while (list ($categoryID) = DB_fetchArray($csql)) {
            $categories[] = $categoryID;
        }
    } else {
        $categories[] = $cat_id;
    }

    foreach ($categories as $category) {
        $fsql = DB_query("SELECT forum_id,grp_id FROM {$_TABLES['gf_forums']} WHERE forum_cat=$category");
        while($frecord = DB_fetchArray($fsql)){
            $groupname = DB_getItem($_TABLES['groups'],'grp_name',"grp_id='{$frecord['grp_id']}'");
            if (SEC_inGroup($groupname)) {
                DB_query("DELETE FROM {$_TABLES['gf_log']} WHERE uid=$_USER[uid] AND forum={$frecord['forum_id']}");
                $tsql = DB_query("SELECT id FROM {$_TABLES['gf_topic']} WHERE forum={$frecord['forum_id']} and pid=0");
                while($trecord = DB_fetchArray($tsql)){
                    $log_sql = DB_query("SELECT * FROM {$_TABLES['gf_log']} WHERE uid=$_USER[uid] AND topic={$trecord['id']} AND forum={$frecord['forum_id']}");
                    if (DB_numRows($log_sql) == 0) {
                        DB_query("INSERT INTO {$_TABLES['gf_log']} (uid,forum,topic,time) VALUES ('$_USER[uid]','$frecord[forum_id]','$trecord[id]','$now')");
                    }
                }
            }
        }
    }
    echo COM_refresh($_CONF['site_url'] .'/forum/index.php');
    exit();
}

// Display Common headers
ob_start();
// echo gf_siteHeader($LANG_GF01['INDEXPAGE']);

//Check if anonymous users allowed to access forum
forum_chkUsercanAccess();

if ($op == 'newposts' AND $_USER['uid'] > 1) {

    echo gf_siteHeader($LANG_GF02['new_posts']);

    if ( $CONF_FORUM['enable_user_rating_system'] && !COM_isAnonUser() ) {
        $rating = intval(DB_getItem($_TABLES['gf_userinfo'],'rating','uid='.intval($_USER['uid'])));
    }

    USES_lib_admin();
    USES_lib_html2text();

    $retval = '';

    $header_arr = array(
        array('text' => $LANG_GF01['FORUM'],  'field' => 'forum'),
        array('text' => $LANG_GF01['TOPIC'],  'field' => 'subject'),
        array('text' => $LANG_GF92['sb_latestposts'],   'field' => 'date', 'nowrap' => true),
    );
    $data_arr = array();
    $text_arr = array();
    if ($CONF_FORUM['usermenu'] == 'navbar') {
        echo forumNavbarMenu($LANG_GF02['new_posts']);
    }
    $retval .= COM_startBlock($LANG_GF02['msg111'], '',
                              COM_getBlockTemplate('_admin_block', 'header'));
    $groups = array ();
    $usergroups = SEC_getUserGroups();
    foreach ($usergroups as $group) {
        $groups[] = $group;
    }
    $grouplist = implode(',',$groups);

    if ($forum > 0) {
        $inforum = "AND forum = '$forum'";
    } else {
        $inforum = "";
    }

    $orderby    = 'date';
    $order      = 1;
    $direction  = "DESC";

    if ($CONF_FORUM['enable_user_rating_system']) {
        $sql = "SELECT * FROM {$_TABLES['gf_topic']} a LEFT JOIN {$_TABLES['gf_forums']} b ON a.forum=b.forum_id WHERE (pid=0) AND b.rating_view <= ".$rating." $inforum AND b.grp_id IN (".$grouplist.") AND b.no_newposts = 0 ORDER BY $orderby $direction";
    } else {
        $sql = "SELECT * FROM {$_TABLES['gf_topic']} a LEFT JOIN {$_TABLES['gf_forums']} b ON a.forum=b.forum_id WHERE (pid=0) $inforum AND b.grp_id IN (".$grouplist.") AND b.no_newposts = 0 ORDER BY $orderby $direction";
    }

    $result = DB_query($sql);

    $nrows = DB_numRows($result);

    $displayrecs = 0;
    for ($i = 1; $i <= $nrows; $i++) {
        $P = DB_fetchArray($result);
        $userlogtime = DB_getItem($_TABLES['gf_log'],"time", "uid=$_USER[uid] AND topic={$P['id']}");
        if ($userlogtime == NULL OR $P['lastupdated'] > $userlogtime) {
            if ($CONF_FORUM['use_censor']) {
                $P['subject'] = COM_checkWords($P['subject']);
                $P['comment'] = COM_checkWords($P['comment']);
            }
            $topic_id = $P['id'];
            $displayrecs++;

            $firstdate = strftime($CONF_FORUM['default_Datetime_format'], $P['date']);
            if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
                $firstdate = @iconv('ISO-8859-1','UTF-8',$firstdate);
            }
            $lastdate  = strftime($CONF_FORUM['default_Datetime_format'], $P['lastupdated']);
            if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
                $lastdate = @iconv('ISO-8859-1','UTF-8',$lastdate);
            }

            if ($P['uid'] > 1) {
                $topicinfo = "{$LANG_GF01['STARTEDBY']} " . COM_getDisplayName($P['uid']) . ', ';
            } else {
                $topicinfo = "{$LANG_GF01['STARTEDBY']} {$P['name']},";
            }

            $topicinfo .= "{$firstdate}<br" . XHTML . ">{$LANG_GF01['VIEWS']}:{$P['views']}, {$LANG_GF01['REPLIES']}:{$P['replies']}<br" . XHTML . ">";

            if (empty ($P['last_reply_rec']) || $P['last_reply_rec'] < 1) {
                $lastid = $P['id'];
                $testText = gf_formatTextBlock($P['comment'],'text','text',$P['status']);
                $testText = strip_tags($testText);
                $html2txt = new html2text($testText,false);
                $testText = trim($html2txt->get_text());
                $lastpostinfogll = htmlspecialchars(preg_replace('#\r?\n#','<br>',strip_tags(substr($testText,0,$CONF_FORUM['contentinfo_numchars']). '...')));
            } else {
                $qlreply = DB_query("SELECT id,uid,name,comment,date,status FROM {$_TABLES['gf_topic']} WHERE id={$P['last_reply_rec']}");
                $B = DB_fetchArray($qlreply);
                $lastid = $B['id'];
                $lastcomment = $B['comment'];
                $P['date'] = $B['date'];
                if ($B['uid'] > 1) {
                    $topicinfo .= sprintf($LANG_GF01['LASTREPLYBY'],COM_getDisplayName($B['uid']));
                } else {
                    $topicinfo .= sprintf($LANG_GF01['LASTREPLYBY'],$B['name']);
                }
                $testText = gf_formatTextBlock($B['comment'],'text','text',$B['status']);
                $testText = strip_tags($testText);
                $html2txt = new html2text($testText,false);
                $testText = trim($html2txt->get_text());
                $lastpostinfogll = htmlspecialchars(preg_replace('#\r?\n#','<br>',strip_tags(substr($testText,0,$CONF_FORUM['contentinfo_numchars']). '...')));
            }
            $link = '<a class="gf_mootip" style="text-decoration:none; white-space:nowrap;" href="' . $_CONF['site_url'] . '/forum/viewtopic.php?showtopic=' . $topic_id . '&amp;lastpost=true#' . $lastid . '" title="' . htmlspecialchars($P['subject']) . '::' . $lastpostinfogll . '" rel="nofollow">';

            $topiclink = '<a class="gf_mootip" style="text-decoration:none;" href="' . $_CONF['site_url'] .'/forum/viewtopic.php?showtopic=' . $topic_id . '" title="' . htmlspecialchars($P['subject']) . '::' . $topicinfo . '">' . $P['subject'] . '</a>';

            $tdate = strftime( $CONF_FORUM['default_Datetime_format'], $P['date'] );
            if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
                $tdate = iconv('ISO-8859-1','UTF-8',$tdate);
            }

            $data_arr[] = array('forum'   => '<a href="'.$_CONF['site_url'].'/forum/index.php?forum='.$P['forum_id'].'">'.$P['forum_name'].'</a>',
                                'subject' => $topiclink,
                                'date'    => $link . $tdate . '</a>'
                                );

            if ($displayrecs >= 100) {
                break;
            }
        }
    }

    $retval .= ADMIN_simpleList("", $header_arr, $text_arr, $data_arr);
    $retval .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));
    echo $retval;

    gf_siteFooter();
    exit();
}

if ($op == 'search') {

    echo gf_siteHeader($LANG_GF02['msg45']);

    $report = new Template(array($_CONF['path'] . 'plugins/forum/templates/',$_CONF['path'] . 'plugins/forum/templates/reports/',$_CONF['path'] . 'plugins/forum/templates/links/'));
    $report->set_file (array ('report' => 'report_results.thtml',
                    'records' => 'report_record.thtml',
                    'outline_header'=>'forum_outline_header.thtml',
                    'outline_footer' => 'forum_outline_footer.thtml',
                    'return' => 'return.thtml'));

    $report->set_var('xhtml',XHTML);
    switch($order) {
        case 1:
            $orderby = 'subject';
            break;
        case 2:
            $orderby = 'replies';
            break;
        case 3:
            $orderby = 'views';
            break;
        case 4:
            $orderby = 'lastupdated';
            break;
        default:
            $orderby = 'lastupdated';
            $order = 4;
            break;
    }
    if ($order == $prevorder) {
        $direction = ($direction == "DESC") ? "ASC" : "DESC";
    } else {
        $direction = ($direction == "ASC") ? "ASC" : "DESC";
    }

    $html_query = strip_tags(COM_stripslashes($_REQUEST['query']));
    $query = DB_escapeString(COM_stripslashes($_REQUEST['query']));
    $report->set_var ('layout_url', $_CONF['layout_url']);
    $report->set_var ('phpself',$_CONF['site_url'] . '/forum/index.php?op=search');
    $report->set_var ('LANG_TITLE',$LANG_GF02['msg119']. ' ' . htmlentities($html_query, ENT_QUOTES, COM_getEncodingt()));
    $report->set_var ('startblock', COM_startBlock( $LANG_GF02['msg119']. ' ' . htmlentities($html_query, ENT_QUOTES, COM_getEncodingt())));
    $report->set_var ('endblock', COM_endBlock());
    $report->set_var ('spacerwidth', '70%');
    $report->set_var ('returnlink', "href=\"{$_CONF['site_url']}/forum/index.php\">");
    $report->set_var ('LANG_return', $LANG_GF02['msg175']);
    $report->parse ('link1','return');
    $report->parse ('header_outline','outline_header');
    $report->parse ('footer_outline','outline_footer');

    $report->set_var ('LANG_Heading1', $LANG_GF01['SUBJECT']);
    $report->set_var ('LANG_Heading2', $LANG_GF01['REPLIES']);
    $report->set_var ('LANG_Heading3', $LANG_GF01['VIEWS']);
    $report->set_var ('LANG_Heading4', $LANG_GF01['DATE']);
    $report->set_var ('op', "&amp;op=search&amp;query=" . htmlentities($html_query, ENT_QUOTES, COM_getEncodingt()));
    $report->set_var ('prevorder', $order);
    $report->set_var ('direction', $direction);
    $report->set_var ('page', '1');
    if ($CONF_FORUM['usermenu'] == 'navbar') {
        $report->set_var('navmenu', forumNavbarMenu());
    } else {
        $report->set_var('navmenu','');
    }

    if ($forum != 0) {
        $inforum = "AND (forum = '$forum')";
    } else {
        $inforum = "";
    }

    if ($CONF_FORUM['mysql4+']) {
        $sql = " (SELECT * FROM {$_TABLES['gf_topic']} WHERE (subject LIKE '%$query%') $inforum ) ";
        $sql .= "UNION ALL (SELECT * FROM {$_TABLES['gf_topic']} WHERE (comment LIKE '%$query%') ";
        $sql .= "$inforum) ORDER BY $orderby $direction LIMIT 100";
        $result = DB_query($sql);
    } else {
        $sql  = "SELECT * FROM {$_TABLES['gf_topic']} WHERE (subject LIKE '%$query%') $inforum OR ";
        $sql .= "(comment LIKE '%$query%') $inforum GROUP BY $orderby ORDER BY $orderby $direction LIMIT 100";
        $result = DB_query($sql);
    }
    $nrows = DB_numRows($result);
    if ($nrows > 0) {
        if ($CONF_FORUM['enable_user_rating_system'] && !COM_isAnonUser() ) {
            $user_rating = intval(DB_getItem($_TABLES['gf_userinfo'],'rating','uid='.intval($_USER['uid'])));
        }
        $csscode = 1;
        for ($i = 1; $i <= $nrows; $i++) {
            $P = DB_fetchArray($result);
            $fres = DB_query("SELECT grp_id,rating_view FROM {$_TABLES['gf_forums']} WHERE forum_id={$P['forum']}");
            list($forumgrpid,$view_rating) = DB_fetchArray($fres);

            $groupname = DB_getItem($_TABLES['groups'],'grp_name',"grp_id='$forumgrpid'");
            if (SEC_inGroup($groupname)) {
                if ( $CONF_FORUM['enable_user_rating_system'] && !COM_isAnonUser() ) {
                    if ( $view_rating > $user_rating ) {
                        continue;
                    }
                }

                if ($CONF_FORUM['use_censor']) {
                    $P['subject'] = COM_checkWords($P['subject']);
                }
                $postdate = COM_getUserDateTimeFormat($P['date']);
                $link = "<a href=\"{$_CONF['site_url']}/forum/viewtopic.php?forum={$P['forum']}&amp;showtopic={$P['id']}&amp;highlight=" . htmlentities($html_query, ENT_QUOTES, COM_getEncodingt()) . "\">";
                $report->set_var('post_start_ahref',$link);
                $report->set_var('post_subject', $P['subject']);
                $report->set_var('post_end_ahref', '</a>');
                $report->set_var('post_date',$postdate[0]);
                $report->set_var('post_replies', $P['replies']);
                $report->set_var('post_views', $P['views']);
                $report->set_var ('csscode', $csscode);
                $report->parse ('report_records', 'records',true);
                if($csscode == 2) {
                    $csscode = 1;
                } else {
                    $csscode++;
                }
            }
        }
    }

    if ($forum == 0) {
        $link = "<p><a href=\"{$_CONF['site_url']}/forum/index.php\">{$LANG_GF02['msg175']}</a><p />";
        $report->set_var ('bottomlink',$link);
    } else {
        $link = "<p><a href=\"{$_CONF['site_url']}/forum/index.php?forum=$forum\">{$LANG_GF02['msg175']}</a><p />";
        $report->set_var ('bottomlink',$link);
    }
    $report->parse ('output', 'report');
    echo $report->finish($report->get_var('output'));
    gf_siteFooter();
    exit();
}

if ($op == 'popular') {

    echo gf_siteHeader($LANG_GF02['msg152']);

    USES_lib_admin();

    $retval = '';

    $header_arr = array(      # display 'text' and use table field 'field'
                  array('text' => $LANG_GF01['FORUM'],     'field' => 'forum_name', 'sort' => false),
                  array('text' => $LANG_GF01['TOPIC'],     'field' => 'subject', 'sort' => false),
                  array('text' => $LANG_GF01['REPLIES'],   'field' => 'replies', 'sort' => false),
                  array('text' => $LANG_GF01['VIEWS'],     'field' => 'views', 'sort' => false),
                  array('text' => $LANG_GF01['DATE'],      'field' => 'date', 'sort' => false, 'nowrap' => true)
    );
    if ($CONF_FORUM['usermenu'] == 'navbar') {
        echo forumNavbarMenu($LANG_GF02['msg201']);
    }

    $retval .= COM_startBlock($LANG_GF02['msg201'], '',
                              COM_getBlockTemplate('_admin_block', 'header'));

    $text_arr = array(
        'has_extras' => true,
        'form_url'   => $_CONF['site_url'] . '/forum/index.php?op=popular',
        'help_url'   => '',
        'nowrap'     => 'date'
    );

    $defsort_arr = array('field'     => 'views',
                         'direction' => 'DESC');

    $groups = array ();
    $usergroups = SEC_getUserGroups();
    foreach ($usergroups as $group) {
        $groups[] = $group;
    }
    $grouplist = implode(',',$groups);

// if user rating, then make sure view_rating < user rating
// add a check if root or forum admin...

    if ( !COM_isAnonUser() && $CONF_FORUM['enable_user_rating_system'] ) {
        $grade = intval(DB_getItem($_TABLES['gf_userinfo'],'rating','uid='.intval($_USER['uid'])));
        $ratingSQL = ' AND b.rating_view <= '.$grade;
    } else {
        $ratingSQL = '';
    }

    $sql  = "SELECT a.id, a.forum, a.name, a.date, a.lastupdated, a.last_reply_rec, a.subject, ";
    $sql .= "a.comment, a.uid, a.name, a.pid, a.replies, a.views, b.forum_name  ";
    $sql .= "FROM {$_TABLES['gf_topic']} a ";
    $sql .= "LEFT JOIN {$_TABLES['gf_forums']} b ON a.forum=b.forum_id ";
    $sql .= "WHERE pid=0 AND b.grp_id IN ($grouplist) AND b.no_newposts = 0 " . $ratingSQL;

    $query_arr = array('table'          => 'topic',
                       'sql'            => $sql,
                       'query_fields'   => array('date','subject','comment','replies','views','id','forum','forum_name'),
                       'default_filter' => '');

    $retval .= ADMIN_list('popular', 'ADMIN_getListField_forum', $header_arr,
                          $text_arr, $query_arr, $defsort_arr);
    $retval .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));

    echo $retval;

    gf_siteFooter();
    exit();
}

if ($op == 'bookmarks' && $_USER['uid'] > 1) {

    echo gf_siteHeader($LANG_GF01['BOOKMARKS']);

    USES_lib_admin();

    $retval = '';

    $header_arr = array(      # display 'text' and use table field 'field'
                  array('text' => '#',                     'field' => 'bookmark', 'sort' => false),
                  array('text' => $LANG_GF01['FORUM'],     'field' => 'forum_name', 'sort' => true),
                  array('text' => $LANG_GF01['TOPIC'],     'field' => 'subject', 'sort' => true),
                  array('text' => $LANG_GF01['AUTHOR'],    'field' => 'name', 'sort' => true),
                  array('text' => $LANG_GF01['REPLIES'],   'field' => 'replies', 'sort' => true),
                  array('text' => $LANG_GF01['VIEWS'],     'field' => 'views', 'sort' => true),
                  array('text' => $LANG_GF01['DATE'],      'field' => 'date', 'sort' => true, 'nowrap' => true)
    );
    if ($CONF_FORUM['usermenu'] == 'navbar') {
        echo forumNavbarMenu($LANG_GF01['BOOKMARKS']);
    }

    $retval .= '<script type="text/javascript">' . LB;
    $retval .= 'var site_url = \''.$_CONF['site_url'].'\';' . LB;
    $retval .= '</script>' . LB;
    $retval .= '<script type="text/javascript" src="'.$_CONF['site_url'].'/forum/javascript/ajax_bookmark.js"></script>' . LB;

    $retval .= COM_startBlock($LANG_GF01['BOOKMARKS'], '',
                              COM_getBlockTemplate('_admin_block', 'header'));

    $text_arr = array(
        'has_extras' => true,
        'form_url'   => $_CONF['site_url'] . '/forum/index.php?op=bookmarks',
        'help_url'   => ''
    );

    $defsort_arr = array('field'     => 'date',
                         'direction' => 'DESC');

    $sql = "SELECT * FROM {$_TABLES['gf_bookmarks']} AS bookmarks LEFT JOIN {$_TABLES['gf_topic']} AS topics ON bookmarks.topic_id=topics.id LEFT JOIN {$_TABLES['gf_forums']} AS forums ON topics.forum=forums.forum_id WHERE topics.id != '' AND bookmarks.uid=" . $_USER['uid'];
    $query_arr = array('table'          => 'gf_bookmarks',
                       'sql'            => $sql,
                       'query_fields'   => array('topics.date','topics.subject','topics.comment','topics.name','topics.replies','topics.views','id','forum','forum_name'),
                       'default_filter' => '');

    $retval .= ADMIN_list('bookmarks', 'ADMIN_getListField_forum', $header_arr,
                          $text_arr, $query_arr, $defsort_arr);
    $retval .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));

    echo $retval;

    gf_siteFooter();
    exit();
}

if ($op == 'lastx') {

    echo gf_siteHeader($LANG_GF01['LASTX']);

    USES_lib_admin();
    USES_lib_html2text();

    $retval = '';

    $header_arr = array(
        array('text' => $LANG_GF01['FORUM'],  'field' => 'forum'),
        array('text' => $LANG_GF01['TOPIC'],  'field' => 'subject'),
        array('text' => $LANG_GF92['sb_latestposts'],   'field' => 'date', 'nowrap' => true),
    );
    $data_arr = array();
    $text_arr = array();
    if ($CONF_FORUM['usermenu'] == 'navbar') {
        echo forumNavbarMenu($LANG_GF01['LASTX']);
    }
    $retval .= COM_startBlock($LANG_GF01['LASTX'], '',
                              COM_getBlockTemplate('_admin_block', 'header'));
    $groups = array ();
    $usergroups = SEC_getUserGroups();
    foreach ($usergroups as $group) {
        $groups[] = $group;
    }
    $grouplist = implode(',',$groups);

// if user rating, add view_rating < user_rating

    if ( !COM_isAnonUser() && $CONF_FORUM['enable_user_rating_system'] ) {
        $grade = intval(DB_getItem($_TABLES['gf_userinfo'],'rating','uid='.intval($_USER['uid'])));
        $ratingSQL = ' AND b.rating_view <= '.$grade .' ';
    } else {
        $ratingSQL = '';
    }

    $sql  = "SELECT * ";
    $sql .= "FROM {$_TABLES['gf_topic']} a ";
    $sql .= "LEFT JOIN {$_TABLES['gf_forums']} b ON a.forum=b.forum_id ";
    $sql .= "WHERE pid=0 AND b.grp_id IN ($grouplist) AND b.no_newposts = 0 " . $ratingSQL;
    $sql .= "ORDER BY lastupdated DESC LIMIT {$CONF_FORUM['show_last_post_count']}";
    $result = DB_query ($sql);

    $nrows = DB_numRows($result);
    $displayrecs = 0;
    for ($i = 1; $i <= $nrows; $i++) {
        $P = DB_fetchArray($result);
        if ($CONF_FORUM['use_censor']) {
            $P['subject'] = COM_checkWords($P['subject']);
            $P['comment'] = COM_checkWords($P['comment']);
        }
        $topic_id = $P['id'];
        $displayrecs++;

        $firstdate = strftime($CONF_FORUM['default_Datetime_format'], $P['date']);
        if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
            $firstdate = iconv('ISO-8859-1','UTF-8',$firstdate);
        }
        $lastdate = strftime($CONF_FORUM['default_Datetime_format'], $P['lastupdated']);
        if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
            $lastdate = iconv('ISO-8859-1','UTF-8',$lastdate);
        }

        if ($P['uid'] > 1) {
            $topicinfo = "{$LANG_GF01['STARTEDBY']} " . COM_getDisplayName($P['uid']) . ', ';
        } else {
            $topicinfo = "{$LANG_GF01['STARTEDBY']} {$P['name']},";
        }

        $topicinfo .= "{$firstdate}<br" . XHTML . ">{$LANG_GF01['VIEWS']}:{$P['views']}, {$LANG_GF01['REPLIES']}:{$P['replies']}<br" . XHTML . ">";

        if (empty ($P['last_reply_rec']) || $P['last_reply_rec'] < 1) {
            $lastid = $P['id'];
            $testText = gf_formatTextBlock($P['comment'],'text','text',$P['status']);
            $testText = strip_tags($testText);
            $html2txt = new html2text($testText,false);
            $testText = trim($html2txt->get_text());
            $lastpostinfogll = htmlspecialchars(preg_replace('#\r?\n#','<br>',strip_tags(substr($testText,0,$CONF_FORUM['contentinfo_numchars']). '...')));
        } else {
            $qlreply = DB_query("SELECT id,uid,name,comment,date,status FROM {$_TABLES['gf_topic']} WHERE id={$P['last_reply_rec']}");
            $B = DB_fetchArray($qlreply);
            $lastid = $B['id'];
            $lastcomment = $B['comment'];
            $P['date'] = $B['date'];
            if ($B['uid'] > 1) {
                $topicinfo .= sprintf($LANG_GF01['LASTREPLYBY'],COM_getDisplayName($B['uid']));
            } else {
                $topicinfo .= sprintf($LANG_GF01['LASTREPLYBY'],$B['name']);
            }
            $testText = gf_formatTextBlock($B['comment'],'text','text',$B['status']);
            $testText = strip_tags($testText);
            $html2txt = new html2text($testText,false);
            $testText = trim($html2txt->get_text());
            $lastpostinfogll = htmlspecialchars(preg_replace('#\r?\n#','<br>',strip_tags(substr($testText,0,$CONF_FORUM['contentinfo_numchars']). '...')));
        }
        $link = '<a class="gf_mootip" style="text-decoration:none; white-space:nowrap;" href="' . $_CONF['site_url'] . '/forum/viewtopic.php?showtopic=' . $topic_id . '&amp;lastpost=true#' . $lastid . '" title="' . htmlspecialchars($P['subject']) . '::' . $lastpostinfogll . '" rel="nofollow">';

        $topiclink = '<a class="gf_mootip" style="text-decoration:none;" href="' . $_CONF['site_url'] .'/forum/viewtopic.php?showtopic=' . $topic_id . '" title="' . htmlspecialchars($P['subject']) . '::' . $topicinfo . '">' . $P['subject'] . '</a>';

        $tdate = strftime( $CONF_FORUM['default_Datetime_format'], $P['date'] );
        if ( $_SYSTEM['swedish_date_hack'] == true && function_exists('iconv') ) {
            $tdate = iconv('ISO-8859-1','UTF-8',$tdate);
        }

        $data_arr[] = array('forum'   => $P['forum_name'],
                            'subject' => $topiclink, /*$link . $P['subject'] . '</a>',*/
                            'date'    => $link . $tdate . '</a>'
                            );

        if ($displayrecs >= $CONF_FORUM['show_last_post_count']) {
            break;
        }
    }

    $retval .= ADMIN_simpleList("", $header_arr, $text_arr, $data_arr);
    $retval .= COM_endBlock(COM_getBlockTemplate('_admin_block', 'footer'));
    echo $retval;

    gf_siteFooter();
    exit();
}

if ($op == 'subscribe') {
    echo gf_siteHeader();
    if ($forum != 0) {
        DB_query("INSERT INTO {$_TABLES['gf_watch']} (forum_id,topic_id,uid,date_added) VALUES ('$forum','0','{$_USER['uid']}', now() )");
        // Delete all individual topic notification records
        DB_query("DELETE FROM {$_TABLES['gf_watch']} WHERE uid='{$_USER['uid']}' AND forum_id='$forum' and topic_id > '0' " );
        forum_statusMessage($LANG_GF02['msg134'],$_CONF['site_url'] .'/forum/index.php?forum=' .$forum,$LANG_GF02['msg135']);
    } else {
        BlockMessage($LANG_GF01['ERROR'],$LANG_GF02['msg136'],false);
    }
    gf_siteFooter();
    exit();
}

echo gf_siteHeader($LANG_GF01['INDEXPAGE']);
// MAIN CODE BEGINS to view forums or topics within a forum
ForumHeader($forum,0);
$errMsg = '';
if( can_view_forum($forum)) {
	// Check if the number of records was specified to show - part of page navigation.
	// Will be 0 if not set - as I'm now passing this tru gf_applyFilter() at top of script
	if ($show == 0 AND $CONF_FORUM['show_topics_perpage'] > 0) {
	    $show = $CONF_FORUM['show_topics_perpage'];
	} elseif ($show == 0) {
	    $show = 20;
	}

	// Check if this is the first page.
	if ($page == 0) {
	    $page = 1;
	}

	if($forum > 0) {
    	$addforumvar = "&amp;forum=" .$forum;
    	$topiclistsql = DB_query("SELECT * FROM {$_TABLES['gf_topic']} WHERE pid=0 and forum='$forum'");
	} else {
   		$topiclistsql = DB_query("SELECT * FROM {$_TABLES['gf_topic']} WHERE pid=0");
	}

	$topicCount = DB_numRows($topiclistsql);
	$numpages = ceil($topicCount / $show);
	$offset = ($page - 1) * $show;
	$base_url = $_CONF['site_url'] . '/forum/index.php?forum='.$forum.'&amp;show='.$show;
} else {
	$errMsg = '<div class="pluginAlert" style="padding:10px;margin:10px;">'.$LANG_GF02['rate_too_low_forum'].'</div>';
	$offset = 0;
}

//Display Categories
if ($forum == 0) {
    $dCat = isset($_REQUEST['cat']) ? COM_applyFilter($_REQUEST['cat'],true) : 0;
    $groups = array ();
    $usergroups = SEC_getUserGroups();
    foreach ($usergroups as $group) {
        $groups[] = $group;
    }
    $groupAccessList = implode(',',$groups);

    if ( $dCat > 0 ) {
        $categoryQuery = DB_query("SELECT * FROM {$_TABLES['gf_categories']} WHERE id=". $dCat . " ORDER BY cat_order ASC");
        $catList = 'singlecategorylisting.thtml';
    } else {
        $categoryQuery = DB_query("SELECT * FROM {$_TABLES['gf_categories']} ORDER BY cat_order ASC");
        $catList = 'categorylisting.thtml';
    }
    $numCategories = DB_numRows($categoryQuery);

    $forumlisting = new Template(array($_CONF['path'] . 'plugins/forum/templates/',$_CONF['path'] . 'plugins/forum/templates/links/'));

    $forumlisting->set_file (array ('forumlisting' => 'homepage.thtml',
            'forum_outline_header'=>'forum_outline_header.thtml',
            'forum_outline_footer'=>'forum_outline_footer.thtml',
            'newposts' => 'newposts.thtml',
            'markread' => 'markread.thtml',
            'forum_record'=>'forumlisting_record.thtml',
            'category_record'=>$catList ));

    $forumlisting->set_var ('xhtml',XHTML);
    $forumlisting->set_var ('forumindeximg','<img src="'.gf_getImage('forumindex').'" alt=""' . XHTML . '>');
    $forumlisting->set_var ('phpself', $_CONF['site_url'] .'/forum/index.php');
    $forumlisting->set_var('layout_url', $_CONF['layout_url']);
    $forumlisting->set_var('forum_home','Forum Index');

    for ($i =1; $i <= $numCategories; $i++) {
        $A = DB_FetchArray($categoryQuery,false);

        $forumlisting->set_var ('cat_name', $A['cat_name']);
        $forumlisting->set_var ('cat_desc', $A['cat_dscp']);
        $forumlisting->set_var ('cat_id', $A['id']);

        if (isset($_USER['uid']) AND $_USER['uid'] > 1) {
            $link = "href=\"{$_CONF['site_url']}/forum/index.php?op=markallread&amp;cat_id={$A['id']}\">";
            $forumlisting->set_var ('markreadlink',$link);
            $forumlisting->set_var ('LANG_markread', $LANG_GF02['msg84']);
            $forumlisting->parse ('markread_link','markread');
            if ($i == 1) {
                $newpostslink = 'href="'.$_CONF['site_url'] .'/forum/index.php?op=newposts">';
                $forumlisting->set_var ('newpostslink', $newpostslink);
                $forumlisting->set_var ('LANG_newposts', $LANG_GF02['msg112']);
                $forumlisting->parse ('newposts_link','newposts');
                $viewnewpostslink = true;
           } else {
                $forumlisting->set_var ('newposts_link', '');
           }
        } else {
            $forumlisting->set_var ('newposts_link', '');
            $forumlisting->set_var ('markread_link', "");
        }

        $forumlisting->set_var ('LANGGF91_forum', $LANG_GF91['forum']);
        $forumlisting->set_var ('LANGGF01_TOPICS', $LANG_GF01['TOPICS']);
        $forumlisting->set_var ('LANGGF01_POSTS', $LANG_GF01['POSTS']);
        $forumlisting->set_var ('LANGGF01_LASTPOST', $LANG_GF01['LASTPOST']);

        //Display all forums under each cat
        $sql = "SELECT * FROM {$_TABLES['gf_forums']} AS f LEFT JOIN {$_TABLES['gf_topic']} AS t ON f.last_post_rec=t.id WHERE forum_cat='{$A['id']}' ";
        $sql .= "AND grp_id IN ($groupAccessList) AND is_hidden=0 ORDER BY forum_order ASC";

        $forumQuery = DB_query($sql);
        $numForums = DB_numRows($forumQuery);

        $numForumsDisplayed = 0;
        while ($B = DB_FetchArray($forumQuery)) {
            //$exectime = $mytimer->stopTimer();
            //COM_errorLog("Start Forum Listing - time:$exectime");
			if(can_view_forum($B['forum_id'])) {
            	$lastforum_noaccess = false;
        	    $topicCount = $B['topic_count'];
            	$postCount = $B['post_count'];
            	if ( $CONF_FORUM['show_moderators'] ) {
                	$modsql = DB_query("SELECT * FROM {$_TABLES['gf_moderators']} WHERE mod_forum='{$B['forum_id']}'");
               	 	$moderatorcnt = 1;
                	if (DB_numRows($modsql) > 0) {
                    	while($showmods = DB_fetchArray($modsql,false)) {
                        	if ($showmods['mod_uid'] == '0') {
                            	if ($showmods['mod_groupid'] > 0) {
                                	$showmods['mod_username'] = DB_getItem($_TABLES['groups'], 'grp_name', "grp_id='{$showmods['mod_groupid']}'");
                            	}
	                            if($moderatorcnt == 1 OR $moderators == '') {
    	                            $moderators = $showmods['mod_username'];
        	                    } else {
            	                    $moderators .= ', ' . $showmods['mod_username'];
                	            }
                    	    } else {
                        	    if($moderatorcnt == 1 OR $moderators == '') {
	                                $moderators = COM_getDisplayName($showmods['mod_uid']);
    	                        } else {
        	                        $moderators .= ', ' . COM_getDisplayName($showmods['mod_uid']);
            	                }
                	        }
                    	    $moderatorcnt++;
	                    }
    	            } else {
        	            $moderators = $LANG_GF01['no_one'];
            	    }
                	$forumlisting->set_var ('moderator', sprintf($LANG_GF01['MODERATED'],$moderators));
	            } else {
    	            $forumlisting->set_var ('moderator', '');
        	    }
           		$numForumsDisplayed ++;
            	if ($postCount > 0) {
                	if ( strlen($B['subject']) > 25 ) {
                    	$B['subject'] = substr($B['subject'],0,25);
	                    $B['subject'] .= "..";
    	            }
        	        if ($CONF_FORUM['use_censor']) {
            	        $B['subject'] = COM_checkWords($B['subject']);
                	}
	                if (isset($_USER['uid']) && $_USER['uid'] > 1) {
    	                // Determine if there are new topics since last visit for this user.
        	            $lsql = DB_query("SELECT * FROM {$_TABLES['gf_log']} WHERE uid='{$_USER['uid']}' AND forum='{$B['forum_id']}' AND time > 0");
            	        if ($topicCount > DB_numRows($lsql)) {
                	        $folderimg = '<img src="'.gf_getImage('busyforum').'" style="border:none;vertical-align:middle;" alt="'.$LANG_GF02['msg111'].'" title="'.$LANG_GF02['msg111'].'"' . XHTML . '>';
                	    } else {
                    	    $folderimg = '<img src="'.gf_getImage('quietforum').'" style="border:none;vertical-align:middle;" alt="'.$LANG_GF02['quietforum'].'" title="'.$LANG_GF02['quietforum'].'"' . XHTML . '>';
                    	}
	                } else {
    	                $folderimg = '<img src="'.gf_getImage('quietforum').'" style="border:none;vertical-align:middle;" alt="'.$LANG_GF02['quietforum'].'" title="'.$LANG_GF02['quietforum'].'"' . XHTML . '>';
	                }

    	            $lastdate1 = strftime('%Y-%m-%d',$B['date']);
        	        if ($lastdate1 == date('Y-m-d') ) {
            	        $lasttime = strftime('%I:%M&nbsp;%p', $B['date']);
                	    $lastdate = $LANG_GF01['TODAY'] .$lasttime;
	                } elseif ($CONF_FORUM['allow_user_dateformat']) {
    	                $lastdate = COM_getUserDateTimeFormat($B['date']);
        	            $lastdate = $lastdate[0];
            	    } else {
                	    $lastdate =strftime('%b/%d/%y %I:%M&nbsp;%p',$B['date']);
	                }

    	            $lastpostmsgDate  = '<span class="forumtxt">' . $LANG_GF01['ON']. '</span>' .$lastdate;
        	        if($B['uid'] > 1) {
            	        $lastposterName = COM_getDisplayName($B['uid']);
                	    $by = '<a href="' .$_CONF['site_url']. '/users.php?mode=profile&amp;uid=' .$B['uid']. '">' .$lastposterName. '</a>';
	                } else {
    	                $by = $B['name'];
        	        }
            	    $lastpostmsgBy = $LANG_GF01['BY']. $by;
                	$forumlisting->set_var ('lastpostmsgDate', $lastpostmsgDate);
	                $forumlisting->set_var ('lastpostmsgTopic', $B['subject']);
    	            $forumlisting->set_var ('lastpostmsgBy', $lastpostmsgBy);

    	        }  else {
        	        $forumlisting->set_var ('lastpostmsgDate', $LANG_GF01['nolastpostmsg']);
            	    $forumlisting->set_var ('lastpostmsgTopic', '');
                	$forumlisting->set_var ('lastpostmsgBy', '');
	                $folderimg = '<img src="'.gf_getImage('quietforum').'" style="border:none;vertical-align:middle;" alt="'.$LANG_GF02['quietforum'].'" title="'.$LANG_GF02['quietforum'].'"' . XHTML . '>';
    	        }

    	        if ($B['pid'] == 0) {
        	        $topicparent = $B['id'];
            	} else {
	                $topicparent = $B['pid'];
    	        }

	            $forumlisting->set_var ('folderimg', $folderimg);
    	        $forumlisting->set_var ('forum_id', $B['forum_id']);
        	    $forumlisting->set_var ('forum_name', $B['forum_name']);
	            $forumlisting->set_var ('forum_desc', $B['forum_dscp']);
    	        $forumlisting->set_var ('topics', $topicCount);
        	    $forumlisting->set_var ('posts', $postCount);
	            $forumlisting->set_var ('topic_id', $topicparent);
    	        $forumlisting->set_var ('lastpostid', $B['id']);
        	    $forumlisting->set_var ('LANGGF01_LASTPOST', $LANG_GF01['LASTPOST']);
            	$forumlisting->parse ('forum_records', 'forum_record',true);
			}
        }

        if ($numForumsDisplayed > 0 ) {
            if (isset($_USER['uid']) AND $_USER['uid'] > 1) {
                $link = "href=\"{$_CONF['site_url']}/forum/index.php?op=markallread&amp;cat_id={$A['id']}\">";
                $forumlisting->set_var ('markreadlink',$link);
                $forumlisting->set_var ('LANG_markread', $LANG_GF02['msg84']);
                $forumlisting->parse ('markread_link','markread');
                if (!isset($viewnewpostslink) || !$viewnewpostslink) {
                    $newpostslink = 'href="'.$_CONF['site_url'] .'/forum/index.php?op=newposts">';
                    $forumlisting->set_var ('newpostslink', $newpostslink);
                    $forumlisting->set_var ('LANG_newposts', $LANG_GF02['msg112']);
                    $viewnewpostslink = true;
                    $forumlisting->parse ('newposts_link','newposts');
                }
            } else {
                $forumlisting->set_var ('newposts_link', '');
                $forumlisting->set_var ('markread_link', "");
            }
            $forumlisting->parse ('category_records', 'category_record',true);
            $forumlisting->set_var ('forum_records', '',false);
        }

    }

    if ($numCategories == 0 ) {         // Do we have any categories defined yet
        echo '<h1 style="padding:10px; color:#F00; background-color:#000">No Categories or Forums Defined</h1>';
    }

    $forumlisting->parse ('outline_header', 'forum_outline_header');
    $forumlisting->parse ('outline_footer', 'forum_outline_footer');
    $forumlisting->parse ('output', 'forumlisting');
    if ( $errMsg != '' ) {
        echo $errMsg;
    } else {
        echo $forumlisting->finish ($forumlisting->get_var('output'));
    }
}

 // Display Forums
if ($forum > 0) {

    $displaypostpages = '';

    $topiclisting = new Template(array($_CONF['path'] . 'plugins/forum/templates/',$_CONF['path'] . 'plugins/forum/templates/links/'));
    $topiclisting->set_file (array ('topiclisting' => 'topiclisting.thtml',
            'forum_outline_header'=>'forum_outline_header.thtml',
            'forum_outline_footer'=>'forum_outline_footer.thtml',
            'subscribe' => 'subscribe_forum.thtml',
            'new' => 'newtopic.thtml',
            'topic_record'=>'topiclist_record.thtml' ));

    $topiclisting->set_var ('xhtml',XHTML);
    $topiclisting->set_var('layout_url', $_CONF['layout_url']);
    $topiclisting->set_var('site_url',$_CONF['site_url']);
    $topiclisting->set_var ('LANG_HOME', $LANG_GF01['HOMEPAGE']);
    $topiclisting->set_var('forum_home',$LANG_GF01['INDEXPAGE']);
    $topiclisting->set_var ('navbreadcrumbsimg','<img src="'.gf_getImage('nav_breadcrumbs').'" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_asc1', '<img src="'.gf_getImage('asc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_asc2', '<img src="'.gf_getImage('asc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_asc3', '<img src="'.gf_getImage('asc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_asc4', '<img src="'.gf_getImage('asc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_asc5', '<img src="'.gf_getImage('asc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_desc1', '<img src="'.gf_getImage('desc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_desc2', '<img src="'.gf_getImage('desc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_desc3', '<img src="'.gf_getImage('desc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_desc4', '<img src="'.gf_getImage('desc').'" border="0" alt=""' . XHTML . '>');
    $topiclisting->set_var ('img_desc5', '<img src="'.gf_getImage('desc').'" border="0" alt=""' . XHTML . '>');

    switch($sort) {
        case 1:
            if($order == 0) {
                $sortOrder = "subject ASC";
                $topiclisting->set_var ('img_asc1', '<img src="'.gf_getImage('asc_on').'" border="0" alt=""' . XHTML . '>');
            } else {
                $sortOrder = "subject DESC";
                $topiclisting->set_var ('img_desc1', '<img src="'.gf_getImage('desc_on').'" border="0" alt=""' . XHTML . '>');
            }
            break;
        case 2:
            if($order == 0) {
                $sortOrder = "views ASC";
                $topiclisting->set_var ('img_asc2', '<img src="'.gf_getImage('asc_on').'" border="0" alt=""' . XHTML . '>');
            } else {
                $sortOrder = "views DESC";
                $topiclisting->set_var ('img_desc2', '<img src="'.gf_getImage('desc_on').'" border="0" alt=""' . XHTML . '>');
            }
            break;
        case 3:
            if($order == 0) {
                $sortOrder = "replies ASC";
                $topiclisting->set_var ('img_asc3', '<img src="'.gf_getImage('asc_on').'" border="0" alt=""' . XHTML . '>');
            } else {
                $sortOrder = "replies DESC";
                $topiclisting->set_var ('img_desc3', '<img src="'.gf_getImage('desc_on').'" border="0" alt=""' . XHTML . '>');
            }
            break;
        case 4:
            if($order == 0) {
                $sortOrder = "name ASC";
                $topiclisting->set_var ('img_asc4', '<img src="'.gf_getImage('asc_on').'" border="0" alt=""' . XHTML . '>');
            } else {
                $sortOrder = "name DESC";
                $topiclisting->set_var ('img_desc4', '<img src="'.gf_getImage('desc_on').'" border="0" alt=""' . XHTML . '>');
            }
            break;
        case 5:
            if($order == 0) {
                $sortOrder = "lastupdated ASC";
                $topiclisting->set_var ('img_asc5', '<img src="' .$_CONF['site_url'] .'/forum/images/asc_on.gif" border="0" alt=""' . XHTML . '>');
            } else {
                $sortOrder = "lastupdated DESC";
                $topiclisting->set_var ('img_desc5', '<img src="' .$_CONF['site_url']. '/forum/images/desc_on.gif" border="0" alt=""' . XHTML . '>');
            }
            break;
        default:
            $sortOrder = "lastupdated DESC";
            $topiclisting->set_var ('img_desc5', '<img src="'.gf_getImage('desc_on').'" border="0" alt=""' . XHTML . '>');
            break;
    }

    $base_url .= "&amp;order=$order&amp;sort=$sort";

    // Retrieve all the Topic Records - where pid is 0 - check to see if user does not want to see anonymous posts
    if ( !isset($_USER['uid']) ) {
        $_USER['uid'] = 1;
    }
    if ($_USER['uid'] > 1 AND $CONF_FORUM['show_anonymous_posts'] == 0) {
        $sql  = "SELECT topic.* FROM {$_TABLES['gf_topic']} topic WHERE topic.forum = '$forum' AND topic.pid = 0 AND topic.uid > 1 ";
    } else {
        $sql  = "SELECT topic.* FROM {$_TABLES['gf_topic']} topic WHERE topic.forum = '$forum' AND topic.pid = 0 ";
    }
    $sql .= "ORDER BY topic.sticky DESC, $sortOrder, topic.id DESC LIMIT $offset, $show";
    $topicResults = DB_query($sql);
    $totalresults = DB_numRows($topicResults);

    // Retrieve Forum details and Category name
    $sql  = "SELECT forum.forum_name,forum.forum_id AS forum, category.cat_name,category.id,forum.is_readonly,forum.grp_id,forum.rating_post,forum.rating_view FROM {$_TABLES['gf_forums']} forum ";
    $sql .= "LEFT JOIN {$_TABLES['gf_categories']} category on category.id=forum.forum_cat ";
    $sql .= "WHERE forum.forum_id = $forum";
    $category = DB_fetchArray(DB_query($sql));
    if($totalresults < 1) {
        $LANG_MSG05 = $LANG_GF02['msg05'];
    }
    $canPost = forum_canPost($category);
    $subscribe = '';
    if (!COM_isAnonUser()) {
        // Check for user subscription status
        $sub_check = DB_getITEM($_TABLES['gf_watch'],"id","forum_id='$forum' AND topic_id=0 AND uid='{$_USER['uid']}'");
        if ($sub_check == '') {
            $subscribelinkimg = '<img src="'.gf_getImage('forumnotify_on').'" style="vertical-align:middle;" alt="'.$LANG_GF01['FORUMSUBSCRIBE'].'" title="'.$LANG_GF01['FORUMSUBSCRIBE'].'"' . XHTML . '>';
            $subscribelink = "{$_CONF['site_url']}/forum/index.php?op=subscribe&amp;forum=$forum";
            $topiclisting->set_var ('subscribelink', $subscribelink);
            $topiclisting->set_var ('subscribelinkimg', $subscribelinkimg);
            $topiclisting->set_var ('LANG_subscribe', $LANG_GF01['FORUMSUBSCRIBE']);
            $topiclisting->parse ('subscribe_link','subscribe');
        } else {
            $subscribelinkimg = '<img src="'.gf_getImage('forumnotify_off').'" alt="'.$LANG_GF01['FORUMUNSUBSCRIBE'].'" title="'.$LANG_GF01['FORUMUNSUBSCRIBE'].'" alt="" style="vertical-align:middle;"' . XHTML . '>';
            $subscribelink = "{$_CONF['site_url']}/forum/notify.php?filter=2";
            $topiclisting->set_var ('subscribelink', $subscribelink);
            $topiclisting->set_var ('subscribelinkimg', $subscribelinkimg);
            $topiclisting->set_var ('LANG_subscribe', $LANG_GF01['FORUMUNSUBSCRIBE']);
            $topiclisting->parse ('subscribe_link','subscribe');
        }
    }
    $rssFeed = DB_getItem($_TABLES['syndication'],'filename','type="forum" AND topic='.$forum.' AND is_enabled=1');
    if ( $rssFeed != '' || $rssFeed != NULL ) {
        $baseurl = SYND_getFeedUrl();
        $imgurl  = '<img src="'.$_CONF['layout_url'].'/images/rss_small.png" alt="'.$LANG_GF01['rss_link'].'" title="'.$LANG_GF01['rss_link'].'" style="vertical-align:middle;"/>';
        $topiclisting->set_var('rssfeed','<a href="'.$baseurl.$rssFeed.'">'.$imgurl.'</a>');
    } else {
        $topiclisting->set_var('rssfeed','');
    }

    $topiclisting->set_var ('cat_name', $category['cat_name']);
    $topiclisting->set_var ('cat_id',$category['id']);
    $topiclisting->set_var ('forum_name', $category['forum_name']);
    $topiclisting->set_var ('forum_id', $forum);
    $topiclisting->set_var ('LANG_TOPIC', $LANG_GF01['TOPICSUBJECT']);
    $topiclisting->set_var ('LANG_STARTEDBY', $LANG_GF01['STARTEDBY']);
    $topiclisting->set_var ('LANG_REPLIES', $LANG_GF01['REPLIES']);
    $topiclisting->set_var ('LANG_VIEWS', $LANG_GF01['VIEWS']);
    $topiclisting->set_var ('LANG_LASTPOST',$LANG_GF01['LASTPOST']);
    $topiclisting->set_var ('LANG_AUTHOR',$LANG_GF01['AUTHOR']);
    $topiclisting->set_var ('LANG_MSG05',$LANG_GF01['LASTPOST']);
    $topiclisting->set_var ('LANG_newforumposts', $LANG_GF02['msg113']);

    if ( $canPost ) {
        $newtopiclinkimg = '<img src="'.gf_getImage('post_newtopic').'" border="0" align="middle" alt="'.$LANG_GF01['NEWTOPIC'].'" title="'.$LANG_GF01['NEWTOPIC'].'"' . XHTML . '>';
        $topiclisting->set_var ('LANG_newtopic', $LANG_GF01['NEWTOPIC']);
        $topiclisting->set_var('newtopiclinkimg',$newtopiclinkimg);
        $topiclisting->set_var ('newtopiclink',"{$_CONF['site_url']}/forum/createtopic.php?method=newtopic&amp;forum=$forum");
        $topiclisting->parse ('newpost_link','new');
    } else {
        $topiclisting->set_var ('LANG_newtopic', '');
        $topiclisting->set_var ('newtopiclink','#');
    }

    $displaypostpages .= $LANG_GF01['PAGES'] .':';

    while ($record = DB_fetchArray($topicResults,false)) {

        if(($record['replies']+1) <= $CONF_FORUM['show_posts_perpage']) {
            $displaypageslink = "";
            $gotomsg = "";
        } else {
            $displaypageslink = "";
            $gotomsg = $LANG_GF02['msg85'] . "&nbsp;";
            if ($CONF_FORUM['show_posts_perpage'] > 0) {
                $pages = ceil(($record['replies']+1)/$CONF_FORUM['show_posts_perpage']);
            } else {
                 $pages = ceil(($record['replies']+1)/20);
            }
            for ($p=1; $p <= $pages; $p++) {
                $displaypageslink .= "<a href=\"{$_CONF['site_url']}/forum/viewtopic.php?forum=$forum";
                $displaypageslink .= "&amp;showtopic={$record['id']}&amp;show={$CONF_FORUM['show_posts_perpage']}&amp;page=$p\">";
                $displaypageslink .= "$p</a>";
                if ( $p > 9 ) {
                    $displaypageslink .= '...';
                    $displaypageslink .= "<a href=\"{$_CONF['site_url']}/forum/viewtopic.php?forum=$forum";
                    $displaypageslink .= "&amp;showtopic={$record['id']}&amp;show={$CONF_FORUM['show_posts_perpage']}&amp;page=$pages\">".$pages;
                    $displaypageslink .= '</a>';
                    break;
                }
                $displaypageslink .= '&nbsp';
            }
        }

        // Check if user is an anonymous poster
        if($record['uid'] > 1) {
            $showuserlink = '<span class="replypagination">';
            $showuserlink .= "<a href=\"{$_CONF['site_url']}/users.php?mode=profile&amp;uid={$record['uid']}\">{$record['name']}";
            $showuserlink .= '</a></span>';
        } else {
            $showuserlink= $record['name'];
        }

        if ($record['last_reply_rec'] > 0) {
            $lastreplysql = DB_query("SELECT topic.*,att.filename FROM {$_TABLES['gf_topic']} topic LEFT JOIN {$_TABLES['gf_attachments']} att ON topic.id=att.topic_id WHERE topic.id={$record['last_reply_rec']}");
            if ( DB_numRows($lastreplysql) > 0 ) {
                $lastreply = DB_fetchArray($lastreplysql);
                $lastreply['subject'] = COM_truncate($record['subject'],$CONF_FORUM['show_subject_length'],'...');

                if ($CONF_FORUM['use_censor']) {
                    $lastreply['subject'] = COM_checkWords($lastreply['subject']);
                }
                $lastdate1 = strftime('%m/%d/%Y', $lastreply['date']);
                if ($lastdate1 == date('m/d/Y')) {
                    $lasttime = strftime('%H:%M&nbsp;%p', $lastreply['date']);
                    $lastdate = $LANG_GF01['TODAY'] . $lasttime;
                } elseif ($CONF_FORUM['allow_user_dateformat']) {
                    $lastdate = COM_getUserDateTimeFormat($lastreply['date']);
                    $lastdate = $lastdate[0];
                } else {
                    $lastdate = strftime('%b/%d/%y %I:%M&nbsp;%p',$lastreply['date']);
                }
            }
        } else {
            $lastdate = strftime('%b/%d/%y %I:%M&nbsp;%p',$record['lastupdated']);
            $lastreply = $record;
            $record['filename'] = DB_getItem($_TABLES['gf_attachments'],'filename','topic_id='.$record['id']);
        }

        $firstdate1 = strftime('%m/%d/%Y', $record['date']);
        if ($firstdate1 == date('m/d/Y')) {
            $firsttime = strftime('%H:%M&nbsp;%p', $record['date']);
            $firstdate = $LANG_GF01['TODAY'] . $firsttime;
        } elseif ($CONF_FORUM['allow_user_dateformat']) {
            $firstdate = COM_getUserDateTimeFormat($record['date']);
            $firstdate = $firstdate[0];
        } else {
            $firstdate = strftime('%b/%d/%y %I:%M&nbsp;%p',$record['date']);
        }

        if ($_USER['uid'] > 1) {
            // Determine if there are new topics since last visit for this user.
            // If topic has been updated or is new - then the user will not have record for this parent topic in the log table
            $sql = "SELECT * FROM {$_TABLES['gf_log']} WHERE uid='{$_USER['uid']}' AND topic='{$record['id']}' AND time > 0";
            $lsql = DB_query($sql);
            if (DB_numRows($lsql) == 0) {
                if ($record['sticky'] == 1) {
                    $folderimg = '<img src="'.gf_getImage('sticky_new').'" border="0" align="middle" alt="'.$LANG_GF02['msg115'].'" title="'.$LANG_GF02['msg115'].'"' . XHTML . '>';
                } elseif ($record['locked'] == 1) {
                    $folderimg = '<img src="'.gf_getImage('locked_new').'" border="0" align="middle" alt="'.$LANG_GF02['msg116'].'" title="'.$LANG_GF02['msg116'].'"' . XHTML . '>';
                } else {
                    $folderimg = '<img src="'.gf_getImage('newposts').'" border="0" align="middle" alt="'.$LANG_GF02['msg60'].'" title="'.$LANG_GF02['msg60'].'"' . XHTML . '>';
                }
            } elseif ($record['sticky'] == 1) {
                $folderimg = '<img src="'.gf_getImage('sticky').'" border="0" align="middle" alt="'.$LANG_GF02['msg61'].'" title="'.$LANG_GF02['msg61'].'"' . XHTML . '>';
            } elseif ($record['locked'] == 1) {
                $folderimg = '<img src="'.gf_getImage('locked').'" border="0" align="middle" alt="'.$LANG_GF02['msg114'].'" title="'.$LANG_GF02['msg114'].'"' . XHTML .'>';
            } else {
                $folderimg = '<img src="'.gf_getImage('noposts').'" border="0" align="middle" alt="'.$LANG_GF02['msg59'].'" title="'.$LANG_GF02['msg59'].'"' . XHTML . '>';
            }
            // Bookmark feature  - check if the parent topic or any reply topics have been bookmarked by the user
            if ($_USER['uid'] > 1 ) {
                $bsql = "SELECT uid FROM {$_TABLES['gf_bookmarks']} WHERE uid={$_USER['uid']} AND (topic_id={$record['id']} OR pid={$record['id']})";
                if (DB_numRows(DB_query($bsql)) > 0 ) {
                    $topiclisting->set_var('bookmark_icon','<img src="'.gf_getImage('star_on_sm').'" title="'.$LANG_GF02['msg204'].'" alt=""' . XHTML . '>');
                } else {
                    $topiclisting->set_var('bookmark_icon','<img src="'.gf_getImage('star_off_sm').'" title="'.$LANG_GF02['msg203'].'" alt=""' . XHTML . '>');
                }
            }
        } elseif ($record['sticky'] == 1) {
            $folderimg = '<img src="'.gf_getImage('sticky').'" border="0" align="middle" alt="'.$LANG_GF02['msg61'].'" title="'.$LANG_GF02['msg61'].'"' . XHTML . '>';
        } elseif ($record['locked'] == 1) {
            $folderimg = '<img src="'.gf_getImage('locked').'" border="0" align="middle" alt="'.$LANG_GF02['msg114'].'" title="'.$LANG_GF02['msg114'].'"' . XHTML . '>';
        } else {
           $folderimg = '<img src="'.gf_getImage('noposts').'" border="0" align="middle" alt="'.$LANG_GF02['msg59'].'" title="'.$LANG_GF02['msg59'].'"' . XHTML . '>';
        }


        if($lastreply['uid'] > 1) {
            $lastposter = COM_getDisplayName($lastreply['uid']);
        } else {
            $lastposter = $lastreply['name'];
        }

        if($record['moved'] == 1){
            $moved = "{$LANG_GF01['MOVED']}: ";
        } else {
            $moved = "";
        }

        $subject = COM_truncate($record['subject'],$CONF_FORUM['show_subject_length'],'...');
        if ($CONF_FORUM['use_censor']) {
            $subject = COM_checkWords($subject);
            $record['subject'] = COM_checkWords($record['subject']);
        }
        if (( isset($record['filename']) && $record['filename'] != '' ) || (isset($lastreply['filename']) && $lastreply['filename'] != '' ))  {
            $subject = $subject . "&nbsp;<img src=\"{$_CONF['site_url']}/forum/images/document_sm.gif\" border=\"0\" alt=\"\"" . XHTML . ">";
        }
        if($record['uid'] > 1) {
            $firstposterName = COM_getDisplayName($record['uid']);
        } else {
            $firstposterName = $record['name'];
        }
        $topicinfo =  "{$LANG_GF01['STARTEDBY']}{$firstposterName}, {$firstdate}::";
        $topicinfo .= wordwrap(strip_tags(substr($record['comment'],0,$CONF_FORUM['contentinfo_numchars'])),$CONF_FORUM['linkinfo_width'],"<br />\n");
        $topicinfoglf = htmlspecialchars($subject).'::'.htmlspecialchars(preg_replace('#\r?\n#','<br>',substr(strip_tags($record['comment']),0,$CONF_FORUM['contentinfo_numchars']) . '...'));
        $topiclisting->set_var ('folderimg', $folderimg);
        $topiclisting->set_var ('topicinfo', $topicinfo);
        $topiclisting->set_var ('topicinfoglf', $topicinfoglf);
        $topiclisting->set_var ('topic_id', $record['id']);
        $topiclisting->set_var ('subject', $subject);
        $topiclisting->set_var('author',$record['uid'] > 1 ? '<a href="' . $_CONF['site_url'] . '/users.php?mode=profile&amp;uid=' . $record['uid'] . '">' . $record['name'] . '</a>' : $record['name']);
        $topiclisting->set_var ('fullsubject', $record['subject']);
        $topiclisting->set_var ('gotomsg', $gotomsg);
        $topiclisting->set_var ('displaypageslink', $displaypageslink);
        $topiclisting->set_var ('showuserlink', $showuserlink);
        $topiclisting->set_var ('lastposter', $lastposter);
        $topiclisting->set_var ('LANG_lastpost', $LANG_GF02['msg188']);
        $topiclisting->set_var ('moved', $moved);
        $topiclisting->set_var ('views', $record['views']);
        $topiclisting->set_var ('replies', $record['replies']);
        $topiclisting->set_var ('lastdate', $lastdate);
        $topiclisting->set_var ('lastpostid', $lastreply['id']);
        $topiclisting->set_var ('LANG_BY', $LANG_GF01['BY']);
        $topiclisting->parse ('topic_records', 'topic_record',true);
    }

    $topiclisting->set_var ('pagenavigation', COM_printPageNavigation($base_url,$page, $numpages));
    $topiclisting->parse ('outline_header', 'forum_outline_header');
    $topiclisting->parse ('outline_footer', 'forum_outline_footer');
    $topiclisting->parse ('output', 'topiclisting');
    if ( $errMsg != '' ) {
        echo $errMsg;
    } else {
        echo $topiclisting->finish ($topiclisting->get_var('output'));
    }

}

BaseFooter();
gf_siteFooter();
$display = ob_get_contents();
ob_end_clean();
echo $display;
?>