<?php
    $activePage = $activePage ?? '';  // avoid notice if some page forgets
?>
<header class="topbar">
    <div class="topbar-left">
        <h1 class="page-title">
            <?php
            $titleMap = [
                'dashboard.php' => 'Dashboard',
                'strategies.php' => 'Strategies',
                'entries.php' => 'Entries',
                'capital.php' => 'Capital',
                'settings.php' => 'Settings'
            ];
            $current = basename($_SERVER['PHP_SELF']);
            echo isset($titleMap[$current]) ? $titleMap[$current] : 'Trading Dashboard';
            ?>
        </h1>
    </div>
    <div class="topbar-right">
        <span class="user-label">
            Logged in as: <?php echo htmlspecialchars(get_current_name()); ?>
        </span>
        <a href="logout.php" class="btn btn-small btn-danger">Logout</a>
    </div>
</header>
