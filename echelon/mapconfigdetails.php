<?php
$page = "mapconfigdetails";
$page_title = "Map Settings";
$auth_name = 'mapconfig';
$b3_conn = true; // this page needs to connect to the B3 database
$pagination = false;
$query_normal = false;
$page_js = array('js/mapconfig-edit.js', 'js/gear-calc.js');
require 'inc.php';

$is_new = isset($_GET['new']);
$modes_all = mapGameModes();

// values re-shown after a failed save
$old = $_SESSION['mc_old'] ?? null;
unset($_SESSION['mc_old']);

if ($is_new) {
    $map = array('id' => 0, 'mapname' => '', 'capturelimit' => 8, 'g_suddendeath' => 0, 'g_gear' => '0', 'g_gravity' => 800, 'g_friendlyfire' => 0,
        'startmessage' => '', 'skiprandom' => 0, 'datelastadd' => '2000-01-01', 'timelimit' => 20, 'gamemodes' => '', 'bot' => 0);
    $page_title = 'Add Map';
    // coming from a map found in the game files: fill in its name and game types
    $pn = $_GET['mapname'] ?? '';
    if (is_string($pn) && mapNameValid($pn)) { $map['mapname'] = $pn; }
    $pm = $_GET['modes'] ?? '';
    if (is_string($pm) && preg_match('/^[a-z0-9_,]{0,60}$/', $pm)) { $map['gamemodes'] = implode(',', array_slice(mapParseModes($pm), 0, 12)); }
} else {
    $mid = $_GET['id'] ?? '';
    if (!is_string($mid) || !ctype_digit($mid) || (int) $mid < 1) {
        set_error('The map id that you have supplied is invalid. Please select a map.');
        send(PATH . 'mapconfig.php');
        exit;
    }
    $map = $db->getMapconfig((int) $mid);
    if (!$map) {
        set_error('That map no longer exists.');
        send(PATH . 'mapconfig.php');
        exit;
    }
    $page_title = $map['mapname'];
}

if (is_array($old)) { // a failed save: show what the admin typed
    $map = array_merge($map, array_intersect_key($old, $map));
}

$sel_modes = mapParseModes($map['gamemodes']);
$other_modes = array_diff($sel_modes, array_keys($modes_all));
$img = $is_new ? null : mapImageUrl($map['mapname']);
$pretty = mapPrettyName($map['mapname']);
$csrf = csrfToken();
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES); };

require 'inc/header.php';
if (!$db->error) :
?>

<div class="page-head">
    <div>
        <h1><?php echo $is_new ? 'Add map' : $e($map['mapname']); ?></h1>
        <p><a href="mapconfig.php"><i class="fas fa-arrow-left mr-1"></i>All maps</a><?php if (!$is_new) : ?> &middot; id <?php echo (int) $map['id']; ?><?php endif; ?></p>
    </div>
</div>

<form method="post" action="actions/b3/mapconfig-edit.php" id="map-form" autocomplete="off" novalidate>
<input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>">
<input type="hidden" name="t" value="<?php echo $is_new ? 'add' : 'edit'; ?>">
<input type="hidden" name="id" value="<?php echo (int) $map['id']; ?>">

<div class="mc-editor">

    <!-- picture -->
    <aside class="card mc-hero">
        <div class="mc-thumb" id="mc-hero-thumb" style="--h:<?php echo mapHue($map['mapname']); ?>">
            <?php if ($img) : ?>
                <img src="<?php echo $e($img); ?>" alt="">
            <?php else : ?>
                <div class="mc-ph"><div><b><?php echo $is_new ? 'New map' : $e($pretty); ?></b><i>No picture</i></div></div>
            <?php endif; ?>
        </div>
        <?php if ($is_new) : ?>
            <div class="card-body text-muted" style="font-size:13px"><i class="far fa-image mr-1"></i> Save the map first, then you can add a picture to its tile.</div>
        <?php else : ?>
            <label class="mc-drop" id="mc-drop" for="mc-file" tabindex="0">
                <i class="fas fa-upload"></i>
                <span id="mc-drop-text">Drop a picture here or click to choose<br><small>JPEG, PNG, GIF or WebP, up to 8 MB. Shown at 16:9.</small></span>
                <input type="file" id="mc-file" accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <div class="mc-progress" id="mc-progress" hidden><div></div></div>
            <div class="mc-hero-actions">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="mc-img-scan" title="Look for this map's level shot in the server's .pk3 files"><i class="fas fa-magic mr-1"></i> From map files</button>
                <label class="btn btn-outline-secondary btn-sm mb-0" for="mc-pk3-file" title="Take the level shot from a .pk3 you choose"><i class="fas fa-file-archive mr-1"></i> From .pk3&hellip;</label>
                <input type="file" id="mc-pk3-file" accept=".pk3,.zip" hidden>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="mc-img-remove" <?php echo $img ? '' : 'hidden'; ?>><i class="fas fa-trash-alt mr-1"></i> Remove picture</button>
            </div>
            <div class="px-3 pb-3" id="mc-dl-one" hidden>
                <div class="alert alert-warning py-2 mb-2" style="font-size:13px"><i class="fas fa-exclamation-triangle mr-1"></i> <span id="mc-dl-one-text">This map is not in your game files folder.</span></div>
                <button type="button" class="btn btn-primary btn-sm" id="mc-dl-one-go"><i class="fas fa-download mr-1"></i> Download this map</button>
            </div>
            <div class="px-3 pb-3 text-muted" style="font-size:12px">
                The picture is stored as <code>images/maps/<?php echo $e(mapSlug($map['mapname'])); ?>.webp</code>. You can also copy a file with that name there yourself.
            </div>
        <?php endif; ?>
    </aside>

    <!-- settings -->
    <div>
        <section class="card">
            <div class="card-header"><h5>General</h5></div>
            <div class="card-body">
                <div class="field">
                    <label for="mapname">Map name</label>
                    <input type="text" class="form-control" id="mapname" name="mapname" maxlength="50" required value="<?php echo $e($map['mapname']); ?>" placeholder="ut4_turnpike">
                    <small class="form-hint text-muted">Exactly as the game server knows it. Letters, numbers, _ - and .</small>
                </div>
                <div class="field">
                    <label for="startmessage">Start message</label>
                    <input type="text" class="form-control" id="startmessage" name="startmessage" maxlength="75" value="<?php echo $e($map['startmessage']); ?>" placeholder="Shown to players when the map loads (optional)">
                    <small class="form-hint text-muted"><span id="msg-count"><?php echo mb_strlen($map['startmessage']); ?></span>/75</small>
                </div>
                <div class="field mb-0">
                    <label class="d-block">Game types this map is played in</label>
                    <div class="mb-2"><button type="button" class="btn btn-outline-secondary btn-sm" id="mc-arena" title="Read the game types from the map's .arena file in your game files"><i class="fas fa-magic mr-1"></i> Read from .arena file</button> <small class="text-muted ml-2" id="mc-arena-msg"></small></div>
                    <div class="mode-picker">
                        <?php foreach ($modes_all as $code => $label) : ?>
                            <label class="mode-opt" style="--c:<?php echo $e(mapModeColor($code)); ?>">
                                <input type="checkbox" name="gamemode[]" value="<?php echo $e($code); ?>" <?php echo in_array($code, $sel_modes, true) ? 'checked' : ''; ?>>
                                <?php echo $e($label); ?> <b><?php echo $e($code); ?></b>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="mt-3">
                        <label for="gamemodes_other" class="mb-1">Other game types <small class="text-muted">(comma separated codes, only if your B3 uses types not listed above)</small></label>
                        <input type="text" class="form-control" id="gamemodes_other" name="gamemodes_other" value="<?php echo $e(implode(',', $other_modes)); ?>">
                    </div>
                    <small class="form-hint text-muted">The list is stored in a 50 character field: <span id="modes-len">0</span>/50</small>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h5>Rules</h5></div>
            <div class="card-body">
                <div class="field-row">
                    <div class="field">
                        <label for="timelimit">Time limit <small class="text-muted">(minutes)</small></label>
                        <input type="number" class="form-control" id="timelimit" name="timelimit" min="0" max="999" value="<?php echo (int) $map['timelimit']; ?>">
                    </div>
                    <div class="field">
                        <label for="capturelimit">Capture limit</label>
                        <input type="number" class="form-control" id="capturelimit" name="capturelimit" min="0" max="99" value="<?php echo (int) $map['capturelimit']; ?>">
                    </div>
                    <div class="field">
                        <label for="g_gravity">Gravity</label>
                        <input type="number" class="form-control" id="g_gravity" name="g_gravity" min="0" max="9999" value="<?php echo (int) $map['g_gravity']; ?>">
                    </div>
                    <div class="field">
                        <label for="g_friendlyfire">Friendly fire</label>
                        <select class="form-control" id="g_friendlyfire" name="g_friendlyfire">
                            <?php foreach (array(0 => 'Off', 1 => 'On', 2 => 'Reflect damage', 3 => 'Shared damage') as $v => $l) : ?>
                                <option value="<?php echo $v; ?>" <?php echo (int) $map['g_friendlyfire'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="field">
                    <label for="g_gear">Gear</label>
                    <input type="text" class="form-control" id="g_gear" name="g_gear" maxlength="100" value="<?php echo $e($map['g_gear']); ?>">
                    <small class="form-hint text-muted"><code>0</code> allows everything. Otherwise the letters of the items to disallow, as in the game's <code>g_gear</code> setting. Use the calculator below instead of typing the letters.</small>
                </div>
                <div class="gear-calc" id="gear-calc" data-target="g_gear">
                    <div class="gear-head">
                        <strong>Gear calculator</strong>
                        <span class="gear-count text-muted" id="gear-count"></span>
                        <span class="gear-presets">
                            <button type="button" class="btn btn-light btn-sm" data-preset="all">Allow everything</button>
                            <button type="button" class="btn btn-light btn-sm" data-preset="pistols" title="No primary or secondary weapons, no grenades">Pistols only</button>
                            <button type="button" class="btn btn-light btn-sm" data-preset="snipers" title="Sidearms and sniper rifles only, no grenades">Snipers only</button>
                        </span>
                    </div>
                    <p class="gear-help">Tick what players <em>may</em> use on this map. The <strong>Gear</strong> value above is filled in for you.</p>
                    <?php foreach (mapGearItems() as $gname => $items) : ?>
                    <div class="gear-group">
                        <div class="gear-gname"><?php echo $e($gname); ?></div>
                        <div class="gear-items">
                            <?php foreach ($items as $letter => $iname) : ?>
                            <label class="gear-pill"><input type="checkbox" data-letter="<?php echo $e($letter); ?>" data-group="<?php echo $e($gname); ?>" data-name="<?php echo $e($iname); ?>" checked><span><?php echo $e($iname); ?><code><?php echo $e($letter); ?></code></span></label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <div class="gear-extra text-muted" id="gear-extra" hidden></div>
                </div>

                <div class="toggle-row">
                    <div class="t"><strong>Sudden death</strong><small>Play on after the time limit when the score is tied.</small></div>
                    <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="g_suddendeath" name="g_suddendeath" value="1" <?php echo (int) $map['g_suddendeath'] ? 'checked' : ''; ?>><label class="custom-control-label" for="g_suddendeath"></label></div>
                </div>
                <div class="toggle-row">
                    <div class="t"><strong>Bots</strong><small>The map has bot support.</small></div>
                    <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="bot" name="bot" value="1" <?php echo (int) $map['bot'] ? 'checked' : ''; ?>><label class="custom-control-label" for="bot"></label></div>
                </div>
                <div class="toggle-row">
                    <div class="t"><strong>Skip in random picks</strong><small>Never choose this map when the next map is picked at random.</small></div>
                    <div class="custom-control custom-switch"><input type="checkbox" class="custom-control-input" id="skiprandom" name="skiprandom" value="1" <?php echo (int) $map['skiprandom'] ? 'checked' : ''; ?>><label class="custom-control-label" for="skiprandom"></label></div>
                </div>
            </div>
        </section>

        <section class="card">
            <div class="card-header"><h5>History</h5></div>
            <div class="card-body">
                <div class="field mb-0" style="max-width:260px">
                    <label for="datelastadd">Last added to the mapcycle</label>
                    <input type="date" class="form-control" id="datelastadd" name="datelastadd" value="<?php echo $e($map['datelastadd']); ?>">
                    <small class="form-hint text-muted">Updated automatically when you add the map from the board. 2000-01-01 means never.</small>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="editor-actions">
    <button type="submit" class="btn btn-primary"><i class="fas fa-save mr-1"></i> <?php echo $is_new ? 'Add map' : 'Save changes'; ?></button>
    <a class="btn btn-outline-secondary" href="mapconfig.php">Cancel</a>
    <span class="sp"></span>
    <?php if (!$is_new) : ?>
        <button type="submit" class="btn btn-outline-secondary" form="map-copy"><i class="far fa-copy mr-1"></i> Duplicate</button>
        <button type="submit" class="btn btn-outline-danger" form="map-delete" data-confirm="Delete <?php echo $e($map['mapname']); ?>? This cannot be undone."><i class="fas fa-trash-alt mr-1"></i> Delete</button>
    <?php endif; ?>
</div>
</form>

<?php if (!$is_new) : ?>
<form method="post" action="actions/b3/mapconfig-edit.php" id="map-copy">
    <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>"><input type="hidden" name="t" value="copy"><input type="hidden" name="id" value="<?php echo (int) $map['id']; ?>">
</form>
<form method="post" action="actions/b3/mapconfig-edit.php" id="map-delete" data-confirm="Delete <?php echo $e($map['mapname']); ?>? This cannot be undone.">
    <input type="hidden" name="csrf" value="<?php echo $e($csrf); ?>"><input type="hidden" name="t" value="del"><input type="hidden" name="id" value="<?php echo (int) $map['id']; ?>">
</form>
<script>window.MAP_EDIT = { id: <?php echo (int) $map['id']; ?>, name: <?php echo json_encode((string) $map['mapname'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?> };</script>
<?php endif; ?>

<?php
endif; // db error
require 'inc/footer.php';
