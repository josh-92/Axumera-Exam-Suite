<?php
/** @var array $_SESSION expected to contain admin_username */
$current = basename($_SERVER['SCRIPT_NAME']);
?>
<header class="admin-header">
    <div class="header-left">
        <img src="assets/img/logo.png" alt="" class="header-logo" onerror="this.style.display='none';">
        <div class="header-title"><?php echo htmlspecialchars(config('app.name')); ?></div>
    </div>
    <nav class="admin-nav">
        <a href="adminpanel.php" class="<?php echo $current === 'adminpanel.php' ? 'active' : ''; ?>">Exams</a>
        <a href="analytics.php" class="<?php echo $current === 'analytics.php' ? 'active' : ''; ?>">Analytics</a>
        <a href="license.php" class="<?php echo $current === 'license.php' ? 'active' : ''; ?>">License</a>
    </nav>
    <div class="profile-container" onclick="toggleDropdown(event)">
        <div class="profile-icon"><?php echo htmlspecialchars(strtoupper(substr((string) ($_SESSION['admin_username'] ?? 'A'), 0, 1))); ?></div>
        <div class="profile-dropdown" id="profileMenu">
            <a href="changepassword.php">🔒 Change Password</a>
            <a href="logout.php" style="color: var(--color-danger);">🚪 Logout</a>
        </div>
    </div>
</header>
<script>
    function toggleDropdown(e) {
        e.stopPropagation();
        const menu = document.getElementById("profileMenu");
        menu.style.display = menu.style.display === "block" ? "none" : "block";
    }
    window.addEventListener('click', () => { const m = document.getElementById("profileMenu"); if (m) m.style.display = "none"; });
</script>
