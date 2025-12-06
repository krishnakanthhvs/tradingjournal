<?php
$current = basename($_SERVER['PHP_SELF']);
if (!isset($activePage)) {
    $activePage = '';
}
?>

<aside class="sidebar">
    <div class="sidebar-brand">
        <img src="assets/img/logo_without_bg.png" alt="Trading Journal" class="sidebar-logo">
    </div>

    <nav class="sidebar-nav">
        <a href="dashboard.php" class="<?= $current === 'dashboard.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-house"></i>
            <span>Dashboard</span>
        </a>

        <a href="strategies.php" class="<?= $current === 'strategies.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-lightbulb"></i>
            <span>Strategies</span>
        </a>

        <a href="entries.php" class="<?= $current === 'entries.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-pen-to-square"></i>
            <span>Entries</span>
        </a>

        <a href="capital.php" class="<?= $current === 'capital.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-coins"></i>
            <span>Capital</span>
        </a>

        <a href="portfolio.php" class="<?= $current === 'portfolio.php' ? 'active' : ''; ?>">
            <i class="fa fa-briefcase"></i>
            <span>Portfolio</span>
        </a>

        <a href="settings.php" class="<?= $current === 'settings.php' ? 'active' : ''; ?>">
            <i class="fa-solid fa-gear"></i>
            <span>Settings</span>
        </a>
    </nav>
</aside>
