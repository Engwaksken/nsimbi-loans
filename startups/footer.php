    </main>
</div>

<script>
function toggleStartupSidebar(forceState) {
    var sidebar = document.getElementById('stpSidebar');
    var overlay = document.getElementById('stpSidebarOverlay');
    if (!sidebar || !overlay) return;

    var shouldShow = typeof forceState === 'boolean'
        ? forceState
        : !sidebar.classList.contains('show');

    sidebar.classList.toggle('show', shouldShow);
    overlay.classList.toggle('show', shouldShow);
}
</script>
<script src="../assets/js/bootstrap.bundle.min.js"></script>
</body>
</html>