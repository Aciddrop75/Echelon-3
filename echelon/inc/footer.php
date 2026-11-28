<?php
## pagination for list pages (only when the page asked for it and there is data)
if(!empty($pagination) && empty($db->error)) :
    if (!isset($no_data)) {
        $no_data = false;
    }
    if(!$no_data) : ?>
        <p class="text-center text-muted my-3" style="font-size:13px"><?php recordNumber($start_row, $limit_rows, $total_rows); ?></p>
        <?php
        if($total_rows > $limit_rows) { // if everything fits on one page there is nothing to page through
            pager($page_no, $total_pages, $query_string_page ?? '');
        }
    endif;
endif;
?>
</main><!-- close .content -->

<footer class="app-footer">
    <span>Echelon <?php echo htmlspecialchars((string) ECH_VERSION, ENT_QUOTES); ?> &middot; based on the work of WatchMiltan, Eire32 &amp; Big Brother Bot</span>
    <span><a href="https://github.com/dkman123/Echelon-2" rel="noopener">Echelon on GitHub</a></span>
</footer>

</div><!-- close .main -->
</div><!-- close .app -->

<script>window.ECH = { path: <?php echo json_encode(PATH); ?>, csrf: <?php echo json_encode(csrfToken()); ?> };</script>
<script src="<?php echo PATH; ?>js/app.js?v=<?php echo rawurlencode((string) ECH_VERSION); ?>"></script>

<!-- page specific js -->
<?php foreach ((array) ($page_js ?? array()) as $pjs) : ?>
	<script src="<?php echo PATH . htmlspecialchars($pjs, ENT_QUOTES); ?>?v=<?php echo rawurlencode((string) ECH_VERSION); ?>"></script>
<?php endforeach; ?>

<?php if(isMe()) { ?>
	<script src="<?php echo PATH; ?>js/me.js" charset="utf-8"></script>
<?php } ?>

<?php if(isCD()) : ?>
	<script src="<?php echo PATH; ?>js/cd.js" charset="utf-8"></script>
<?php endif; ?>

<?php
    ## plugin specific js ##
    if(!$no_plugins_active)
        $plugins->getJS();
?>

</body>
</html>
