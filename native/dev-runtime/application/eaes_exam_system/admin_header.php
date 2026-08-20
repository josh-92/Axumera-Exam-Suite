<?php
/*   __________________________________________________
    |  Obfuscated by YAK Pro - Php Obfuscator  3.0.0   |
    |              on 2026-08-01 22:27:03              |
    |    GitHub: https://github.com/pk-fr/yakpro-po    |
    |__________________________________________________|
*/
 $J_Lw8 = basename($_SERVER['SCRIPT_NAME']); ?>
<header class="admin-header">
    <div class="header-left">
        <img src="assets/img/logo.png" alt="" class="header-logo" onerror="this.style.display='none';">
        <div class="header-title"><?php  echo htmlspecialchars(B_k5t('app.name')); ?></div>
    </div>
    <nav class="admin-nav">
        <a href="adminpanel.php" class="<?php  echo $J_Lw8 === 'adminpanel.php' ? 'active' : ''; ?>">Exams</a>
        <a href="admin_question_bank.php" class="<?php  echo $J_Lw8 === 'admin_question_bank.php' ? 'active' : ''; ?>">Question Bank</a>
        <a href="analytics.php" class="<?php  echo $J_Lw8 === 'analytics.php' ? 'active' : ''; ?>">Analytics</a>
        <a href="license.php" class="<?php  echo $J_Lw8 === 'license.php' ? 'active' : ''; ?>">License</a>
    </nav>
    <div class="profile-container" onclick="toggleDropdown(event)">
        <div class="profile-icon"><?php  echo htmlspecialchars(strtoupper(substr((string) ($_SESSION['admin_username'] ?? 'A'), 0, 1))); ?></div>
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
