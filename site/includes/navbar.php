<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<nav class="navbar">
    <a href="index.php" class="navbar-logo">
        MyWebsite
    </a>
    <ul class="navbar-links">
        <li>
            <a href="cooking.php"
            class="<?= $current_page === 'cooking' ? 'active' : '' ?>">
            Cooking
        </a>
        </li>
        <li>
            <a href="alchemy.php"
            class="<?= $current_page === 'alchemy' ? 'active' : '' ?>">
            Alchemy
        </a>
        </li>
        <li>
            <a href="processing.php"
            class="<?= $current_page === 'processing' ? 'active' : '' ?>">
            processing
        </a>
        </li>
        <li>
            <a href="items.php"
            class="<?= $current_page === 'items' ? 'active' : '' ?>">
            Items
        </a>
        </li>
    </ul>
    <div class="navbar-search">
        <span class="navbar-search-icon">🔍</span>
        <input
            type="text"
            id="global-search"
            placeholder="Search recipes, items..."
            autocomplete="off"
            />
    </div>
</nav>