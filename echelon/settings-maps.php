<?php
$page = "settings-maps";
$page_title = "Maps & World Map";
$auth_name = 'manage_settings';
$b3_conn = false;
$pagination = false;
$query_normal = false;
$page_js = array('js/settings-maps.js');
require 'inc.php';

$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

$servers = $dbl->getServers($game);
$mapcycleFile = !empty($servers) ? trim((string) $servers[0]['mapcyclefile']) : '';

$saved_dirs = (string) geoCfg('map_pk3_dir', '');
$dirs_now = mapPk3Dirs($mapcycleFile);
$suggest = mapPk3Suggestions($mapcycleFile);
$pk3_total = 0;
foreach ($dirs_now as $d) { $pk3_total += count((array) glob($d . '/*.[pP][kK]3')); }

$provider_pref = geoCfg('geo_provider', '');
$mm = geoMmdbInfo();
$legacy_present = is_file(geoLegacyPath());
if ($provider_pref === '') { $provider_pref = $mm ? 'geolite2' : ($legacy_present ? 'legacy' : 'geolite2'); }
$account = (string) geoCfg('maxmind_account', '');
$key_saved = (string) geoCfg('maxmind_key', '') !== '';
$ext_curl = function_exists('curl_init');
$ext_zip = class_exists('ZipArchive');
$ext_gd = extension_loaded('gd');
$lib_writable = is_writable(ECH_ROOT . 'lib');
$auto_on = geoCfg('geo_auto', '0') === '1';
$last_upd = geoLastUpdated();
$last_err = (string) geoCfg('geo_last_error', '');
$cron_cmd = 'php ' . rtrim((string) (realpath(ECH_ROOT) ?: ECH_ROOT), '/\\') . DIRECTORY_SEPARATOR . 'cron' . DIRECTORY_SEPARATOR . 'update-geolite2.php';

$dl_sources = (string) geoCfg('map_dl_sources', '');
$dl_lan = geoCfg('map_dl_lan', '0') === '1';
$dl_max = (int) geoCfg('map_dl_max_mb', '200');
$dl_writable = $dirs_now && mapDlTargetDir($dirs_now) !== '';

$mcf_slots = mapcycleOverview($mapcycleFile);
$mcf_dir_ok = is_dir(mapcycleDir()) ? is_writable(mapcycleDir()) : is_writable(rtrim(ECH_ROOT, '/\\'));
$can_link = function_exists('symlink');

$styles = geoStyleList();
$style_now = geoDefaultStyle();
$custom_url = (string) geoCfg('map_custom_url', '');
$custom_attr = (string) geoCfg('map_custom_attr', '');
$switcher = geoSwitcherOn();
$thumb = function ($st) {
    $sub = $st['sub'] !== '' ? substr($st['sub'], 0, 1) : '';
    return str_replace(array('{s}', '{r}', '{z}', '{x}', '{y}'), array($sub, '', '2', '2', '1'), $st['url']);
};

require 'inc/header.php';
?>

<div class="page-head">
    <div>
        <h1>Maps &amp; World Map</h1>
        <p>Where your map files are, and where the World Map gets its locations from.</p>
    </div>
</div>

<section class="card">
    <div class="card-header"><h5>1. Game files <small class="ml-2">for map pictures</small></h5></div>
    <div class="card-body">
        <p>Every map file (<code>.pk3</code>) contains the picture the game shows for that map. Tell Echelon which folder your game files are in and it will take the pictures from them, so you do not have to upload each one.</p>

        <div class="field">
            <label for="sm-dirs">Game files folder</label>
            <textarea class="form-control" id="sm-dirs" rows="2" spellcheck="false" placeholder="<?php echo $e($mapcycleFile !== '' ? dirname($mapcycleFile) : '/home/urt/.q3a/q3ut4'); ?>"><?php echo $e(str_replace('|', "\n", $saved_dirs)); ?></textarea>
            <small class="form-hint text-muted">The full path of the folder that holds the <code>.pk3</code> files &mdash; usually named <code>q3ut4</code>. One folder per line (the folder with the downloaded maps and the one with the game's own maps, if they are different). It has to be on the same machine as this website and readable by the web server.</small>
        </div>

        <?php if ($suggest) : ?>
        <div class="mb-3" id="sm-suggest">
            <span class="text-muted mr-2" style="font-size:13px">Found on this server:</span>
            <?php foreach ($suggest as $s) : ?>
                <button type="button" class="mc-chip" data-path="<?php echo $e($s); ?>" style="--c:#0ca678"><?php echo $e($s); ?></button>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="d-flex flex-wrap align-items-center" style="gap:10px">
            <button type="button" class="btn btn-primary" id="sm-save-dirs"><i class="fas fa-save mr-1"></i> Save folder</button>
            <button type="button" class="btn btn-outline-secondary" id="sm-save-scan"><i class="fas fa-magic mr-1"></i> Save and get map pictures</button>
            <span class="text-muted" id="sm-dirs-msg" style="font-size:13px">
                <?php if ($dirs_now) : ?><i class="fas fa-check-circle text-success"></i> <?php echo $pk3_total; ?> map file<?php echo $pk3_total === 1 ? '' : 's'; ?> found.
                <?php else : ?>No folder is set yet.<?php endif; ?>
            </span>
        </div>
        <?php if (!$ext_zip || !$ext_gd) : ?>
            <div class="alert alert-warning mt-3 mb-0">This server's PHP is missing <?php echo implode(' and ', array_filter(array(!$ext_zip ? '<code>zip</code>' : '', !$ext_gd ? '<code>gd</code>' : ''))); ?> (install <?php echo implode(' and ', array_filter(array(!$ext_zip ? 'php-zip' : '', !$ext_gd ? 'php-gd' : ''))); ?>), which map pictures need.</div>
        <?php endif; ?>
    </div>
</section>

<section class="card" id="map-downloads">
    <div class="card-header"><h5>2. Map downloads <small class="ml-2">fetch missing maps into the game files folder</small></h5></div>
    <div class="card-body">
        <p>When a map in Map Config has no <code>.pk3</code> in your game files folder, Echelon can download it from a site you trust. Enter the address of the map file with <code>{map}</code> where the map name goes, one site per line; they are tried in order.</p>

        <div class="field">
            <label for="sm-dl-sources">Download sites</label>
            <textarea class="form-control" id="sm-dl-sources" rows="3" spellcheck="false" placeholder="https://fastdl.example.com/q3ut4/{map}.pk3"><?php echo $e($dl_sources); ?></textarea>
            <small class="form-hint text-muted">For example <code>https://fastdl.example.com/q3ut4/{map}.pk3</code> becomes <code>.../ut4_turnpike.pk3</code>. Use the same address your game server hands out as its download redirect (<code>sv_dlURL</code>) or a map site that links straight to the file. Sites that only serve the game's own client cannot be used.</small>
        </div>
        <div class="row">
            <div class="col-md-4 field">
                <label for="sm-dl-max">Largest file (MB)</label>
                <input type="number" class="form-control" id="sm-dl-max" min="5" max="1000" value="<?php echo (int) ($dl_max ?: 200); ?>">
            </div>
            <div class="col-md-8 field">
                <label class="d-block">&nbsp;</label>
                <label class="mb-0"><input type="checkbox" id="sm-dl-lan" <?php echo $dl_lan ? 'checked' : ''; ?>> Allow addresses on my own network <small class="text-muted">(only if your download site is on this machine or your LAN)</small></label>
            </div>
        </div>
        <div class="d-flex flex-wrap align-items-center" style="gap:10px">
            <button type="button" class="btn btn-primary" id="sm-dl-save"><i class="fas fa-save mr-1"></i> Save download sites</button>
            <span class="input-group input-group-sm" style="width:auto">
                <input type="text" class="form-control" id="sm-dl-testmap" value="ut4_turnpike" spellcheck="false" aria-label="Map name to test with" style="max-width:170px">
                <span class="input-group-append"><button type="button" class="btn btn-outline-secondary" id="sm-dl-test"><i class="fas fa-vial mr-1"></i> Check the first site</button></span>
            </span>
            <span class="text-muted" id="sm-dl-msg" style="font-size:13px"></span>
        </div>
        <?php if (!$ext_curl || !$ext_zip) : ?>
            <div class="alert alert-warning mt-3 mb-0">Downloading needs <?php echo implode(' and ', array_filter(array(!$ext_curl ? '<code>curl</code>' : '', !$ext_zip ? '<code>zip</code>' : ''))); ?> in this server's PHP (install <?php echo implode(' and ', array_filter(array(!$ext_curl ? 'php-curl' : '', !$ext_zip ? 'php-zip' : ''))); ?>).</div>
        <?php endif; ?>
        <?php if ($dirs_now && !$dl_writable) : ?>
            <div class="alert alert-warning mt-3 mb-0">The web server user (<?php echo $e(mapcycleWebUser()); ?>) is not allowed to write to <code><?php echo $e($dirs_now[0]); ?></code>, so maps cannot be saved there. For example: <code>sudo chown <?php echo $e(mapcycleWebUser()); ?> <?php echo $e($dirs_now[0]); ?></code></div>
        <?php elseif (!$dirs_now) : ?>
            <div class="alert alert-info mt-3 mb-0">Set the game files folder in section 1 first: that is where downloaded maps are saved.</div>
        <?php endif; ?>
        <p class="text-muted mt-3 mb-0" style="font-size:12.5px">Echelon only downloads maps listed in Map Config, only over http(s), refuses addresses that point at this computer unless you allow them above, checks that the file really is a map and never replaces a file that is already there. Only download from sites you trust, and respect the map authors' licences.</p>
    </div>
</section>

<section class="card" id="mapcycle-files">
    <div class="card-header"><h5>3. Mapcycle files <small class="ml-2">where Map Config writes the cycle</small></h5></div>
    <div class="card-body">
        <p>Choose the real mapcycle file of your game server for each game type. Echelon keeps a link to it in its own <code>mapcycle</code> folder and reads and writes the real file through that link, so the file stays where the game server expects it. The <em>All game types</em> row is used when you have only one mapcycle.</p>
        <?php if (!$mcf_dir_ok) : ?><div class="alert alert-warning">Echelon cannot create its <code>mapcycle</code> folder. Make the Echelon folder writable for the web server user (<?php echo $e(mapcycleWebUser()); ?>), or create a folder named <code>mapcycle</code> in it yourself and give that user write access.</div><?php endif; ?>
        <?php if (!$can_link) : ?><div class="alert alert-info">This server's PHP cannot make links, so Echelon will remember the file's location instead. It works the same way.</div><?php endif; ?>
        <table class="mcf-table" id="mcf-table"><thead><tr><th>Game type</th><th>Mapcycle file</th><th></th></tr></thead><tbody id="mcf-rows"></tbody></table>
        <div class="mcf-custom" id="mcf-custom">
            <h6 class="mb-1">Add your own game type</h6>
            <p class="text-muted mb-3" style="font-size:13px">Does your server run a mod or a game type that is not in the list, such as Instagib CTF or Gun Game with your own rules? Add it here. It gets its own mapcycle file row above, shows up as a game type in Map Config, and can be ticked on each map.</p>
            <div class="d-flex flex-wrap align-items-end" style="gap:12px">
                <div class="mcf-field"><label for="mcf-new-name">Name</label><input type="text" class="form-control" id="mcf-new-name" maxlength="40" placeholder="Instagib CTF" autocomplete="off"></div>
                <div class="mcf-field"><label for="mcf-new-code">Short code <small class="text-muted">(made from the name)</small></label><input type="text" class="form-control" id="mcf-new-code" maxlength="12" placeholder="instagib_ctf" spellcheck="false" autocomplete="off"></div>
                <button type="button" class="btn btn-primary" id="mcf-add"><i class="fas fa-plus mr-1"></i> Add game type</button>
            </div>
            <div class="mt-2" id="mcf-add-msg" style="font-size:13px" aria-live="polite"></div>
        </div>
        <p class="text-muted mt-3 mb-0" style="font-size:12.5px">The web server user (<code><?php echo $e(mapcycleWebUser()); ?></code>) needs permission to write the file. Echelon keeps the previous text of each file in <code>mapcycle/&lt;type&gt;.previous</code> after every write.</p>
    </div>
</section>

<div class="modal fade mcf-pick" id="mcf-modal" tabindex="-1" role="dialog" aria-labelledby="mcf-title" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header"><h5 class="modal-title" id="mcf-title">Choose the mapcycle file</h5><button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button></div>
            <div class="modal-body">
                <div class="mcf-bar">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="mcf-up" title="Up one folder"><i class="fas fa-level-up-alt"></i></button>
                    <input type="text" class="form-control form-control-sm" id="mcf-path" spellcheck="false" autocomplete="off" aria-label="Folder or file path">
                    <button type="button" class="btn btn-outline-secondary btn-sm" id="mcf-go">Go</button>
                </div>
                <div class="mcf-list" id="mcf-list" role="listbox" aria-label="Folders and mapcycle files"></div>
            </div>
            <div class="modal-footer">
                <span class="mcf-chosen" id="mcf-chosen">No file chosen</span>
                <button type="button" class="btn btn-light" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="mcf-use" disabled><i class="fas fa-link mr-1"></i> Use this file</button>
            </div>
        </div>
    </div>
</div>
<script type="application/json" id="mcf-data"><?php echo json_encode($mcf_slots, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE); ?></script>

<section class="card">
    <div class="card-header"><h5>4. World map <small class="ml-2">player locations</small></h5></div>
    <div class="card-body">
        <p>The World Map uses <a href="https://leafletjs.com" target="_blank" rel="noopener">Leaflet</a> with <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a> maps. Choose where it should look up each player's location:</p>

        <div class="mode-picker mb-3" style="grid-template-columns:repeat(auto-fit,minmax(280px,1fr))">
            <label class="mode-opt" style="--c:#d9480f">
                <input type="radio" name="sm-provider" value="geolite2" <?php echo $provider_pref === 'geolite2' ? 'checked' : ''; ?>>
                <span><strong>MaxMind GeoLite2</strong> <small class="text-muted d-block">City level, free, updated twice a week. Needs a free MaxMind account.</small></span>
            </label>
            <label class="mode-opt" style="--c:#868e96">
                <input type="radio" name="sm-provider" value="legacy" <?php echo $provider_pref === 'legacy' ? 'checked' : ''; ?>>
                <span><strong>Old GeoIP.dat</strong> <small class="text-muted d-block">Country level only. Only works if you already have <code>lib/GeoIP.dat</code> (<?php echo $legacy_present ? 'found' : 'not found'; ?>).</small></span>
            </label>
        </div>

        <div id="sm-geolite" <?php echo $provider_pref === 'geolite2' ? '' : 'hidden'; ?>>
            <div id="sm-geo-status" class="mc-notice" style="margin-bottom:16px">
                <i class="fas fa-database"></i>
                <span id="sm-geo-status-text">
                    <?php if ($mm) : ?><strong>GeoLite2 is installed.</strong> Database built <?php echo $e(gmdate('j M Y', $mm['built'])); ?> (<?php echo round($mm['size'] / 1048576, 1); ?> MB).
                    <?php else : ?><strong>Not set up yet.</strong> Follow the three steps below.<?php endif; ?>
                </span>
            </div>

            <ol class="sm-steps">
                <li>
                    <strong>Create a free MaxMind account.</strong>
                    <span class="text-muted d-block">Sign up at <a href="https://www.maxmind.com/en/geolite2/signup" target="_blank" rel="noopener">maxmind.com/en/geolite2/signup</a> and confirm your email. It is free and takes a couple of minutes.</span>
                </li>
                <li>
                    <strong>Make a license key.</strong>
                    <span class="text-muted d-block">Log in, open <a href="https://www.maxmind.com/en/accounts/current/license-key" target="_blank" rel="noopener">Manage license keys</a> and choose <em>Generate new license key</em>. When it asks whether the key will be used for <em>geoipupdate</em>, answer <em>No</em>. Copy the key right away &mdash; MaxMind only shows it once. Your <strong>Account ID</strong> is the number at the top of the account page.</span>
                </li>
                <li>
                    <strong>Enter them here.</strong>
                    <span class="text-muted d-block">Echelon downloads the database for you (about 60&nbsp;MB) and keeps your details so you can update it later.</span>
                </li>
            </ol>

            <div class="field-row" style="max-width:640px">
                <div class="field">
                    <label for="sm-account">Account ID</label>
                    <input type="text" class="form-control" id="sm-account" inputmode="numeric" autocomplete="off" value="<?php echo $e($account); ?>" placeholder="123456">
                </div>
                <div class="field">
                    <label for="sm-key">License key</label>
                    <input type="password" class="form-control" id="sm-key" autocomplete="new-password" value="<?php echo $key_saved ? '&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;&bull;' : ''; ?>" placeholder="Paste your license key">
                </div>
            </div>
            <div class="d-flex flex-wrap align-items-center" style="gap:10px">
                <button type="button" class="btn btn-primary" id="sm-download"><i class="fas fa-download mr-1"></i> <span id="sm-download-label"><?php echo $mm ? 'Update database' : 'Save and download database'; ?></span></button>
                <button type="button" class="btn btn-outline-danger" id="sm-remove" <?php echo $mm ? '' : 'hidden'; ?>><i class="fas fa-trash-alt mr-1"></i> Remove database</button>
                <span class="text-muted" id="sm-geo-msg" style="font-size:13px"></span>
            </div>
            <?php if (!$lib_writable) : ?><div class="alert alert-warning mt-3 mb-0">The folder <code>lib/</code> is not writable by the web server user, so the database cannot be saved there. Fix that first.</div><?php endif; ?>

            <div class="sm-auto mt-4">
                <label class="sm-check"><input type="checkbox" id="sm-auto" <?php echo $auto_on ? 'checked' : ''; ?>> <span><strong>Keep the database up to date automatically</strong></span></label>
                <p class="text-muted mb-2" style="font-size:13px">MaxMind publishes a new database every Tuesday and Friday. With this on, when an administrator opens the World Map and the database is more than 3 days old, Echelon downloads the new one in the background (a failed try is not repeated for 12 hours). Saved with <em>Use this for the World Map</em> below.</p>
                <p class="text-muted mb-1" style="font-size:13px" id="sm-updated">
                    <?php if ($last_upd) : ?>Last updated <?php echo $e(gmdate('j M Y', $last_upd)); ?>.<?php else : ?>Not downloaded yet.<?php endif; ?>
                    <?php if ($last_err !== '') : ?><span class="text-danger">Last automatic update failed: <?php echo $e($last_err); ?></span><?php endif; ?>
                </p>
                <details class="text-muted" style="font-size:13px">
                    <summary>Prefer a schedule (cron / Windows Task Scheduler) over the page trigger?</summary>
                    <p class="mt-2 mb-1">Run this twice a week (it only downloads when the database is more than 3 days old; add <code>--force</code> to always download):</p>
                    <pre class="sm-cmd"><?php echo $e($cron_cmd); ?></pre>
                    <p class="mb-0">Linux example (Wednesday and Saturday at 04:17, as the web server's user): <code>17 4 * * 3,6 www-data <?php echo $e($cron_cmd); ?></code></p>
                </details>
            </div>
            <p class="text-muted mt-3 mb-0" style="font-size:12.5px">GeoLite2 is free for use under MaxMind's <a href="https://www.maxmind.com/en/geolite2/eula" target="_blank" rel="noopener">GeoLite2 End User License Agreement</a>; the World Map shows the required attribution.</p>
        </div>

        <div id="sm-legacy" <?php echo $provider_pref === 'legacy' ? '' : 'hidden'; ?>>
            <p class="text-muted mb-0">MaxMind no longer offers the old <code>GeoIP.dat</code> file for download, so this only works if you already have it in <code>lib/GeoIP.dat</code>. GeoLite2 is the recommended choice.</p>
        </div>

        <div class="mt-3 d-flex align-items-center" style="gap:10px">
            <button type="button" class="btn btn-outline-secondary" id="sm-save-provider"><i class="fas fa-check mr-1"></i> Use this for the World Map</button>
            <a href="map.php" class="btn btn-link">Open the World Map</a>
        </div>
    </div>
</section>

<section class="card">
    <div class="card-header"><h5>5. Map style <small class="ml-2">how the World Map looks</small></h5></div>
    <div class="card-body">
        <p>Pick the style new visitors see. The pictures below are loaded live from each provider, so your browser needs internet access to see them.</p>
        <div class="sm-styles" id="sm-styles">
            <?php foreach ($styles as $sid => $st) : ?>
            <label class="sm-style">
                <input type="radio" name="sm-style" value="<?php echo $e($sid); ?>" <?php echo $sid === $style_now ? 'checked' : ''; ?>>
                <span class="sm-thumb"><img loading="lazy" alt="" src="<?php echo $e($thumb($st)); ?>" onerror="this.style.visibility='hidden'"></span>
                <span class="sm-sname"><strong><?php echo $e($st['name']); ?></strong><small class="text-muted d-block"><?php echo $e($st['note']); ?></small></span>
            </label>
            <?php endforeach; ?>
            <label class="sm-style sm-style-custom">
                <input type="radio" name="sm-style" value="custom" <?php echo $style_now === 'custom' ? 'checked' : ''; ?>>
                <span class="sm-thumb sm-thumb-custom"><i class="fas fa-sliders-h"></i></span>
                <span class="sm-sname"><strong>Custom</strong><small class="text-muted d-block">Your own tile server or provider key</small></span>
            </label>
        </div>

        <div id="sm-custom" class="mt-3" <?php echo $style_now === 'custom' || $custom_url !== '' ? '' : 'hidden'; ?> style="max-width:760px">
            <div class="field">
                <label for="sm-custom-url">Custom tile address</label>
                <input type="text" class="form-control" id="sm-custom-url" spellcheck="false" autocomplete="off" value="<?php echo $e($custom_url); ?>" placeholder="https://tiles.example.com/{z}/{x}/{y}.png?key=YOUR_KEY">
                <small class="form-hint text-muted">Any XYZ raster tile source (MapTiler, Thunderforest, Stadia, your own server&hellip;). Use <code>{z}</code>, <code>{x}</code>, <code>{y}</code> (and <code>{s}</code> / <code>{r}</code> if the provider asks for them). The key becomes visible to anyone who opens the World Map, so use a key restricted to your website's address.</small>
            </div>
            <div class="field">
                <label for="sm-custom-attr">Credit line</label>
                <input type="text" class="form-control" id="sm-custom-attr" maxlength="200" value="<?php echo $e($custom_attr); ?>" placeholder="&copy; OpenStreetMap contributors, tiles by Example Maps">
                <small class="form-hint text-muted">Shown in the corner of the map. Most providers require one &mdash; check their terms.</small>
            </div>
        </div>

        <label class="sm-check mt-3"><input type="checkbox" id="sm-switcher" <?php echo $switcher ? 'checked' : ''; ?>> <span>Let visitors switch styles on the map (their choice is remembered in their browser)</span></label>

        <div class="mt-3 d-flex flex-wrap align-items-center" style="gap:10px">
            <button type="button" class="btn btn-primary" id="sm-save-style"><i class="fas fa-save mr-1"></i> Save map style</button>
            <span class="text-muted" id="sm-style-msg" style="font-size:13px"></span>
        </div>
        <p class="text-muted mt-3 mb-0" style="font-size:12.5px">Echelon only loads map tiles while someone has the World Map open. Each provider has its own fair-use terms (for example OpenStreetMap's tile usage policy); for a very busy site, use your own provider key through <em>Custom</em>.</p>
    </div>
</section>

<?php require 'inc/footer.php'; ?>
