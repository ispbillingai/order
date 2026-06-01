    </main>
    
    <footer class="main-footer">
        <p>&copy; <?= date('Y') ?> RestoPOS - <?= te('footer_tagline') ?></p>
    </footer>
    
    <!-- Toast notifications container -->
    <div id="toastContainer" class="toast-container"></div>
    
    <script src="/assets/js/app.js"></script>
    <?php if (isset($extraJs)): ?>
        <?php foreach ((array)$extraJs as $js): ?>
            <script src="<?= $js ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>
</body>
</html>
