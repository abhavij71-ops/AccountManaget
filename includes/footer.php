        </main>
    </div>
</div>

<script src="<?= e(appUrl('assets/js/bootstrap.bundle.min.js')) ?>"></script>
<script>
document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) {
        e.preventDefault();
    }
});
</script>
</body>
</html>
