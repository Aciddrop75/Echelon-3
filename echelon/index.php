<?php
$page = "home";
$page_title = "Dashboard";
$auth_name = 'login';
$auth_user_here = true;
$b3_conn = true; // the dashboard shows a few live numbers from the B3 database
$pagination = false;
$query_normal = false;
require 'inc.php';

## Site admins are told when a newer Echelon exists (the check is cached and times out quickly) ##
## (only when ECH_UPDATE_URL is set in inc/config.php - see config_sample.php)
if ($mem->reqLevel('see_update_msg') && defined('ECH_UPDATE_URL') && ECH_UPDATE_URL !== '') {
    $latest = getEchVer();
    if ($latest !== false && version_compare(ltrim($latest, 'vV'), ltrim(ECH_VERSION, 'vV'), '>')) {
        set_warning('A newer version of Echelon is available (' . htmlspecialchars($latest, ENT_QUOTES) . '). You are running ' . htmlspecialchars(ECH_VERSION, ENT_QUOTES) . '.');
    }
}

## A handful of cheap numbers (each is an index/primary-key friendly COUNT) ##
$stats = array('clients' => null, 'new' => null, 'bans' => null, 'maps' => null);
$recent = array();
if (!$db->error && empty($no_games)) {
    $one = function ($sql) use ($db) {
        try {
            $r = $db->mysql->query($sql);
            $v = (int) $r->fetch_row()[0];
            $r->free();
            return $v;
        } catch (Throwable $e) {
            return null; // table missing in this B3 variant
        }
    };
    $now = time();
    $stats['clients'] = $one("SELECT COUNT(*) FROM clients");
    $stats['new']     = $one("SELECT COUNT(*) FROM clients WHERE time_add > " . ($now - 7 * 86400));
    $stats['bans']    = $one("SELECT COUNT(*) FROM penalties WHERE inactive = 0 AND type IN ('Ban','TempBan') AND (time_expire = -1 OR time_expire > $now)");
    $stats['maps']    = $one("SELECT COUNT(*) FROM mapconfig");
    try {
        $r = $db->mysql->query("SELECT id, name, time_edit FROM clients ORDER BY time_edit DESC LIMIT 8");
        $recent = $r->fetch_all(MYSQLI_ASSOC);
        $r->free();
    } catch (Throwable $e) {
        $recent = array();
    }
}

require 'inc/header.php';

$h = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };
$first_visit = (($_SESSION['last_seen'] ?? 1) == 0 && ($_SESSION['username'] ?? '') === 'admin');
?>

<div class="page-head">
    <div>
        <h1>Welcome, <?php echo $h($mem->displayName()); ?></h1>
        <p>
            <?php if (empty($no_games)) : ?>You are viewing the &ldquo;<?php echo $h($game_name); ?>&rdquo; database.<?php endif; ?>
            <?php if (($_SESSION['last_seen'] ?? 0) != 0) : ?> Your last visit was <?php $mem->lastSeen('l, jS F Y (H:i)'); ?>.<?php endif; ?>
        </p>
    </div>
    <?php if (empty($no_games)) : ?>
    <div class="actions"><a class="btn btn-primary" href="clients.php?ob=time_edit&amp;o=DESC"><i class="fas fa-users mr-1"></i> Browse clients</a></div>
    <?php endif; ?>
</div>

<?php if ($first_visit) : ?>
    <div class="alert alert-success">Welcome to Echelon for the first time. Open <a href="sa.php">Site Admin</a> and the Settings pages to set up your game and server information.</div>
<?php endif; ?>

<?php if (!empty($no_games)) : ?>
    <div class="alert alert-info">No game has been added yet. <a href="settings-games.php?t=add">Add your first game</a> to get started.</div>
<?php else : ?>

<div class="stat-grid">
    <?php
    $cards = array(
        array('users', 'Players on record', $stats['clients'], 'clients.php'),
        array('user-plus', 'New in the last 7 days', $stats['new'], 'clients.php?ob=time_add&o=DESC'),
        array('ban', 'Active bans', $stats['bans'], 'bans.php?t=a'),
        array('map', 'Maps configured', $stats['maps'], 'mapconfig.php'),
    );
    foreach ($cards as $c) :
        if ($c[2] === null) { continue; } ?>
        <a class="stat" href="<?php echo $h($c[3]); ?>">
            <span class="ico"><i class="fas fa-<?php echo $c[0]; ?>"></i></span>
            <span><span class="num"><?php echo number_format($c[2]); ?></span><span class="lbl d-block"><?php echo $h($c[1]); ?></span></span>
        </a>
    <?php endforeach; ?>
</div>

<div class="dash-cols">
    <section class="card">
        <div class="card-header"><h5>Recently seen players</h5></div>
        <?php if ($recent) : ?>
            <div class="table-responsive"><table class="table mb-0">
                <thead><tr><th>Player</th><th class="text-right">Last seen</th></tr></thead>
                <tbody>
                <?php foreach ($recent as $c) : ?>
                    <tr>
                        <td><a href="clientdetails.php?id=<?php echo (int) $c['id']; ?>"><?php echo $h($c['name']); ?></a> <small class="text-muted">@<?php echo (int) $c['id']; ?></small></td>
                        <td class="text-right text-muted"><?php echo $h(date('M j, H:i', (int) $c['time_edit'])); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php else : ?>
            <div class="card-body text-muted">No players have been recorded yet.</div>
        <?php endif; ?>
    </section>

    <section class="card quick-links">
        <div class="card-header"><h5>Quick links</h5></div>
        <?php if ($mem->reqLevel('clients')) : ?><a href="clients.php"><i class="fas fa-users"></i> Clients</a><?php endif; ?>
        <?php if ($mem->reqLevel('penalties')) : ?><a href="bans.php?t=a"><i class="fas fa-user-slash"></i> Admin bans</a><?php endif; ?>
        <?php if ($mem->reqLevel('mapconfig')) : ?><a href="mapconfig.php"><i class="fas fa-map"></i> Map config</a><?php endif; ?>
        <?php if ($mem->reqLevel('reports')) : ?><a href="reports.php"><i class="fas fa-chart-bar"></i> Reports</a><?php endif; ?>
        <a href="me.php"><i class="fas fa-user-cog"></i> My account</a>
    </section>
</div>

<?php endif; ?>

<?php if (!empty($config['cosmos']['newsfeed'])) : ?>
<section class="card">
    <div class="card-header"><h5>Announcements</h5></div>
    <div class="card-body news-body"><?php echo $h($config['cosmos']['newsfeed']); ?></div>
</section>
<?php endif; ?>

<?php require 'inc/footer.php'; ?>
