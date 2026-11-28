<?php
$page = "map";
$page_title = "World Map";
$auth_name = 'clients';
$b3_conn = true; // this page needs to connect to the B3 database
$pagination = false;
$query_normal = false;
$page_js = array('vendor/leaflet/leaflet.js', 'js/worldmap.js');
require 'inc.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

$days = (int) ($_GET['days'] ?? 7);
if (!in_array($days, array(1, 7, 30, 90, 365), true)) { $days = 7; }

$provider = geoProvider();
$data = null;
$total = 0;

if ($provider !== null && !$db->error) {
    $mtime = $provider === 'geolite2' ? (int) @filemtime(geoMmdbPath()) : (int) @filemtime(geoLegacyPath());
    $data = geoCached($provider . '|' . $mtime . '|' . $days . '|' . $game, function () use ($db, $days, $provider) {
        // One row per distinct IP (with how many clients used it) instead of loading every raw IP row
        $stmt = $db->mysql->prepare("SELECT ip, COUNT(*) AS n FROM clients WHERE time_edit > ? AND ip <> '' GROUP BY ip");
        $since = time() - $days * 86400;
        $stmt->bind_param('i', $since);
        $stmt->execute();
        $res = $stmt->get_result();
        $ips = array();
        while ($row = $res->fetch_row()) { $ips[$row[0]] = (int) $row[1]; }
        $res->free();
        $stmt->close();
        $out = geoLocate($ips, $provider);
        $out['total'] = array_sum($ips);
        return $out;
    });
    $total = (int) $data['total'];
}

// a GeoLite2 database over a month old: nudge the admin who can update it
$old_db = false;
if ($provider === 'geolite2') {
    $info = geoMmdbInfo();
    $old_db = $info && (time() - $info['built']) > 35 * 86400;
}
$can_settings = $mem->reqLevel('manage_settings');
// administrators' browsers run the automatic update in the background when it is due (see js/worldmap.js)
$auto_due = $can_settings && $provider === 'geolite2' && geoUpdateDue();

$styles = array();
foreach (geoStyleList() as $sid => $st) {
    $styles[] = array('id' => $sid, 'name' => $st['name'], 'url' => $st['url'], 'attr' => $st['attr'], 'max' => $st['max'], 'sub' => $st['sub']);
}

require 'inc/header.php';
?>
<link rel="stylesheet" href="<?php echo PATH; ?>vendor/leaflet/leaflet.css">

<div class="page-head">
    <div>
        <h1>World Map</h1>
        <p><?php if ($data) : ?><strong><?php echo number_format($total); ?></strong> player connection<?php echo $total === 1 ? '' : 's'; ?> in the last <?php echo $days === 1 ? 'day' : $days . ' days'; ?>.<?php else : ?>Where your players connect from.<?php endif; ?></p>
    </div>
    <div class="actions">
        <?php if ($provider !== null) : ?>
        <form method="get" class="form-inline">
            <label class="mr-2 mb-0 text-muted" for="wm-days">Show</label>
            <select name="days" id="wm-days" class="form-control" onchange="this.form.submit()">
                <?php foreach (array(1 => 'Last 24 hours', 7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year') as $d => $l) : ?>
                    <option value="<?php echo $d; ?>" <?php echo $d === $days ? 'selected' : ''; ?>><?php echo $l; ?></option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php endif; ?>
        <?php if ($can_settings) : ?><a class="btn btn-outline-secondary" href="settings-maps.php"><i class="fas fa-cog mr-1"></i> Map settings</a><?php endif; ?>
    </div>
</div>

<?php if ($provider === null) : ?>

    <section class="card"><div class="card-body text-center py-5">
        <i class="fas fa-globe-americas" style="font-size:44px;color:var(--accent);opacity:.8"></i>
        <h4 class="mt-3">The World Map needs a location database</h4>
        <p class="text-muted mx-auto" style="max-width:520px">To place players on the map Echelon looks up their IP addresses in MaxMind's free GeoLite2 database.
            <?php if ($can_settings) : ?>Setting it up takes a few minutes: you make a free MaxMind account, and Echelon downloads the database for you.<?php else : ?>Ask an administrator to set it up under <em>Settings &rsaquo; Maps &amp; World Map</em>.<?php endif; ?></p>
        <?php if ($can_settings) : ?><a class="btn btn-primary" href="settings-maps.php">Set up the World Map</a><?php endif; ?>
    </div></section>

<?php else : ?>

    <div class="mc-notice" id="wm-update" hidden><i class="fas fa-sync-alt"></i><span id="wm-update-text"></span></div>
    <?php if ($old_db && $can_settings && !geoAutoEnabled()) : ?>
        <div class="mc-notice"><i class="fas fa-info-circle"></i><span>The GeoLite2 database is over a month old. <a href="settings-maps.php">Update it</a> (or switch on automatic updates there) to keep locations accurate.</span></div>
    <?php endif; ?>

    <div class="wm-layout">
        <section class="card wm-map-card">
            <div id="world-map" role="img" aria-label="Map of where players connect from"></div>
            <div class="wm-attrib text-muted"><?php echo $provider === 'geolite2' ? 'This product includes GeoLite2 data created by <a href="https://www.maxmind.com" target="_blank" rel="noopener">MaxMind</a>.' : 'Country level locations.'; ?></div>
        </section>

        <section class="card">
            <div class="card-header"><h5>Countries</h5></div>
            <div class="wm-countries">
                <?php
                $max = 0;
                foreach ($data['countries'] as $c) { $max = max($max, $c['n']); }
                foreach ($data['countries'] as $cc => $c) : ?>
                    <div class="wm-row">
                        <span class="nm"><?php echo $cc !== '??' ? '<span class="cc">' . $e($cc) . '</span>' : ''; ?><?php echo $e($c['name'] !== '' ? $c['name'] : 'Unknown'); ?></span>
                        <span class="ct"><?php echo number_format($c['n']); ?></span>
                        <span class="bar" style="width:<?php echo $max ? max(2, round($c['n'] / $max * 100)) : 0; ?>%"></span>
                    </div>
                <?php endforeach; ?>
                <?php if ($data['unknown'] > 0) : ?>
                    <div class="wm-row muted"><span class="nm">Not found (private or unknown addresses)</span><span class="ct"><?php echo number_format($data['unknown']); ?></span></div>
                <?php endif; ?>
                <?php if (!$data['countries'] && !$data['unknown']) : ?><div class="card-body text-muted">No players connected in this period.</div><?php endif; ?>
            </div>
        </section>
    </div>

    <script type="application/json" id="wm-data"><?php echo json_encode(array('points' => $data['points'], 'styles' => $styles, 'style' => geoDefaultStyle(), 'switcher' => geoSwitcherOn(), 'autoUpdate' => $auto_due), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>

<?php endif; ?>

<?php require 'inc/footer.php'; ?>
