<?php
$page = "maps";
$page_title = "Map Config";
$auth_name = 'mapconfig';
$b3_conn = true; // this page needs to connect to the B3 database
$pagination = false;
$query_normal = false; // the board loads its own data (one query, filtered/sorted in the browser)
$page_js = array('js/mapconfig.js');
require 'inc.php';

## mapcycle settings of the current game's first server ##
if (!isset($servers) || sizeof($servers) < 1) {
    $servers = $dbl->getServers($game);
}
$mapcycleURL  = !empty($servers) ? (string) $servers[0]['mapcycleurl'] : '';
$mapcycleFile = !empty($servers) ? (string) $servers[0]['mapcyclefile'] : '';

$pk3_dirs = mapPk3Dirs($mapcycleFile);
$catalog = $pk3_dirs ? mapPk3Catalog($pk3_dirs) : array(); // every map in the game files, with the game types their .arena files list
$maps = array();
if (!$db->error) {
    foreach ($db->getMapconfigs() as $m) {
        $maps[] = array(
            'id'    => (int) $m['id'],
            'name'  => (string) $m['mapname'],
            'modes' => mapParseModes($m['gamemodes']),
            'cl'    => (int) $m['capturelimit'],
            'tl'    => (int) $m['timelimit'],
            'sd'    => (int) $m['g_suddendeath'],
            'ge'    => (string) $m['g_gear'],
            'gr'    => (int) $m['g_gravity'],
            'ff'    => (int) $m['g_friendlyfire'],
            'msg'   => (string) $m['startmessage'],
            'sr'    => (int) $m['skiprandom'],
            'last'  => (string) $m['datelastadd'],
            'bot'   => (int) $m['bot'],
            'img'   => mapImageUrl($m['mapname']),
            'hue'   => mapHue($m['mapname']),
            'am'    => $catalog[strtolower($m['mapname'])]['modes'] ?? array(), // game types from the game files
        );
    }
}

$missing_pics = 0;
foreach ($maps as $m) { if (!$m['img']) { $missing_pics++; } }

// maps that are in the game files but not in Map Config yet: listed too, so nothing is hidden
$in_config = count($maps);
$known = array();
foreach ($maps as $m) { $known[strtolower($m['name'])] = true; }
$fill_n = 0;
foreach ($maps as $m) { if (!$m['modes'] && $m['am']) { $fill_n++; } }
$found_new = 0; $found_unknown = 0; $fid = 0;
foreach ($catalog as $k => $c) {
    if (isset($known[$k])) { continue; }
    $fid--;
    $found_new++;
    if (!$c['modes']) { $found_unknown++; }
    $maps[] = array(
        'id' => $fid, 'name' => $c['name'], 'modes' => $c['modes'], 'cl' => 8, 'tl' => 20, 'sd' => 0, 'ge' => '0', 'gr' => 800, 'ff' => 0,
        'msg' => $c['long'], 'sr' => 0, 'last' => '2000-01-01', 'bot' => 0, 'img' => mapImageUrl($c['name']), 'hue' => mapHue($c['name']),
        'am' => $c['modes'], 'found' => true, 'src' => $c['src'],
    );
}

$pk3_ok = (bool) $pk3_dirs;
$suggest_dirs = $pk3_ok ? array() : mapPk3Suggestions($mapcycleFile);

// mapcycle files that are set up (game type links in mapcycle/, or the file from Server Settings)
$cycle_targets = array();
foreach (mapcycleOverview($mapcycleFile) as $t) {
    if ($t['path'] !== '') {
        $cycle_targets[] = array('slot' => $t['slot'], 'label' => $t['label'], 'ok' => $t['exists'], 'writable' => $t['writable'], 'via' => $t['via'], 'target' => $t['target']);
    }
}
$can_setup = $mem->reqLevel('manage_settings');

$custom_colors = array();
foreach (array_keys(mapcycleCustomTypes()) as $c) { $custom_colors[$c] = mapModeColor($c); }

// JSON for the board. The HEX flags make it safe inside a <script> block.
$board = array(
    'maps'      => $maps,
    'modes'     => mapGameModes(),
    'colors'    => $custom_colors ?: new stdClass(),
    'cycleUrl'  => $mapcycleURL,
    'targets'   => $cycle_targets,
    'canWrite'  => !empty($cycle_targets),
    'endpoints' => array('stamp' => 'actions/b3/mapconfig-datelastadd.php', 'cycle' => 'actions/mapcycle.php', 'extract' => 'actions/b3/mapconfig-extract.php', 'download' => 'actions/b3/mapconfig-download.php'),
    'dl'        => array('on' => $pk3_ok, 'sources' => (bool) mapDlSources(), 'settings' => $can_setup),
    'found'     => array('on' => $pk3_ok, 'inConfig' => $in_config, 'new' => $found_new, 'unknown' => $found_unknown, 'fill' => $fill_n, 'files' => count($catalog)),
    'importUrl' => 'actions/b3/mapconfig-import.php',
);

require 'inc/header.php';

if (!$db->error) :
?>

<div class="page-head">
    <div>
        <h1>Map Config</h1>
        <p>Click a map to edit its settings. Use <i class="fas fa-plus"></i> on a tile to add it to the mapcycle below.</p>
    </div>
    <div class="actions">
        <a class="btn btn-primary" href="mapconfigdetails.php?new=1"><i class="fas fa-plus mr-1"></i> Add map</a>
    </div>
</div>

<?php if ($missing_pics > 0) : ?>
<div class="mc-notice" id="mc-pics">
    <i class="far fa-image"></i>
    <span id="mc-pics-text"><strong><?php echo $missing_pics; ?></strong> of <?php echo $in_config; ?> maps have no picture.
        <?php echo $pk3_ok ? 'Echelon can take them from your map files.' : 'Tell Echelon where your game files are and it will take the pictures from the maps\' own <code>.pk3</code> files.'; ?></span>
    <span class="grow"></span>
    <?php if ($pk3_ok) : ?><button type="button" class="btn btn-primary btn-sm" id="mc-pics-scan"><i class="fas fa-magic mr-1"></i> Extract from map files</button><?php endif; ?>
    <label class="btn btn-outline-secondary btn-sm mb-0" for="mc-pics-file"><i class="fas fa-upload mr-1"></i> From a .pk3 file&hellip;</label>
    <input type="file" id="mc-pics-file" accept=".pk3,.zip" hidden>
    <?php if (!$pk3_ok) : ?>
    <div class="mc-setdir" style="flex-basis:100%">
        <div class="input-group input-group-sm">
            <input type="text" class="form-control" id="mc-dir" spellcheck="false" placeholder="Game files folder, e.g. /home/urt/.q3a/q3ut4" aria-label="Game files folder">
            <div class="input-group-append"><button type="button" class="btn btn-primary" id="mc-dir-save"><i class="fas fa-magic mr-1"></i> Save folder and get pictures</button></div>
        </div>
        <?php if ($suggest_dirs) : ?><div class="mt-1" style="font-size:12.5px">Found here: <?php foreach ($suggest_dirs as $sd) : ?><a href="#" class="mc-dir-pick mr-2" data-path="<?php echo htmlspecialchars($sd, ENT_QUOTES); ?>"><?php echo htmlspecialchars($sd, ENT_QUOTES); ?></a><?php endforeach; ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="mc-notice" id="mc-found" hidden>
    <i class="fas fa-folder-open"></i>
    <span id="mc-found-text" style="flex:1 1 340px"></span>
    <button type="button" class="btn btn-primary btn-sm" id="mc-found-add" hidden><i class="fas fa-plus mr-1"></i> Add them to Map Config</button>
    <button type="button" class="btn btn-outline-secondary btn-sm" id="mc-found-fill" hidden><i class="fas fa-magic mr-1"></i> Fill in game types</button>
</div>

<div class="mc-notice" id="mc-dl" hidden>
    <i class="fas fa-cloud-download-alt"></i>
    <span id="mc-dl-text" style="flex:1 1 340px"></span>
    <button type="button" class="btn btn-primary btn-sm" id="mc-dl-go" hidden><i class="fas fa-download mr-1"></i> Download missing maps</button>
</div>

<div class="mc-toolbar" id="mc-toolbar">
    <div class="mc-toolbar-row">
        <div class="mc-search">
            <i class="fas fa-search"></i>
            <input type="search" class="form-control" id="mc-q" placeholder="Search maps or start messages" autocomplete="off" aria-label="Search maps">
        </div>
        <label class="inline">Sort by
            <select id="mc-sort" class="form-control">
                <option value="name">Name (A to Z)</option>
                <option value="name-desc">Name (Z to A)</option>
                <option value="mode">Game type (grouped)</option>
                <option value="used-old">Longest since in mapcycle</option>
                <option value="used-new">Most recently in mapcycle</option>
                <option value="id-new">Newest added</option>
            </select>
        </label>
        <?php if ($found_new) : ?>
        <label class="inline">Show
            <select id="mc-src" class="form-control">
                <option value="all">All maps</option>
                <option value="config">In Map Config</option>
                <option value="found">Only found in game files</option>
            </select>
        </label>
        <?php endif; ?>
        <span class="mc-count" id="mc-count" aria-live="polite"></span>
    </div>
    <div class="mc-chips" id="mc-chips" role="group" aria-label="Filter by game type">
        <span class="mc-chips-label">Game type</span>
        <!-- chips are built by js/mapconfig.js -->
    </div>
</div>

<div id="mc-board"></div>

<div class="mc-empty" id="mc-empty" hidden>
    <i class="fas fa-map"></i>
    <div id="mc-empty-text">No maps match your filters.</div>
</div>

<!-- Mapcycle builder -->
<div class="mc-drawer" id="mc-drawer">
    <div class="mc-drawer-bar">
        <button type="button" class="ttl" id="mc-drawer-toggle" aria-expanded="false" aria-controls="mc-drawer-panel">
            <i class="fas fa-list-ol"></i> Mapcycle <span class="count" id="mc-queued">0</span> <i class="fas fa-chevron-up chev"></i>
        </button>
        <span class="grow"></span>
        <?php if (!empty($cycle_targets)) : ?>
        <label class="chk" title="Which mapcycle file Load current and Write file use">File
            <select id="mc-target" aria-label="Mapcycle file">
                <?php foreach ($cycle_targets as $ct) : ?><option value="<?php echo htmlspecialchars($ct['slot'], ENT_QUOTES); ?>"><?php echo htmlspecialchars($ct['label'], ENT_QUOTES); ?></option><?php endforeach; ?>
            </select>
        </label>
        <?php elseif ($can_setup) : ?>
        <a class="btn" href="<?php echo PATH; ?>settings-maps.php#mapcycle-files"><i class="fas fa-link mr-1"></i> Choose mapcycle file&hellip;</a>
        <?php endif; ?>
        <label class="chk" title="Write each map followed by its capture limit, gravity, gear and so on"><input type="checkbox" id="mc-withconfig"> Include config settings</label>
        <button type="button" class="btn" id="mc-load" title="Replace the text below with the current mapcycle file"><i class="fas fa-download mr-1"></i> Load current</button>
        <button type="button" class="btn" id="mc-clear"><i class="fas fa-eraser mr-1"></i> Clear</button>
        <button type="button" class="btn primary" id="mc-write" <?php echo empty($cycle_targets) ? 'disabled title="Choose a mapcycle file first"' : ''; ?>><i class="fas fa-save mr-1"></i> Write file</button>
        <?php if ($mapcycleURL !== '') : ?>
            <a class="cur" href="<?php echo htmlspecialchars($mapcycleURL, ENT_QUOTES); ?>" target="_blank" rel="noopener">View current</a>
        <?php endif; ?>
    </div>
    <div class="mc-drawer-panel" id="mc-drawer-panel">
        <textarea id="mc-cycle" spellcheck="false" aria-label="Mapcycle text" placeholder="Maps you add appear here, one per line."></textarea>
        <div class="hint" id="mc-cycle-hint">Nothing is saved to the server until you press &ldquo;Write file&rdquo;.<?php if ($can_setup && !empty($cycle_targets)) : ?> <a href="<?php echo PATH; ?>settings-maps.php#mapcycle-files">Change the mapcycle files</a><?php endif; ?></div>
    </div>
</div>

<script type="application/json" id="mc-data"><?php echo json_encode($board, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>

<?php
endif; // db error

require 'inc/footer.php';
