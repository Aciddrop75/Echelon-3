<?php
## if the page has the normal query process & there is a connectionn to the B3 DB
if(!isset($query_normal)) {
    $query_normal = false;
}
if (!isset($db_error)) {
    $db_error = false;
}
if($b3_conn && $query_normal && (!$db_error)) :
    $results = $db->query($query_limit, true, 'select', $query_params ?? array());

    $num_rows = $results['num_rows']; // the the num_rows
    $data_set = $results['data']; // seperate out the return data set
endif;

## Pagination for pages with tables ## 
if($b3_conn && $pagination == true && (!$db_error)) : // if pagination is needed on the page
    ## Find total rows ##
    $total_num_rows = $db->query($query, false, 'select', $query_params ?? array()); // count only - nothing is fetched
    $total_rows = $total_num_rows['num_rows'];

    $query_string_page = queryStringPage();

    // create query_string
    if($total_rows > 0) {

        $total_pages = totalPages($total_rows, $limit_rows);

        if($page_no > $total_pages) {
            $db->error = true;
            $db->error_msg = 'That page does not exists, please select a real page.';
        }
    } else {
        $total_pages = 0;
    }
else :
    $total_rows = 0;
    $total_pages = 1;
endif;

if (!isset($_SESSION['timezone'])) {
    $_SESSION['timezone'] = 'Europe/London';
}
date_default_timezone_set($_SESSION['timezone']);
?>
<?php
$logged_in = $mem->loggedIn();
$this_cur_page = basename($_SERVER['SCRIPT_NAME'] ?? '');
$detail_to_index = array('clientdetails.php' => 'clients.php', 'mapconfigdetails.php' => 'mapconfig.php', 'editban.php' => 'bans.php?t=a');
$games_list = ($logged_in && empty($no_games)) ? $dbl->getGamesList() : array();
$display_name = '';
if ($logged_in) { ob_start(); $mem->displayName(); $display_name = trim(strip_tags(ob_get_clean())); }
$page_title_h = htmlspecialchars((string) ($page_title ?? ''), ENT_QUOTES);
$asset_v = rawurlencode(ECH_VERSION);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <script src="<?php echo PATH; ?>js/theme.js?v=<?php echo $asset_v; ?>"></script>
    <title><?php echo htmlspecialchars((string) ($site_name ?? ''), ENT_QUOTES); ?> Echelon - <?php echo $page_title_h; ?></title>

    <!-- all assets are self-hosted (vendor/): no third-party requests, works on closed networks -->
    <link rel="stylesheet" href="<?php echo PATH; ?>vendor/bootstrap/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo PATH; ?>vendor/fontawesome/fontawesome.min.css">
    <link rel="stylesheet" href="<?php echo PATH; ?>css/echelon.css?v=<?php echo $asset_v; ?>">
    <?php if(isCD()) : ?><link rel="stylesheet" href="<?php echo PATH; ?>css/cd.css?v=<?php echo $asset_v; ?>"><?php endif; ?>

    <link rel="icon" href="<?php echo PATH; ?>favicon.ico">
    <?php
    // return any plugin CSS files
    if(!$no_plugins_active) {
        $plugins->getCSS();
    }
    ?>

    <script src="<?php echo PATH; ?>vendor/jquery/jquery.min.js"></script>
    <script src="<?php echo PATH; ?>vendor/bootstrap/bootstrap.bundle.min.js"></script>
</head>

<body id="<?php echo htmlspecialchars((string) $page, ENT_QUOTES); ?>" class="<?php echo $logged_in ? 'is-app' : 'public'; ?><?php echo !empty($body_class) ? ' '.htmlspecialchars($body_class, ENT_QUOTES) : ''; ?>">
<div class="app">

<?php if($logged_in) : ?>
<aside class="sidebar" id="sidebar" aria-label="Main navigation">
    <a class="sidebar-brand" href="<?php echo PATH; ?>" title="Dashboard">
        <img src="<?php echo PATH; ?>images/logo.png" alt="" width="34" height="34">
        <span><strong>Echelon</strong><small>B3 administration</small></span>
    </a>

    <?php if(!empty($games_list)) : ?>
    <div class="game-switch dropdown">
        <button class="btn" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
            <i class="fas fa-gamepad"></i>
            <span class="label"><small>Game</small><?php echo htmlspecialchars((string) ($game_name ?? ''), ENT_QUOTES); ?></span>
            <i class="fas fa-chevron-down" style="font-size:10px;opacity:.6"></i>
        </button>
        <div class="dropdown-menu">
            <?php
            $switch_target = $detail_to_index[$this_cur_page] ?? $this_cur_page;
            $switch_sep = (strpos($switch_target, '?') === false) ? '?' : '&';
            foreach($games_list as $g) :
                $cur = ((int) $g['id'] === (int) $game);
            ?>
                <a class="dropdown-item<?php echo $cur ? ' active' : ''; ?>" style="<?php echo $cur ? 'background:var(--accent-soft);color:var(--accent)' : ''; ?>"
                   href="<?php echo htmlspecialchars(PATH.$switch_target.$switch_sep.'game='.(int) $g['id'], ENT_QUOTES); ?>" title="Switch to this game"><?php echo htmlspecialchars((string) $g['name'], ENT_QUOTES); ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <nav class="nav-group" aria-label="Sections">
        <?php navLink('index.php', 'Dashboard', 'th-large', array(), 'Home'); ?>

        <?php if(empty($no_games)) : ?>

            <?php if($mem->reqLevel('clients')) : ?>
            <p class="nav-group-title">Players</p>
            <?php
                navLink('clients.php', 'Clients', 'users', array('clientdetails.php'), 'Clients listing');
                navLink('regular.php', 'Regular Visitors', 'user-friends', array(), 'Regular non admin visitors to your servers');
                navLink('admins.php', 'Admin Listing', 'user-shield', array(), 'A list of all admins');
                navLink('active.php', 'In-active Admins', 'user-clock', array(), 'In-active admins');
                if(is_file(ECH_ROOT.'lib/GeoIP.dat')) { navLink('map.php', 'World Player Map', 'globe-africa', array(), 'Player map'); }
            endif; ?>

            <?php if($mem->reqLevel('penalties')) : ?>
            <p class="nav-group-title">Penalties</p>
            <?php
                navLink('adminkicks.php', 'Admin Kicks', 'user-times');
                navLink('bans.php?t=a', 'Admin Bans', 'user-slash', array('editban.php'));
                navLink('bans.php?t=b', 'B3 Bans & Kicks', 'robot', array(), 'All kicks/bans added automatically by B3');
                navLink('toppen.php', 'Toplist Penalties', 'sort-amount-up');
                navLink('pubbans.php', 'Public Ban List', 'book-open', array(), 'A public list of bans in the database');
                navLink('notices.php', 'Notices', 'flag', array(), 'In-game notices');
            endif; ?>

            <?php if($mem->reqLevel('mapconfig') || $mem->reqLevel('listmgmt') || $mem->reqLevel('reports') || $mem->reqLevel('server_action')) : ?>
            <p class="nav-group-title">Manage</p>
            <?php
                if($mem->reqLevel('mapconfig')) { navLink('mapconfig.php', 'Map Config', 'map', array('mapconfigdetails.php'), 'Map settings and mapcycle'); }
                if($mem->reqLevel('listmgmt')) { navLink('listmgmt.php', 'List Management', 'align-justify'); }
                if($mem->reqLevel('reports')) { navLink('reports.php', 'Reports', 'chart-bar'); }
                if($mem->reqLevel('server_action')) { navLink('server_action.php', 'Server Action', 'terminal'); }
            endif; ?>

            <?php if(!$no_plugins_active AND $plugins->NavExists()) : ?>
            <p class="nav-group-title">Plugins</p>
            <?php $plugins->displayNav(); ?>
            <?php endif; ?>

        <?php endif; // no games ?>

        <p class="nav-group-title">Settings</p>
        <?php
            if($mem->reqLevel('siteadmin')) {
                navLink('sa.php', 'Site Admin', 'cogs', array(), 'Site administration');
                navLink('settings.php', 'Site Settings', 'sliders-h');
                navLink('sa.php?t=perms', 'Permissions', 'users-cog', array(), 'User permissions management');
            }
            if($mem->reqLevel('manage_settings')) {
                navLink('settings-games.php', 'Game Settings', 'gamepad');
                navLink('settings-server.php', 'Server Settings', 'server');
                navLink('settings-maps.php', 'Maps & World Map', 'globe-americas', array(), 'Game files folder and player location data');
            }
            navLink('me.php', 'My Account', 'user-circle', array(), 'Edit your account');
        ?>
    </nav>

    <div class="sidebar-foot">Echelon <?php echo htmlspecialchars((string) ECH_VERSION, ENT_QUOTES); ?></div>
</aside>
<?php endif; ?>

<div class="main">
    <header class="topbar">
        <?php if($logged_in) : ?>
            <button class="menu-toggle" type="button" id="menu-toggle" aria-label="Toggle navigation" aria-controls="sidebar"><i class="fas fa-bars"></i></button>
            <div class="crumbs"><span>Echelon</span><span class="sep">/</span><strong><?php echo $page_title_h; ?></strong></div>
            <div class="topbar-spacer"></div>

            <?php if(empty($no_games) && $mem->reqLevel('clients')) :
                if (!isset($search_type)) { $search_type = 'all'; }
                $st = array('all' => 'All Records', 'alias' => 'Name', 'pbid' => 'PBID', 'ip' => 'IP Address', 'id' => 'Player ID', 'ipaliastable' => 'IP-Alias', 'aliastable' => 'Alias');
            ?>
            <div class="top-search">
                <form action="<?php echo PATH; ?>clients.php" method="get" id="c-search" role="search">
                    <div class="input-group input-group-sm">
                        <select class="custom-select custom-select-sm" name="t" aria-label="Search in">
                            <?php foreach($st as $val => $lbl) : ?>
                                <option value="<?php echo $val; ?>"<?php echo ($search_type === $val) ? ' selected' : ''; ?>><?php echo $lbl; ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input class="form-control form-control-sm" type="text" autocomplete="off" name="s" id="search" placeholder="Search clients…" aria-label="Search clients">
                        <div class="input-group-append"><button class="btn btn-outline-secondary btn-sm" id="sub-search" type="submit" aria-label="Search"><i class="fa fa-search"></i></button></div>
                    </div>
                    <div class="suggestionsBox" id="suggestions" style="display:none"><div class="suggestionList" id="suggestionsList"></div></div>
                </form>
            </div>
            <?php endif; ?>

            <div class="theme-switch" role="group" aria-label="Colour theme">
                <button type="button" data-theme-set="light" aria-pressed="false" title="Light" aria-label="Light theme"><i class="fas fa-sun"></i></button>
                <button type="button" data-theme-set="dark" aria-pressed="false" title="Dark" aria-label="Dark theme"><i class="fas fa-moon"></i></button>
                <button type="button" data-theme-set="system" aria-pressed="false" title="System (follow device)" aria-label="System theme"><i class="fas fa-desktop"></i></button>
            </div>
            <div class="user-menu dropdown">
                <button class="btn" type="button" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                    <span class="avatar"><?php echo htmlspecialchars(mb_substr($display_name !== '' ? $display_name : '?', 0, 1), ENT_QUOTES); ?></span>
                    <span class="name"><?php echo htmlspecialchars($display_name, ENT_QUOTES); ?></span>
                    <i class="fas fa-chevron-down" style="font-size:10px;opacity:.5"></i>
                </button>
                <div class="dropdown-menu dropdown-menu-right">
                    <a class="dropdown-item" href="<?php echo PATH; ?>me.php"><i class="fas fa-user-circle mr-2"></i>My Account</a>
                    <a class="dropdown-item" href="<?php echo PATH; ?>actions/logout.php" title="Sign out"><i class="fas fa-sign-out-alt mr-2"></i>Sign Out</a>
                </div>
            </div>
        <?php else : ?>
            <a class="brand" href="<?php echo PATH; ?>"><img src="<?php echo PATH; ?>images/logo.png" alt="" width="30" height="30"><span>Echelon</span></a>
            <div class="topbar-spacer"></div>
            <div class="theme-switch" role="group" aria-label="Colour theme">
                <button type="button" data-theme-set="light" aria-pressed="false" title="Light" aria-label="Light theme"><i class="fas fa-sun"></i></button>
                <button type="button" data-theme-set="dark" aria-pressed="false" title="Dark" aria-label="Dark theme"><i class="fas fa-moon"></i></button>
                <button type="button" data-theme-set="system" aria-pressed="false" title="System (follow device)" aria-label="System theme"><i class="fas fa-desktop"></i></button>
            </div>
            <a class="btn btn-light btn-sm mr-2" href="<?php echo PATH; ?>pubbans.php"><i class="fas fa-book-open mr-1"></i> Public Ban List</a>
            <a class="btn btn-primary btn-sm" href="<?php echo PATH; ?>login.php"><i class="fas fa-sign-in-alt mr-1"></i> Login</a>
        <?php endif; ?>
    </header>

    <main class="content" id="content">
    <?php
    if(!isHome()) {
        errors(); // echo out all errors/success/warnings
    }

    if($b3_conn && $query_normal) { // if this is a normal query page and there is a db error show message
        if($db->error) {
            dbErrorShow($db->error_msg); // show db error
        }
    }
    ?>
