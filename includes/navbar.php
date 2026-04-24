<?php
$current_page = basename($_SERVER['PHP_SELF'], '.php');
?>

<nav class="navbar">
    <a href="/bdo-site/index.php" class="navbar-logo">
        MyWebsite
    </a>
    <ul class="navbar-links">
        <li>
            <a href="/bdo-site/cooking.php"
            class="<?= $current_page === 'cooking' ? 'active' : '' ?>">
            Cooking
        </a>
        </li>
        <li>
            <a href="/bdo-site/alchemy.php"
            class="<?= $current_page === 'alchemy' ? 'active' : '' ?>">
            Alchemy
        </a>
        </li>
        <li>
            <a href="/bdo-site/processing.php"
            class="<?= $current_page === 'processing' ? 'active' : '' ?>">
            processing
        </a>
        </li>
        <li>
            <a href="/bdo-site/items.php"
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