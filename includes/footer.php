<?php
// ============================================================
// includes/footer.php — Global Page Footer
// Include at the BOTTOM of every authenticated page
// ============================================================
?>
        </main>
        <!-- ── End main-content ── -->

    </div>
    <!-- ── End main-area ── -->

</div>
<!-- ── End app-wrapper ── -->

<!-- ── Core JavaScript ── -->
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>

<!-- Page-specific JS injected via $extraJs -->
<?php if (!empty($extraJs)): ?>
    <?php foreach ((array)$extraJs as $js): ?>
        <script src="<?= e($js) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<!-- Inline page JS -->
<?php if (!empty($inlineJs)): ?>
    <script><?= $inlineJs ?></script>
<?php endif; ?>

</body>
</html>
